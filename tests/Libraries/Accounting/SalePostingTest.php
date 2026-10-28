<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\InterBranchBalances;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PairBalance;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Accounting\SalePosting;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ParityFixture;
use Tests\Support\RegisterFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\WorkerRequestTrait;

/**
 * The cycle of a sale through the books, driven through the real sale model: what is posted when a
 * sale is completed, delivered, paid, returned and cancelled, and that a refusal of the books
 * leaves nothing of the sale behind.
 */
class SalePostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use RegisterFixtureTrait;
    use SaleAccountingFixtureTrait;
    use WorkerRequestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------
    // The invoice and the cost of the goods, with the sale and in one transaction
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_PostsTheInvoiceAndTheCostOfTheGoodsAsTwoJournalsOfTheSale(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertGreaterThan(0, $saleId);

        $revenue = $this->standing($saleId, 'revenue');
        $cost    = $this->standing($saleId, 'cogs');

        $this->assertCount(1, $revenue);
        $this->assertSame(
            [$this->customerAccount() . ':816.50:0.00', $this->accountId('sales_revenue') . ':0.00:710.00', $this->accountId('tax_output') . ':0.00:106.50'],
            $revenue[0]['lines'],
        );
        $this->assertCount(1, $cost);
        $this->assertSame([$this->accountId('cogs') . ':400.00:0.00', $this->stockAccount() . ':0.00:400.00'], $cost[0]['lines']);

        $this->assertSame('sale', $revenue[0]['source']);
        $this->assertSame('sale', $cost[0]['source']);
        $this->assertSame(date('Y-m-d'), $revenue[0]['date']);
        $this->assertSame($revenue[0]['date'], $cost[0]['date']);
        $this->assertSame(1, $revenue[0]['revision']);
        $this->assertCount(2, $this->journals($saleId));
    }

    public function testSaveValue_PostsTheJournalsInTheBranchOfTheSale(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $branches = array_unique(array_column($this->accountingDb()->table('accounting_journals')->select('branch_id')->whereIn('journal_id', array_column($this->journals($saleId), 'id'))->get()->getResultArray(), 'branch_id'));

        $this->assertSame([(string) $this->saleRow($saleId)->branch_id], array_values($branches));
    }

    public function testSaveValue_PostsNoCostForServicesAndAnInvoiceAloneForThem(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '3', '100')]);

        $this->assertCount(1, $this->journals($saleId));
        $this->assertSame('revenue', $this->journals($saleId)[0]['entry']);
    }

    public function testSaveValue_PostsNothingWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $cycle   = $this->referenceInvoice();
        $before  = [$this->countJournals(), $this->countRows('accounting_audit_log'), $this->countRows('accounting_posting_failures')];
        $saleId  = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')]);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame($before, [$this->countJournals(), $this->countRows('accounting_audit_log'), $this->countRows('accounting_posting_failures')]);
    }

    public function testSaveValue_PostsNothingForASaleDatedBeforeAccountingStarts(): void
    {
        $this->setConfigValue('accounting_start_date', date('Y-m-d', strtotime('+1 day')));

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([], $this->journals($saleId));
    }

    public function testSaveValue_ACostThatCannotBePostedUndoesTheInvoiceTheSaleThePaymentsAndTheStock(): void
    {
        $cycle = $this->referenceInvoice();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->stockAccount())->update(['active' => 0]);

        $sales    = $this->countSales();
        $journals = $this->countJournals();
        $payments = $this->countRows('sales_payments');
        $lines    = $this->countRows('sales_items');
        $stock    = $this->stock($cycle['cream']);

        $written = [];
        $listen  = static function (Query $query) use (&$written): void {
            if (str_contains($query->getQuery(), 'INSERT INTO `ospos_accounting_journals`')) {
                $written[] = $query->getQuery();
            }
        };
        Events::on('DBQuery', $listen);

        try {
            $result = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')]);
        } finally {
            Events::removeListener('DBQuery', $listen);
        }

        $this->assertNotSame([], $written, 'The invoice journal must have been written before the cost failed, or the rollback proves nothing.');
        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame($payments, $this->countRows('sales_payments'));
        $this->assertSame($lines, $this->countRows('sales_items'));
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame(PostingError::AccountNotPostable, $this->failure()->error);
    }

    public function testSaveValue_WritesTheReasonInTheFailureLogAfterTheRollback(): void
    {
        $cycle = $this->referenceInvoice();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->stockAccount())->update(['active' => 0]);

        $this->save($cycle['items'], $cycle['taxes']);

        $logged = $this->accountingDb()->table('accounting_posting_failures')->get()->getResult();

        $this->assertCount(1, $logged);
        $this->assertSame('sale', $logged[0]->source_type);
        $this->assertSame('account_not_postable', $logged[0]->error_code);
        $this->assertSame((string) $this->employeeId, (string) $logged[0]->employee_id);
    }

    public function testSaveValue_AGiftCardPaymentIsGivenBackWhenTheBooksRefuseTheSale(): void
    {
        $db = $this->accountingDb();
        $db->table('giftcards')->insert(['giftcard_number' => 7771234, 'value' => 500, 'deleted' => 0, 'person_id' => null]);

        $cycle = $this->referenceInvoice();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->stockAccount())->update(['active' => 0]);

        $result = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.giftcard') . ':7771234', '100.00')]);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertEqualsWithDelta(500.0, (float) $db->table('giftcards')->where('giftcard_number', 7771234)->get()->getRow()->value, 0.001);

        $db->table('giftcards')->where('giftcard_number', 7771234)->delete();
    }

    public function testSaveValue_RefusesASaleWhenARoutingRowIsSavedWithATypePostingDoesNotApply(): void
    {
        $cycle = $this->referenceInvoice();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::Customers->value)->update(['routing_type' => RoutingType::MainPerEntity->value]);

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes']));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::RoutingTypeUnsupported, $this->failure()->error);
    }

    public function testSaveValue_RefusesASaleInAClosedPeriodWithoutLeavingAnyOfIt(): void
    {
        $cycle = $this->referenceInvoice();
        $this->closeThrough(date('Y-m-d'));

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')]));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesAPaymentTypeTheBooksDoNotKnowAndLeavesNoSale(): void
    {
        $cycle = $this->referenceInvoice();
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [$this->payment('Barter', '100.00')]));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::PaymentTypeUnknown, $this->failure()->error);
        $this->assertSame(0, $this->countJournals());
    }

    // ---------------------------------------------------------------------------------------
    // A journal for each payment
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_PostsOneJournalForEachPaymentThatSettlesTheCustomer(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [
            $this->payment(lang('Sales.cash'), '300.00'),
            $this->payment(lang('Sales.check'), '163.50'),
            $this->payment(lang('Sales.bank_transfer'), '100.00'),
        ]);

        $payments = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));

        $this->assertCount(3, $payments);
        $this->assertSame(
            [
                [$this->accountId('cash') . ':300.00:0.00', $this->customerAccount() . ':0.00:300.00'],
                [$this->accountId('cash') . ':163.50:0.00', $this->customerAccount() . ':0.00:163.50'],
                [$this->accountId('cash') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'],
            ],
            array_column($payments, 'lines'),
        );
        $this->assertSame(['general', 'general', 'general'], array_column($payments, 'entry'));
        $this->assertSame('253.00', $this->balance($this->customerAccount()));
        $this->assertSame('563.50', $this->balance($this->accountId('cash')));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function creationOrderProvider(): array
    {
        return ParityFixture::cases('T-06', 'creation_order');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('creationOrderProvider')]
    public function testSaveValue_NumbersAPaidSaleInvoiceThenPaymentThenCostAsTheReferenceSystemDoes(array $case): void
    {
        $this->assertSame('exact', $case['classification'], $case['id']);
        $this->assertSame($case['reference']['order'], $case['expected']['order']);
        $this->assertSame(['invoice', 'payment', 'invoice_cost'], array_keys($case['reference']['numbers']));
        $this->assertSame(range(23, 25), array_values($case['reference']['numbers']));

        $cycle    = $this->referenceInvoice();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $journals = $this->journals($saleId);
        $steps    = ['revenue' => 'invoice', 'general' => 'payment', 'cogs' => 'invoice_cost'];

        $this->assertSame($case['expected']['order'], implode(', ', array_map(static fn (array $journal): string => $steps[$journal['entry']], $journals)));
        $this->assertSame(array_column($journals, 'id'), $this->sortedIds($journals));
        $this->assertSame([1, 1, 1], array_column($journals, 'revision'));
    }

    public function testSaveValue_PostsThePaymentsBetweenTheInvoiceAndTheCostWithTheLinesTheyAlwaysHad(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [
            $this->payment(lang('Sales.cash'), '300.00'),
            $this->payment(lang('Sales.check'), '163.50'),
            $this->payment(lang('Sales.bank_transfer'), '100.00'),
        ]);

        $journals = $this->journals($saleId);

        $this->assertSame(['revenue', 'general', 'general', 'general', 'cogs'], array_column($journals, 'entry'));
        $this->assertSame($this->sortedIds($journals), array_column($journals, 'id'));
        $this->assertSame(
            [
                [$this->customerAccount() . ':816.50:0.00', $this->accountId('sales_revenue') . ':0.00:710.00', $this->accountId('tax_output') . ':0.00:106.50'],
                [$this->accountId('cash') . ':300.00:0.00', $this->customerAccount() . ':0.00:300.00'],
                [$this->accountId('cash') . ':163.50:0.00', $this->customerAccount() . ':0.00:163.50'],
                [$this->accountId('cash') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'],
                [$this->accountId('cogs') . ':400.00:0.00', $this->stockAccount() . ':0.00:400.00'],
            ],
            array_column($journals, 'lines'),
        );
        $this->assertSame('253.00', $this->balance($this->customerAccount()));
        $this->assertSame('563.50', $this->balance($this->accountId('cash')));
        $this->assertTies();
    }

    public function testSaveValue_PostsASaleWithoutPaymentsAsInvoiceThenCost(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame(['revenue', 'cogs'], array_column($this->journals($saleId), 'entry'));
    }

    public function testSaveValue_PostsAPaidSaleOfServicesAsInvoiceThenPayment(): void
    {
        $saleId = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->assertSame(['revenue', 'general'], array_column($this->journals($saleId), 'entry'));
        $this->assertTies();
    }

    public function testSaveValue_PostsTheCostOfAPaidSaleLastWhenItsGoodsAreDeliveredLater(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_INVOICE, -1, false);

        $this->assertSame(['revenue', 'general'], array_column($this->journals($saleId), 'entry'));
        $this->assertSame($saleId, $this->sales->confirm_delivery($saleId, $this->employeeId));
        $this->assertSame(['revenue', 'general', 'cogs'], array_column($this->journals($saleId), 'entry'));
    }

    public function testSaveValue_PostsAReturnWithItsRefundBetweenTheReturnAndItsCost(): void
    {
        $cycle    = $this->referenceInvoice();
        $invoice  = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')]);
        $returnId = $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [$this->payment(lang('Sales.cash'), '-253.00')], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertGreaterThan(0, $returnId);

        $journals = $this->journals($returnId);

        $this->assertSame(['revenue', 'general', 'cogs'], array_column($journals, 'entry'));
        $this->assertSame($this->sortedIds($journals), array_column($journals, 'id'));
        $this->assertSame([$this->customerAccount() . ':253.00:0.00', $this->accountId('cash') . ':0.00:253.00'], $journals[1]['lines']);
        $this->assertTies();
    }

    public function testUpdate_PostsAPaymentAddedLaterAfterEveryJournalTheSaleAlreadyHad(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $before = $this->journals($saleId);

        $paymentId = $this->addPayment($saleId, '163.50');
        $after     = $this->journals($saleId);

        $this->assertGreaterThan(0, $paymentId);
        $this->assertCount(4, $after);
        $this->assertSame($before, array_slice($after, 0, 3), 'a journal that was written is never renumbered or rewritten');
        $this->assertSame(['revenue', 'general', 'cogs', 'general'], array_column($after, 'entry'));
        $this->assertGreaterThan($before[2]['id'], $after[3]['id']);
    }

    /**
     * @param list<array<string, mixed>> $journals
     *
     * @return list<int>
     */
    private function sortedIds(array $journals): array
    {
        $ids = array_column($journals, 'id');

        sort($ids);

        return $ids;
    }

    /**
     * A treasury the employee who sells works from.
     */
    private function drawerOfTheSeller(): int
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Seller drawer', 'kind' => 'cash']);

        $treasuries->setDefaultFor($this->employeeId, $drawer);

        return $drawer;
    }

    private function paymentRow(int $saleId): object
    {
        return $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->orderBy('payment_id', 'ASC')->get()->getRow();
    }

    public function testSaveValue_PostsAPaymentToTheTreasuryTheSellerWorksFrom(): void
    {
        $drawer = $this->drawerOfTheSeller();
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
        $payment = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'))[0];
        $account = (int) (new TreasuryService())->accountOf($drawer);

        $this->assertSame($drawer, (int) $this->paymentRow($saleId)->treasury_id);
        $this->assertSame([$account . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'], $payment['lines']);
        $this->assertSame('100.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('0.00', $this->balance($this->accountId('cash')), 'The main treasury received nothing');
    }

    public function testSaveValue_PostsAPaymentToTheAccountOfItsTypeWhenTheSellerHasNoTreasury(): void
    {
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->assertNull($this->paymentRow($saleId)->treasury_id);
        $this->assertSame('100.00', $this->balance($this->accountId('cash')));
    }

    public function testSaveValue_PutsNoTreasuryOnAPaymentThatSettlesNothingInCash(): void
    {
        $this->drawerOfTheSeller();

        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.due'), '100.00')]);

        $this->assertNull($this->paymentRow($saleId)->treasury_id);
    }

    /**
     * A treasury of the seller's branch that only the employees given may deposit into.
     *
     * @param list<int> $employees
     */
    private function drawerOpenOnlyTo(array $employees, string $direction = 'deposit'): int
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        return (new TreasuryService())->create(['name' => 'Scoped drawer', 'kind' => 'cash', $direction . '_scope' => 'staff', $direction . '_members' => $employees]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceCustomerPayments(): array
    {
        return array_filter(ParityFixture::cases('T-21', 'treasury_payment'), static fn (array $set): bool => $set[0]['inputs']['payer'] === 'customer');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceCustomerPayments')]
    public function testSaveValue_PostsAPaymentToTheChosenTreasuryAsTheReferenceSystemDoes(array $case): void
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $treasury   = $treasuries->create($case['inputs']['treasury'] === 'bank'
            ? ['name' => 'Fixture bank', 'kind' => 'bank', 'bank_name' => 'Riyad Bank', 'account_number' => '3500000001']
            : ['name' => 'Fixture drawer', 'kind' => 'cash']);
        $item       = $this->item('10.00');
        $way        = $case['inputs']['way'] === 'bank_transfer' ? lang('Sales.bank_transfer') : lang('Sales.cash');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment($way, $case['inputs']['amount']) + ['treasury_id' => $treasury]]);
        $payment    = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'))[0];
        $amount     = $case['inputs']['amount'];

        $this->assertSame([(int) $treasuries->accountOf($treasury) . ':' . $amount . ':0.00', $this->customerAccount() . ':0.00:' . $amount], $payment['lines']);
        $this->assertSame($case['expected'], ['debit' => 'treasury', 'credit' => 'customer', 'amount' => $amount]);
        $this->assertSame($case['reference'], $case['expected']);
    }

    public function testSaveValue_RefusesAPaymentIntoATreasuryTheSellerMayNotDepositIntoAndSavesNothing(): void
    {
        $drawer = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])]);
        $item   = $this->item('10.00');
        $sales  = $this->countSales();

        $result = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame('0.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame(0, $this->countRows('sales_payments'));
    }

    public function testSaveValue_AcceptsAPaymentIntoATreasuryTheSellerMayDepositInto(): void
    {
        $drawer = $this->drawerOpenOnlyTo([$this->employeeId]);
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame($drawer, (int) $this->paymentRow($saleId)->treasury_id);
        $this->assertSame('100.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testSaveValue_SendsAPaymentToTheMainTreasuryWhenTheTreasuryTheSellerWorksFromIsClosedToThem(): void
    {
        $drawer = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])]);

        (new TreasuryService())->setDefaultFor($this->employeeId, $drawer);

        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->assertGreaterThan(0, $saleId);
        $this->assertNull($this->paymentRow($saleId)->treasury_id);
        $this->assertSame('100.00', $this->balance($this->accountId('cash')));
        $this->assertSame('0.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testSaveValue_RefusesAPaymentThatWouldGoToTheMainTreasuryWhenItIsClosedToTheSeller(): void
    {
        $treasuries = new TreasuryService();
        $main       = $treasuries->ensureMain();

        $treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);

        $item  = $this->item('10.00');
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame($sales, $this->countSales());
    }

    public function testSaveValue_RefusesAPaymentThatNamesNoTreasuryWhenItsTypeIsRoutedToATreasuryClosedToTheSeller(): void
    {
        $treasuries = new TreasuryService();
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $item  = $this->item('10.00');
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame('0.0000', $treasuries->position($bank)->balance);
    }

    public function testSaveValue_AcceptsAPaymentThatNamesNoTreasuryWhenItsTypeIsRoutedToATreasuryOpenToTheSeller(): void
    {
        $treasuries = new TreasuryService();
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeId]]);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->assertGreaterThan(0, $saleId);
        $this->assertNull($this->paymentRow($saleId)->treasury_id);
        $this->assertSame('100.0000', $treasuries->position($bank)->balance);
    }

    public function testSaveValue_LeavesAPaymentThatSettlesNothingInCashToTheScopeOfNoTreasury(): void
    {
        $treasuries = new TreasuryService();
        $main       = $treasuries->ensureMain();

        $treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);

        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.due'), '100.00')]);

        $this->assertGreaterThan(0, $saleId);
    }

    public function testSaveValue_AsksTheWithdrawalScopeOfAPaymentThatGivesMoneyBackToTheCustomer(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $drawer  = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'withdraw');
        $refund  = [$this->payment(lang('Sales.cash'), '-100.00') + ['treasury_id' => $drawer]];

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], $refund, SALE_TYPE_RETURN, -1, true, $invoice));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);

        $sale = $this->save([$this->line($item, 0, '1', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);

        $this->assertGreaterThan(0, $sale, 'Putting money in was not limited');
    }

    public function testUpdate_RefusesToMoveAPaymentToATreasuryTheEditorMayNotDepositIntoAndChangesNothing(): void
    {
        $item      = $this->item('10.00');
        $saleId    = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])]);

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'moved', 'payments' => [
            ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'treasury_id' => $drawer],
        ]]));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertNull($this->paymentRow($saleId)->treasury_id);
        $this->assertSame('100.00', $this->balance($this->accountId('cash')));
    }

    public function testUpdate_AsksTheWithdrawalScopeOfTheTreasuryAPaymentIsTakenOffAndAcceptsItWhenTheEditorMayWithdraw(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'withdraw');
        $item      = $this->item('10.00');
        $saleId    = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;
        $payment   = ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'treasury_id' => 0];

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'back to main', 'payments' => [$payment]]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame($drawer, (int) $this->paymentRow($saleId)->treasury_id);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $drawer)->update(['withdraw_scope' => 'all']);

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'back to main', 'payments' => [$payment]]));
        $this->assertNull($this->paymentRow($saleId)->treasury_id);
    }

    public function testUpdate_AsksNothingOfAnEditThatChangesNeitherTheAmountNorTheTreasuryOfAPayment(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId]);
        $item      = $this->item('10.00');
        $saleId    = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->accountingDb()->table('treasuries')->where('treasury_id', $drawer)->update(['deposit_scope' => 'branches']);
        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'only a note', 'payments' => [
            ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'treasury_id' => $drawer],
        ]]));
    }

    /**
     * The payment as the edit form sends it back, with what the test changes.
     *
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function scopeEditedPayment(int $paymentId, array $changes = []): array
    {
        return ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, ...$changes];
    }

    public function testUpdate_RefusesToRemoveAPaymentFromATreasuryTheEditorMayNotWithdrawFromAndKeepsItsJournal(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'removed', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '0.00'])]]));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame(1, $this->countRows('sales_payments'));
        $this->assertSame('100.0000', $treasuries->position($drawer)->balance);
    }

    public function testUpdate_RemovesAPaymentFromATreasuryTheEditorMayWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeId], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'removed', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '0.00'])]]));
        $this->assertSame(0, $this->countRows('sales_payments'));
        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);
    }

    public function testUpdate_RefusesToRemoveAPaymentThatLandedOnATreasuryThroughTheRoutingOfItsType(): void
    {
        $treasuries = new TreasuryService();
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]]);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $item      = $this->item('10.00');
        $saleId    = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertNull($this->paymentRow($saleId)->treasury_id);
        $this->assertFalse($this->sales->update($saleId, ['comment' => 'removed', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '0.00'])]]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('100.0000', $treasuries->position($bank)->balance);
    }

    public function testUpdate_RefusesToChangeTheAmountOfAPaymentOfATreasuryClosedToTheEditorEvenWhenTheEditNamesNoTreasury(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeId]);
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '60.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $drawer, 'direction' => 'deposit', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'more money', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '100.00'])]]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('60.0000', $treasuries->position($drawer)->balance);
    }

    public function testUpdate_RefusesToChangeTheWayOfPaymentOfAnUnstampedPaymentToOneRoutedToATreasuryClosedToTheEditor(): void
    {
        $treasuries = new TreasuryService();
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.bank_transfer')->update(['account_id' => $treasuries->accountOf($bank)]);

        $item      = $this->item('10.00');
        $saleId    = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'by transfer', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_type' => lang('Sales.bank_transfer')])]]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('0.0000', $treasuries->position($bank)->balance);
    }

    public function testDelete_RefusesToCancelASaleWhosePaymentsWentIntoATreasuryTheEditorMayNotWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]], SALE_TYPE_POS);

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame(COMPLETED, (int) $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow()->sale_status, 'The sale stands');
        $this->assertSame('100.0000', $treasuries->position($drawer)->balance);
    }

    public function testDelete_CancelsASaleWhosePaymentsWentIntoATreasuryTheEditorMayWithdrawFromAndTheBooksAlwaysDo(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeId], 'withdraw');
        $item       = $this->item('10.00');
        $first      = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]], SALE_TYPE_POS);
        $second     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]], SALE_TYPE_POS);

        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $drawer, 'direction' => 'withdraw', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertSame('200.0000', $treasuries->position($drawer)->balance);

        (new SalePosting())->cancel($second);

        $this->assertSame('100.0000', $treasuries->position($drawer)->balance, 'The books act without an employee');
        $this->assertFalse($this->sales->delete($first, false, true, $this->employeeId));
        $this->assertSame('100.0000', $treasuries->position($drawer)->balance);
    }

    public function testDelete_CancelsASaleWhosePaymentsWentIntoATreasuryTheEditorMayWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeId], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]], SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);
    }

    public function testUpdate_RefusesToLowerThePaymentOfATreasuryTheEditorMayNotWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'less money', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '60.00'])]]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('100.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('100.00', $this->paymentRow($saleId)->payment_amount);
    }

    public function testUpdate_LowersThePaymentOfATreasuryTheEditorMayWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeId], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'less money', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '60.00'])]]));
        $this->assertSame('60.0000', $treasuries->position($drawer)->balance);
    }

    public function testUpdate_RefusesToChangeThePaymentOfATreasuryToAWayOfPaymentThatUsesNoTreasury(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'on account', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_type' => lang('Sales.due')])]]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('100.0000', $treasuries->position($drawer)->balance);
        $this->assertSame(lang('Sales.cash'), $this->paymentRow($saleId)->payment_type);
    }

    public function testUpdate_RefusesToChangeOrRemoveAPaymentThatBelongsToAnotherSale(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $item       = $this->item('10.00');
        $mine       = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $other      = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $foreignId  = (int) $this->paymentRow($other)->payment_id;

        $this->assertFalse($this->sales->update($mine, ['comment' => 'removes it', 'payments' => [$this->scopeEditedPayment($foreignId, ['payment_amount' => '0.00'])]]));
        $this->assertSame(AccountingError::SalePaymentNotOnSale, $this->sales->accountingFailure()->error);
        $this->assertFalse($this->sales->update($mine, ['comment' => 'lowers it', 'payments' => [$this->scopeEditedPayment($foreignId, ['payment_amount' => '60.00'])]]));
        $this->assertSame(AccountingError::SalePaymentNotOnSale, $this->sales->accountingFailure()->error);
        $this->assertSame('100.00', $this->paymentRow($other)->payment_amount);
        $this->assertSame('200.0000', $treasuries->position($drawer)->balance);
    }

    public function testUpdate_RefusesToTakeThePaymentOfATreasuryOutOfTheBooksByChangingItsStatus(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeWithGrants([])], 'withdraw');
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'not paid after all', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_status' => 'draft'])]]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('100.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('completed', $this->paymentRow($saleId)->payment_status);
    }

    public function testUpdate_KeepsTheTreasuryThePaymentCarriesEvenWhenItBelongsToAnotherBranchButRefusesToMoveItThere(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Carried drawer', 'kind' => 'cash']);
        $other      = $treasuries->create(['name' => 'Other drawer', 'kind' => 'cash']);
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;
        $branch     = $this->createBranch('B2');

        $this->accountingDb()->table('treasuries')->whereIn('treasury_id', [$drawer, $other])->update(['branch_id' => $branch]);

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'only a note', 'payments' => [$this->scopeEditedPayment($paymentId, ['treasury_id' => $drawer])]]));
        $this->assertFalse($this->sales->update($saleId, ['comment' => 'moved', 'payments' => [$this->scopeEditedPayment($paymentId, ['treasury_id' => $other])]]));
        $this->assertSame(AccountingError::TreasuryNotFound, $this->sales->accountingFailure()->error);
        $this->assertSame($drawer, (int) $this->paymentRow($saleId)->treasury_id);
    }

    public function testUpdate_RefusesToCompleteAPaymentIntoATreasuryTheEditorMayNotDepositInto(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $this->drawerOpenOnlyTo([$this->employeeId]);
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $drawer]]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'not paid yet', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_status' => 'draft'])]]));
        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);

        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $drawer, 'direction' => 'deposit', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'paid after all', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_status' => 'completed'])]]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('draft', $this->paymentRow($saleId)->payment_status);
    }

    public function testUpdate_AsksTheScopeAgainWhenTheAmountOfAPaymentChanges(): void
    {
        $drawer    = $this->drawerOpenOnlyTo([$this->employeeId]);
        $item      = $this->item('10.00');
        $saleId    = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '60.00') + ['treasury_id' => $drawer]]);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;
        $payment   = ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'treasury_id' => $drawer];

        $this->accountingDb()->table('treasury_scope_members')->where('treasury_id', $drawer)->delete();
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $drawer, 'direction' => 'deposit', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'more money', 'payments' => [$payment]]));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('60.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testUpdate_MovesAPaymentToTheTreasuryItIsGivenByReversingAndPostingItAgain(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Drawer', 'kind' => 'cash']);
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
        $paymentId  = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertSame('100.00', $this->balance($this->accountId('cash')));

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'moved', 'payments' => [
            ['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'treasury_id' => $drawer],
        ]]));

        $this->assertSame($drawer, (int) $this->paymentRow($saleId)->treasury_id);
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertSame('100.0000', $treasuries->position($drawer)->balance);
        $this->assertSame(['1:reversed', '2:posted'], array_map(
            static fn (array $journal): string => $journal['revision'] . ':' . $journal['status'],
            array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment' && $journal['entry'] === 'general' && $journal['revision'] <= 2)),
        ));
    }

    public function testSaveValue_LeavesWhatIsNotPaidOnTheCustomerAndPostsNothingForACreditPayment(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00'), $this->payment(lang('Sales.due'), '516.50')]);

        $this->assertCount(1, array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));
        $this->assertSame('516.50', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_TakesTheChangeGivenBackOffTheCashReceived(): void
    {
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '120.00', '20.00')]);

        $payment = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'))[0];

        $this->assertSame([$this->accountId('cash') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'], $payment['lines']);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_SendsTheCashRoundingToTheCashDifferencesAccountAndSettlesTheCustomer(): void
    {
        $item   = $this->item('1.00');
        $saleId = $this->save([$this->line($item, 0, '1', '9.98', '1.00')], [], [
            $this->payment(lang('Sales.cash'), '10.00'),
            $this->payment(lang('Sales.cash_adjustment'), '0.02', '0', CASH_ADJUSTMENT_TRUE),
        ]);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('-0.02', $this->balance($this->accountId('cash_difference')));
    }

    public function testSaveValue_PostsASaleWithoutACustomerToTheCounterCustomersAndSettlesThem(): void
    {
        $item   = $this->item('20.00');
        $saleId = $this->save([$this->line($item, 0, '2', '50', '20.00')], [], [$this->payment(lang('Sales.cash'), '100.00')], SALE_TYPE_POS, null);

        $this->assertSame([$this->accountId('counter_customers') . ':100.00:0.00', $this->accountId('sales_revenue') . ':0.00:100.00'], $this->standing($saleId, 'revenue')[0]['lines']);
        $this->assertSame('0.00', $this->balance($this->accountId('counter_customers')));
        $this->assertSame('100.00', $this->balance($this->accountId('cash')));
    }

    public function testSaveValue_RefusesASaleWithoutACustomerThatIsNotPaidInFullAndLeavesNoSale(): void
    {
        $item  = $this->item('20.00');
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '2', '50', '20.00')], [], [$this->payment(lang('Sales.cash'), '60.00')], SALE_TYPE_POS, null));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::CreditNeedsCustomer, $this->failure()->error);
    }

    public function testPostCompleted_RunAgainWritesNothingMore(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $before = $this->journals($saleId);

        $posting = new SalePosting();
        $posting->postCompleted($saleId, $this->employeeId);
        $posting->postCompleted($saleId, $this->employeeId);
        $posting->syncPayments($saleId, $this->employeeId);

        $this->assertSame($before, $this->journals($saleId));
        $this->assertCount(3, $before);
    }

    public function testUpdate_PostsAPaymentAddedLaterAsItsOwnJournal(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '300.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $payments = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));

        $this->assertCount(1, $payments);
        $this->assertSame([$this->accountId('cash') . ':300.00:0.00', $this->customerAccount() . ':0.00:300.00'], $payments[0]['lines']);
    }

    public function testUpdate_ReversesAndPostsAgainAPaymentWhoseAmountChanged(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'corrected', 'payments' => [
            ['payment_id' => (int) $row->payment_id, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '350.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $journals = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));

        $this->assertSame(['reversed', 'posted'], array_column($journals, 'status'));
        $this->assertSame([1, 2], array_column($journals, 'revision'));
        $this->assertSame([$this->accountId('cash') . ':350.00:0.00', $this->customerAccount() . ':0.00:350.00'], $journals[1]['lines']);
        $this->assertSame('466.50', $this->balance($this->customerAccount()));
    }

    public function testUpdate_LeavesTheJournalOfAPaymentWhoseTypeChangedToAnotherThatPostsTheSame(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();
        $before = $this->journals($saleId);

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'by cheque', 'payments' => [
            ['payment_id' => (int) $row->payment_id, 'payment_type' => lang('Sales.check'), 'payment_amount' => '300.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $this->assertSame($before, $this->journals($saleId));
    }

    public function testUpdate_ReversesTheJournalOfAPaymentThatIsRemoved(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'removed', 'payments' => [
            ['payment_id' => (int) $row->payment_id, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $this->assertSame(0, $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->countAllResults());
        $this->assertSame([], array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment' && $journal['status'] === 'posted'));
        $this->assertSame('816.50', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
    }

    public function testUpdate_RefusesToChangeTheCustomerOrTheDateOfAPostedSale(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);
        $other  = $this->insertCustomer('Hind', 'Saad');
        $before = $this->journals($saleId);

        $this->assertFalse($this->sales->update($saleId, ['customer_id' => $other, 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);

        $this->assertFalse($this->sales->update($saleId, ['sale_time' => date('Y-m-d H:i:s', strtotime('-3 days')), 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);

        $this->assertSame((string) $this->customerId, (string) $this->saleRow($saleId)->customer_id);
        $this->assertSame($before, $this->journals($saleId));
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testUpdate_LetsTheCommentChangeWhileTheCustomerAndTheDateStay(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);
        $sale   = $this->saleRow($saleId);

        $this->assertTrue($this->sales->update($saleId, [
            'sale_time'   => $sale->sale_time,
            'customer_id' => $this->customerId,
            'comment'     => 'a note',
        ]));
        $this->assertSame('a note', $this->saleRow($saleId)->comment);
    }

    public function testUpdate_UndoesTheWholeChangeWhenAPaymentCannotBePosted(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $before = $this->journals($saleId);

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'Barter', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => 'Barter', 'payment_amount' => '50.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $this->assertSame(PostingError::PaymentTypeUnknown, $this->failure()->error);
        $this->assertSame('test', $this->saleRow($saleId)->comment);
        $this->assertSame(1, $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->countAllResults());
        $this->assertSame($before, $this->journals($saleId));
    }

    public function testUpdate_RefusesToLeaveASaleWithoutACustomerNotPaidInFull(): void
    {
        $item   = $this->item('20.00');
        $saleId = $this->save([$this->line($item, 0, '2', '50', '20.00')], [], [$this->payment(lang('Sales.cash'), '100.00')], SALE_TYPE_POS, null);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();
        $before = $this->journals($saleId);

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'x', 'payments' => [
            ['payment_id' => (int) $row->payment_id, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $this->assertSame(PostingError::CreditNeedsCustomer, $this->failure()->error);
        $this->assertSame(1, $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->countAllResults());
        $this->assertSame($before, $this->journals($saleId));
    }

    // ---------------------------------------------------------------------------------------
    // The draft, the invoice that is not delivered, and the delivery that follows
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_PostsNothingForASuspendedSaleAndPostsItWhenItIsCompleted(): void
    {
        $cycle  = $this->referenceInvoice();
        $stock  = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([], $this->journals($saleId));
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);

        $this->assertSame($saleId, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $saleId));

        $this->assertCount(2, $this->standing($saleId));
        $this->assertEqualsWithDelta($stock - 4, $this->stock($cycle['cream']), 0.0001);
    }

    public function testSaveValue_PostsTheInvoiceOfAnUndeliveredSaleWithoutCostOrStockMovement(): void
    {
        $cycle  = $this->referenceInvoice();
        $stock  = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, false);

        $this->assertSame('0', (string) $this->saleRow($saleId)->delivered);
        $this->assertCount(1, $this->standing($saleId, 'revenue'));
        $this->assertSame([], $this->standing($saleId, 'cogs'));
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
    }

    public function testSaveValue_AnInvoiceIsTheOnlyKindOfSaleThatCanWaitForItsDelivery(): void
    {
        $item   = $this->item('20.00');
        $saleId = $this->save([$this->line($item, 0, '1', '50', '20.00')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, -1, false);

        $this->assertSame('1', (string) $this->saleRow($saleId)->delivered);
        $this->assertCount(1, $this->standing($saleId, 'cogs'));
    }

    public function testConfirmDelivery_TakesTheStockAndPostsTheCostAtTheCostOfThatMoment(): void
    {
        $cycle   = $this->referenceInvoice();
        $saleId  = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, false);
        $stock   = $this->stock($cycle['cream']);
        $perfume = $this->stock($cycle['perfume']);

        $this->accountingDb()->table('items')->where('item_id', $cycle['cream'])->update(['cost_price' => '45.00']);

        $this->assertSame($saleId, $this->sales->confirm_delivery($saleId, $this->employeeId));

        $cost = $this->standing($saleId, 'cogs');

        $this->assertCount(1, $cost);
        $this->assertSame([$this->accountId('cogs') . ':420.00:0.00', $this->stockAccount() . ':0.00:420.00'], $cost[0]['lines']);
        $this->assertSame(date('Y-m-d'), $cost[0]['date']);
        $this->assertSame('1', (string) $this->saleRow($saleId)->delivered);
        $this->assertEqualsWithDelta($stock - 4, $this->stock($cycle['cream']), 0.0001);
        $this->assertEqualsWithDelta($perfume - 2, $this->stock($cycle['perfume']), 0.0001);
        $this->assertSame('45.000000', $this->accountingDb()->table('sales_items')->where(['sale_id' => $saleId, 'item_id' => $cycle['cream']])->get()->getRow()->item_cost_price);
    }

    public function testConfirmDelivery_ConfirmingAgainChangesNothing(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, false);

        $this->sales->confirm_delivery($saleId, $this->employeeId);

        $stock    = $this->stock($cycle['cream']);
        $journals = $this->journals($saleId);

        $this->assertSame($saleId, $this->sales->confirm_delivery($saleId, $this->employeeId));
        $this->assertSame($journals, $this->journals($saleId));
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
    }

    public function testConfirmDelivery_RefusesWhatIsNotAnIssuedInvoice(): void
    {
        $item = $this->item('20.00');
        $pos  = $this->save([$this->line($item, 0, '1', '50', '20.00')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS);
        $draft = $this->save([$this->line($item, 0, '1', '50', '20.00')], [], [], SALE_TYPE_INVOICE, -1, false, null, SUSPENDED);

        $this->assertSame(NEW_ENTRY, $this->sales->confirm_delivery($pos, $this->employeeId));
        $this->assertSame(NEW_ENTRY, $this->sales->confirm_delivery($draft, $this->employeeId));
        $this->assertSame(NEW_ENTRY, $this->sales->confirm_delivery(999999, $this->employeeId));
    }

    public function testConfirmDelivery_UndoesTheDeliveryWhenTheCostCannotBePosted(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, false);
        $stock  = $this->stock($cycle['cream']);
        $this->closeThrough(date('Y-m-d'));

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->confirm_delivery($saleId, $this->employeeId));

        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame('0', (string) $this->saleRow($saleId)->delivered);
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame([], $this->standing($saleId, 'cogs'));
        $this->assertSame('40.000000', $this->accountingDb()->table('sales_items')->where(['sale_id' => $saleId, 'item_id' => $cycle['cream']])->get()->getRow()->item_cost_price);
    }

    public function testDelete_RefusesToCancelAnIssuedInvoiceThatWasNotDeliveredEither(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, false);
        $stock  = $this->stock($cycle['cream']);
        $before = $this->journals($saleId);

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));

        $this->assertSame(AccountingError::IssuedDocumentLocked, $this->failure()->error);
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame($before, $this->journals($saleId));
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($saleId)->sale_status);
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    // ---------------------------------------------------------------------------------------
    // Cancelling, returning, and what they do to the customer and the stock
    // ---------------------------------------------------------------------------------------

    public function testDelete_ReversesEveryJournalOfTheSaleAndGivesTheStockBack(): void
    {
        $cycle  = $this->referenceInvoice();
        $before = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS);

        $this->assertEqualsWithDelta($before - 4, $this->stock($cycle['cream']), 0.0001);
        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $this->assertSame([], $this->standing($saleId));
        $this->assertSame(['reversed', 'reversed', 'reversed'], array_column($this->journals($saleId), 'status'));
        $this->assertEqualsWithDelta($before, $this->stock($cycle['cream']), 0.0001);

        foreach ([$this->customerAccount(), $this->accountId('sales_revenue'), $this->accountId('tax_output'), $this->accountId('cogs'), $this->stockAccount(), $this->accountId('cash')] as $accountId) {
            $this->assertSame('0.00', $this->balance($accountId), (string) $accountId);
        }
    }

    public function testDelete_CancelingTwiceChangesNothing(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS);

        $this->sales->delete($saleId, false, true, $this->employeeId);

        $stock    = $this->stock($cycle['cream']);
        $journals = $this->countJournals();

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame($journals, $this->countJournals());
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
    }

    public function testDelete_DatesTheReversalOnTheDayOfTheSaleWhenItsPeriodIsOpen(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS);

        $this->sales->delete($saleId, false, true, $this->employeeId);

        $reversals = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getResult();

        $this->assertCount(2, $reversals);

        foreach ($reversals as $reversal) {
            $this->assertSame(date('Y-m-d'), $reversal->journal_date);
            $this->assertSame('system', $reversal->origin);
        }
    }

    public function testDelete_DatesTheReversalTodayWhenThePeriodOfTheSaleHasBeenClosedSince(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS);

        $this->accountingDb()->table('accounting_journals')->whereIn('journal_id', array_column($this->journals($saleId), 'id'))->update(['journal_date' => '2026-03-10']);
        $this->closeThrough('2026-06-30');

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        foreach ($this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getResult() as $reversal) {
            $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        }

        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testDelete_UndoesTheCancellationWhenItCannotBePosted(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS);
        $stock  = $this->stock($cycle['cream']);
        $this->closeThrough('2035-12-31');

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));

        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($saleId)->sale_status);
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertCount(2, $this->standing($saleId));
    }

    public function testDelete_ARestoredSaleCompletedAgainPostsANewRevision(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS);

        $this->sales->delete($saleId, false, true, $this->employeeId);
        $this->sales->restore_list([$saleId], $this->employeeId);

        $this->assertSame([], $this->standing($saleId));
        $this->assertSame($saleId, $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS, -1, true, null, COMPLETED, $saleId));

        $standing = $this->standing($saleId);

        $this->assertCount(3, $standing);
        $this->assertSame(['revenue', 'general', 'cogs'], array_column($standing, 'entry'));
        $this->assertSame([2, 1, 2], array_column($standing, 'revision'));
        $this->assertSame('516.50', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_PostsAReturnAtTheCostOfTheSaleItComesFrom(): void
    {
        $cycle    = $this->referenceInvoice();
        $invoice  = $this->save($cycle['items'], $cycle['taxes']);
        $this->accountingDb()->table('items')->where('item_id', $cycle['perfume'])->update(['cost_price' => '130.00']);

        $returnId = $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '130.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame(
            [$this->accountId('sales_returns') . ':220.00:0.00', $this->accountId('tax_output') . ':33.00:0.00', $this->customerAccount() . ':0.00:253.00'],
            $this->standing($returnId, 'revenue')[0]['lines'],
        );
        $this->assertSame([$this->stockAccount() . ':120.00:0.00', $this->accountId('cogs') . ':0.00:120.00'], $this->standing($returnId, 'cogs')[0]['lines']);
        $this->assertSame((string) $invoice, (string) $this->saleRow($returnId)->return_of_sale_id);
    }

    // ---------------------------------------------------------------------------------------
    // The refund invoice: the same return as a credit note, issued as the other document
    // ---------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cycleProvider')]
    public function testSaveValue_PostsARefundInvoiceWithTheLinesOfTheCreditNoteOfTheReferenceCycle(array $case): void
    {
        $invoice = $case['inputs']['invoice'];
        $note    = $case['inputs']['credit_note'];
        $ids     = [];
        $items   = [];
        $taxes   = [];

        foreach ($invoice['items'] as $line => $row) {
            $itemId            = $this->item($row['cost']);
            $ids[$row['name']] = $itemId;
            $items[]           = $this->line($itemId, $line, $row['quantity'], $row['price'], $row['cost'], $row['discount'], $row['discount_type'] === 'percent' ? PERCENT : FIXED);
            $taxes[]           = $this->tax($itemId, $line, $row['tax']);
        }

        $invoiceId = $this->save($items, $taxes);

        $this->accountingDb()->table('items')->where('item_id', $ids[$note['item']])->update(['cost_price' => $note['cost_of_the_item_now']]);

        $refundId = $this->save(
            [$this->line($ids[$note['item']], 0, '-' . $note['quantity'], $note['price'], $note['cost_of_the_item_now'])],
            [$this->tax($ids[$note['item']], 0, '-' . $note['tax'])],
            [],
            SALE_TYPE_RETURN,
            -1,
            true,
            $invoiceId,
            COMPLETED,
            NEW_ENTRY,
            null,
            ReturnDocument::RefundInvoice,
        );

        $this->assertGreaterThan(0, $refundId);

        $steps = array_column($case['expected']['journals'], null, 'step');

        foreach (['credit_note' => 'revenue', 'credit_note_cost' => 'cogs'] as $step => $entry) {
            $posted = $this->standing($refundId, $entry);

            $this->assertCount(1, $posted, $step);
            $this->assertSame($steps[$step]['entry'], $posted[0]['entry'], $step);
            $this->assertSame($this->shapeOfRoles($steps[$step]['lines']), $this->sortedLines($posted[0]['lines']), $step);
        }

        $this->assertSame(ReturnDocument::RefundInvoice->value, $this->saleRow($refundId)->return_document);
        $this->assertTies();
    }

    public function testSaveValue_PostsARefundInvoiceWithTheSameJournalsAsACreditNoteOfTheSameLines(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $this->accountingDb()->table('items')->where('item_id', $cycle['perfume'])->update(['cost_price' => '130.00']);

        $lines = [$this->line($cycle['perfume'], 0, '-1', '220', '130.00')];
        $taxes = [$this->tax($cycle['perfume'], 0, '-33.00')];
        $note  = $this->save($lines, $taxes, [], SALE_TYPE_RETURN, -1, true, $invoice);
        $stock = $this->stock($cycle['perfume']);
        $after = $this->save($lines, $taxes, [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice);

        $this->assertGreaterThan(0, $note);
        $this->assertGreaterThan(0, $after);
        $this->assertSame(
            array_map(static fn (array $journal): array => [$journal['source'], $journal['entry'], $journal['revision'], $journal['status'], $journal['lines']], $this->journals($note)),
            array_map(static fn (array $journal): array => [$journal['source'], $journal['entry'], $journal['revision'], $journal['status'], $journal['lines']], $this->journals($after)),
        );
        $this->assertEqualsWithDelta($stock + 1, $this->stock($cycle['perfume']), 0.0001);
        $this->assertNull($this->saleRow($note)->return_document);
        $this->assertSame(ReturnDocument::RefundInvoice->value, $this->saleRow($after)->return_document);
        $this->assertSame((string) $invoice, (string) $this->saleRow($after)->return_of_sale_id);
    }

    public function testSaveValue_ReturnsTheStockAndReversesTheCostOfARefundInvoiceAtTheOriginalCost(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $stock   = $this->stock($item);
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => '66.00']);

        $refund = $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [$this->payment(lang('Sales.cash'), '-200.00')], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice);

        $this->assertGreaterThan(0, $refund);
        $this->assertEqualsWithDelta($stock + 2, $this->stock($item), 0.0001);
        $this->assertSame([$this->stockAccount() . ':120.00:0.00', $this->accountId('cogs') . ':0.00:120.00'], $this->standing($refund, 'cogs')[0]['lines']);
        $this->assertSame('-180.00', $this->balance($this->stockAccount()));
        $this->assertSame('180.00', $this->balance($this->accountId('cogs')));
        $this->assertSame(['revenue', 'general', 'cogs'], array_column($this->journals($refund), 'entry'));
    }

    public function testSaveValue_SettlesARefundInvoiceIntoItsInvoiceAsACreditNoteIs(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $refund  = $this->refundInvoiceOf($invoice, '40.00');

        $this->assertGreaterThan(0, $refund);
        $this->assertSame(['credit_note:' . $refund . '->' . $invoice . ':40.00'], $this->applications());
        $this->assertSame('60.00', bcadd($this->position()->open(), '0', 2));
        $this->assertSame('0.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSaveValue_KeepsTheCreditOfARefundInvoiceWhenTheInvoiceIsPaid(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $refund  = $this->refundInvoiceOf($invoice, '40.00');

        $this->assertGreaterThan(0, $refund);
        $this->assertSame([], $this->applications());
        $this->assertSame('40.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSaveValue_ARefundInvoiceAndACreditNoteTogetherCannotTakeBackMoreThanWasSold(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '2', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '200.00')]);
        $note    = $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $sales   = $this->countSales();

        $this->assertGreaterThan(0, $note);
        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-2', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice));
        $this->assertSame(PostingError::SaleReturnExceedsOrigin, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice));
    }

    public function testSaveValue_RefusesARefundInvoiceOfStockThatNamesNoInvoiceAndLeavesNothingOfIt(): void
    {
        $item  = $this->item('60.00');
        $stock = $this->stock($item);
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice));
        $this->assertSame(PostingError::ReturnOriginRequired, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertEqualsWithDelta($stock, $this->stock($item), 0.0001);
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesARefundInvoiceOfAnInvoiceThatWasNeverDelivered(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice));
        $this->assertSame(PostingError::ReturnOfUndelivered, $this->failure()->error);
    }

    public function testSaveValue_RefusesARefundInvoiceInAClosedPeriodWithoutLeavingAnyOfIt(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $stock   = $this->stock($cycle['perfume']);
        $this->closeThrough(date('Y-m-d'));

        $sales    = $this->countSales();
        $journals = $this->countJournals();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($journals, $this->countJournals());
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['perfume']), 0.0001);
    }

    public function testDelete_RefusesToCancelARefundInvoiceAsItDoesACreditNote(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $refund  = $this->refundInvoiceOf($invoice, '40.00');
        $before  = $this->journals($refund);

        $this->assertFalse($this->sales->delete($refund, false, true, $this->employeeId));
        $this->assertSame(AccountingError::IssuedDocumentLocked, $this->failure()->error);
        $this->assertSame($before, $this->journals($refund));
        $this->assertSame(['credit_note:' . $refund . '->' . $invoice . ':40.00'], $this->applications());
    }

    public function testUpdate_RefusesToMoveARefundInvoiceToAnotherCustomerOrDateAsItDoesACreditNote(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $refund  = $this->refundInvoiceOf($invoice, '40.00');
        $other   = $this->insertCustomer('Hind', 'Saad');
        $before  = $this->journals($refund);

        $this->assertFalse($this->sales->update($refund, ['customer_id' => $other, 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);
        $this->assertFalse($this->sales->update($refund, ['sale_time' => date('Y-m-d H:i:s', strtotime('-3 days')), 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);
        $this->assertSame((string) $this->customerId, (string) $this->saleRow($refund)->customer_id);
        $this->assertSame($before, $this->journals($refund));
    }

    public function testUpdate_LetsTheNoteOfARefundInvoiceChangeAndKeepsItsDocument(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $refund  = $this->refundInvoiceOf($invoice, '40.00');
        $sale    = $this->saleRow($refund);

        $this->assertTrue($this->sales->update($refund, ['sale_time' => $sale->sale_time, 'customer_id' => $this->customerId, 'comment' => 'a note']));
        $this->assertSame('a note', $this->saleRow($refund)->comment);
        $this->assertSame(ReturnDocument::RefundInvoice->value, $this->saleRow($refund)->return_document);
    }

    public function testUpdate_ReversesAndPostsAgainTheRefundPaymentOfARefundInvoiceWhoseAmountChanged(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $refund  = $this->save([$this->line($item, 0, '-2', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '-100.00')], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice);
        $row     = $this->accountingDb()->table('sales_payments')->where('sale_id', $refund)->get()->getRow();

        $this->assertTrue($this->sales->update($refund, ['comment' => 'corrected', 'payments' => [
            ['payment_id' => (int) $row->payment_id, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '-150.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $journals = array_values(array_filter($this->journals($refund), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));

        $this->assertSame(['reversed', 'posted'], array_column($journals, 'status'));
        $this->assertSame([1, 2], array_column($journals, 'revision'));
        $this->assertSame([$this->customerAccount() . ':150.00:0.00', $this->accountId('cash') . ':0.00:150.00'], $journals[1]['lines']);
        $this->assertTies();
    }

    public function testUpdate_AppliesTheCreditOfARefundInvoiceWhenTheInvoicePaymentIsRemovedAndReleasesItWhenPaidAgain(): void
    {
        $invoice   = $this->invoiceOf('100.00');
        $paymentId = $this->addPayment($invoice, '100.00');
        $refund    = $this->refundInvoiceOf($invoice, '100.00');

        $this->assertSame([], $this->applications());
        $this->assertSame('100.00', bcadd($this->position()->credit(), '0', 2));

        $this->removePayment($invoice, $paymentId);

        $this->assertSame(['credit_note:' . $refund . '->' . $invoice . ':100.00'], $this->applications());
        $this->assertTies();

        $this->addPayment($invoice, '100.00');

        $this->assertSame([], $this->applications());
        $this->assertSame('100.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSaveValue_TheCustomerAccountFollowsTheWholeCycleOfInvoiceReturnAndPayments(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame('816.50', $this->balance($this->customerAccount()));

        $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertSame('563.50', $this->balance($this->customerAccount()));

        $this->assertTrue($this->sales->update($invoice, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '300.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.check'), 'payment_amount' => '163.50', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => 'CHQ1234'],
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.bank_transfer'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => 'TRF1234'],
        ]]));

        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('-710.00', $this->balance($this->accountId('sales_revenue')));
        $this->assertSame('220.00', $this->balance($this->accountId('sales_returns')));
        $this->assertSame('-73.50', $this->balance($this->accountId('tax_output')));
        $this->assertSame('563.50', $this->balance($this->accountId('cash')));
        $this->assertSame('280.00', $this->balance($this->accountId('cogs')));
        $this->assertSame('-280.00', $this->balance($this->stockAccount()));
    }

    public function testSaveValue_AReturnAfterPaymentLeavesCreditOnTheCustomerUntilItIsRefunded(): void
    {
        $item     = $this->item('60.00');
        $invoice  = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $returnId = $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame('-200.00', $this->balance($this->customerAccount()));

        $refund = $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], [$this->payment(lang('Sales.cash'), '-100.00')], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertGreaterThan(0, $refund);
        $this->assertSame('-200.00', $this->balance($this->customerAccount()));
        $this->assertSame('400.00', $this->balance($this->accountId('cash')));
    }

    public function testSaveValue_ReturnsTheStockToTheLocationAndTheInventoryAccountAtTheOriginalCost(): void
    {
        $item     = $this->item('60.00');
        $invoice  = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $stock    = $this->stock($item);
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => '66.00']);

        $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [$this->payment(lang('Sales.cash'), '-200.00')], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertEqualsWithDelta($stock + 2, $this->stock($item), 0.0001);
        $this->assertSame('-180.00', $this->balance($this->stockAccount()));
        $this->assertSame('180.00', $this->balance($this->accountId('cogs')));
    }

    public function testSaveValue_RefusesAReturnOfStockThatNamesNoSaleAndLeavesNothingOfIt(): void
    {
        $item     = $this->item('60.00');
        $stock    = $this->stock($item);
        $sales    = $this->countSales();
        $payments = $this->countRows('sales_payments');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '-100.00')], SALE_TYPE_RETURN));

        $this->assertSame(PostingError::ReturnOriginRequired, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($payments, $this->countRows('sales_payments'));
        $this->assertEqualsWithDelta($stock, $this->stock($item), 0.0001);
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesAReturnOfAnItemThatWasNotOnTheSaleItNamesAndLeavesNothingOfIt(): void
    {
        $sold    = $this->item('60.00');
        $other   = $this->item('15.00');
        $invoice = $this->save([$this->line($sold, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $sales   = $this->countSales();
        $stock   = $this->stock($other);

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($other, 0, '-1', '40', '15.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));

        $this->assertSame(PostingError::ReturnLineNotOnOrigin, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertEqualsWithDelta($stock, $this->stock($other), 0.0001);
    }

    public function testSaveValue_RefusesAReturnOfASaleThatWasNeverDelivered(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));
        $this->assertSame(PostingError::ReturnOfUndelivered, $this->failure()->error);
    }

    public function testSaveValue_ReturnsOfServicesNeedNoSaleToComeFrom(): void
    {
        $service = $this->item('0', false);
        $returnId = $this->save([$this->line($service, 0, '-1', '100')], [], [$this->payment(lang('Sales.cash'), '-100.00')], SALE_TYPE_RETURN);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame([], $this->standing($returnId, 'cogs'));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_AReturnOfAnUnpaidSaleReducesWhatTheCustomerOwes(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')]);

        $this->assertSame('500.00', $this->balance($this->customerAccount()));

        $this->save([$this->line($item, 0, '-5', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
    }

    public function testSaveValue_RefusesAReturnOfMoreThanTheSaleBroughtAndLeavesNothingOfIt(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')]);
        $sales   = $this->countSales();
        $stock   = $this->stock($item);
        $entries = $this->countRows('accounting_stock_entries');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-6', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));

        $this->assertSame(PostingError::SaleReturnExceedsOrigin, $this->failure()->error);
        $this->assertSame('5.000', $this->failure()->context['max']);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
        $this->assertEqualsWithDelta($stock, $this->stock($item), 0.0001);
        $this->assertSame('500.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_AllowsAReturnOfExactlyWhatTheSaleBrought(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')]);

        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '-5', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));
    }

    public function testSaveValue_CountsWhatEarlierReturnsGaveBackAgainstTheSale(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')]);

        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '-3', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));
        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-3', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));
        $this->assertSame(PostingError::SaleReturnExceedsOrigin, $this->failure()->error);
        $this->assertSame('2.000', $this->failure()->context['max']);
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '-2', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_AReturnTheBooksRefuseLeavesNoStockMovementOfItsOwn(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')]);
        $entries = $this->countRows('accounting_stock_entries');

        $this->closeThrough(date('Y-m-d'));

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-2', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));
        $this->assertSame($entries, $this->countRows('accounting_stock_entries'));
    }

    public function testSaveValue_ReturnsFromTwoRequestsAtTheSameMomentGiveBackNoMoreThanTheSaleBrought(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')], SALE_TYPE_POS, null);
        $stock   = $this->stock($item);
        $cash    = lang('Sales.cash');

        $arguments = [$invoice, $item, '-3', '60.00', '100', 0, $this->employeeId, $cash, '-300.00'];

        [$first, $second] = $this->raceTwoProcesses('returnSale', $arguments, $arguments);

        $this->assertSame(1, (int) $first + (int) $second, 'Of two returns of 3 against a sale of 5, one is taken and the other refused');

        $returns = $this->accountingDb()->table('sales')->where('return_of_sale_id', $invoice)->get()->getResult();

        foreach ($returns as $return) {
            $this->saleIds[] = (int) $return->sale_id;
        }

        $this->assertCount(1, $returns);
        $this->assertEqualsWithDelta($stock + 3, $this->stock($item), 0.0001);
        $this->assertSame('200.00', $this->balance($this->accountId('cash')));
        $this->assertSame('-120.00', $this->balance($this->stockAccount()));
    }

    public function testSaveValue_RecordsTheStockMovementsOfTheCostAndTiesToTheInventoryAccount(): void
    {
        $item = $this->item('60.00');
        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '5', '100', '60.00')]));

        $entries = $this->accountingDb()->table('accounting_stock_entries')->where('item_id', $item)->where('source_type', 'sale')->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame('-5.000', $entries[0]->quantity);
        $this->assertSame('-300.0000', $entries[0]->value);
        $this->assertSame('posting', $entries[0]->kind);
        $this->assertSame('-300.00', $this->balance($this->stockAccount()));
    }

    public function testDelete_TakesTheStockMovementsOfTheCostBackWithTheJournal(): void
    {
        $item   = $this->item('60.00');
        $saleId = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')], SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $kinds = array_column($this->accountingDb()->table('accounting_stock_entries')->select('kind')->where('item_id', $item)->where('source_id', $saleId)->orderBy('kind', 'ASC')->get()->getResultArray(), 'kind');

        $this->assertSame(['posting', 'reversal'], $kinds);
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
    }

    public function testConfirmDelivery_RecordsTheStockMovementsOnlyWhenTheGoodsLeave(): void
    {
        $item   = $this->item('60.00');
        $saleId = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->assertSame(0, $this->accountingDb()->table('accounting_stock_entries')->where('source_id', $saleId)->where('item_id', $item)->countAllResults());

        $this->assertGreaterThan(0, $this->sales->confirm_delivery($saleId, $this->employeeId));

        $this->assertSame(1, $this->accountingDb()->table('accounting_stock_entries')->where('source_id', $saleId)->where('item_id', $item)->where('source_type', 'sale')->countAllResults());
    }

    public function testConfirmDelivery_TwoRequestsAtTheSameTimeTakeTheStockAndPostTheCostOnce(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, false);
        $stock  = $this->stock($cycle['cream']);

        [$first, $second] = $this->raceTwoProcesses('confirmDelivery', [$saleId, $this->employeeId], [$saleId, $this->employeeId]);

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertEqualsWithDelta($stock - 4, $this->stock($cycle['cream']), 0.0001);
        $this->assertCount(1, $this->standing($saleId, 'cogs'));
        $this->assertCount(1, array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['entry'] === 'cogs'));
        $this->assertSame('400.00', $this->balance($this->accountId('cogs')));
    }

    public function testDelete_TwoRequestsAtTheSameTimeGiveTheStockBackAndReverseTheSaleOnce(): void
    {
        $cycle  = $this->referenceInvoice();
        $stock  = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS);

        [$first, $second] = $this->raceTwoProcesses('cancelSale', [$saleId, $this->employeeId], [$saleId, $this->employeeId]);

        $this->assertTrue($first);
        $this->assertTrue($second);
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame([], $this->standing($saleId));
        $this->assertSame(3, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults());
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    // ---------------------------------------------------------------------------------------
    // The discount on the whole of a sale, taken after the discounts of its lines
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_PostsTheRevenueNetOfAPercentageDiscountOnTheWholeInvoiceAndTheTaxOnWhatIsLeft(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(
            [$this->customerAccount() . ':734.85:0.00', $this->accountId('sales_revenue') . ':0.00:639.00', $this->accountId('tax_output') . ':0.00:95.85'],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
        $this->assertSame([$this->accountId('cogs') . ':400.00:0.00', $this->stockAccount() . ':0.00:400.00'], $this->standing($saleId, 'cogs')[0]['lines'], 'the cost of the goods does not depend on what they were sold for');
        $this->assertSame('734.85', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_PostsTheRevenueNetOfAFixedDiscountOnTheWholeInvoiceAndTheTaxOnWhatIsLeft(): void
    {
        $cycle  = $this->discountedReferenceInvoice(false);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(
            [$this->customerAccount() . ':793.50:0.00', $this->accountId('sales_revenue') . ':0.00:690.00', $this->accountId('tax_output') . ':0.00:103.50'],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
    }

    public function testSaveValue_PostsNoJournalLineOfItsOwnForTheDiscountOnTheWholeInvoice(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $this->assertCount(3, $this->standing($saleId, 'revenue')[0]['lines']);
        $this->assertSame('0.00', $this->balance($this->accountId('sales_discount')));
    }

    public function testSaveValue_KeepsWhatWasEnteredOnTheSaleAndTheShareOfEachLineOnTheLine(): void
    {
        $cycle  = $this->discountedReferenceInvoice(false);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $sale   = $this->saleRow($saleId);

        $this->assertSame((string) FIXED, (string) $sale->invoice_discount_type);
        $this->assertSame('20.0000', $sale->invoice_discount);
        $this->assertSame(['0' => '7.6100', '1' => '12.3900'], $this->sharesOf($saleId));
    }

    public function testSaveValue_LeavesTheColumnsOfTheDiscountEmptyOnASaleWithoutOne(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertNull($this->saleRow($saleId)->invoice_discount_type);
        $this->assertNull($this->saleRow($saleId)->invoice_discount);
        $this->assertSame(['0' => null, '1' => null], $this->sharesOf($saleId));
    }

    public function testSaveValue_SavesNothingOfADiscountOfNothing(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, ['type' => FIXED, 'value' => '0.00']);

        $this->assertNull($this->saleRow($saleId)->invoice_discount_type);
        $this->assertSame(['0' => null, '1' => null], $this->sharesOf($saleId));
        $this->assertSame([$this->customerAccount() . ':816.50:0.00', $this->accountId('sales_revenue') . ':0.00:710.00', $this->accountId('tax_output') . ':0.00:106.50'], $this->standing($saleId, 'revenue')[0]['lines']);
    }

    public function testSaveValue_SavesTheDiscountAndPostsNothingWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame('10.0000', $this->saleRow($saleId)->invoice_discount);
        $this->assertSame(['0' => '27.0000', '1' => '44.0000'], $this->sharesOf($saleId));
        $this->assertSame([], $this->journals($saleId));
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_SavesTheDiscountOfASuspendedSaleAndPostsNothingUntilItIsCompleted(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);

        $this->assertSame('10.0000', $this->saleRow($saleId)->invoice_discount);
        $this->assertSame([], $this->journals($saleId));

        $this->assertSame($saleId, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $saleId, $cycle['discount']));
        $this->assertSame([$this->customerAccount() . ':734.85:0.00', $this->accountId('sales_revenue') . ':0.00:639.00', $this->accountId('tax_output') . ':0.00:95.85'], $this->standing($saleId, 'revenue')[0]['lines']);
    }

    public function testSaveValue_SavesASuspendedSaleWithTheSharesWorkedOutAndNotTheOnesTheRegisterCarried(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        $cycle['items'][0]['invoice_discount_share'] = '1.0000';
        $cycle['items'][1]['invoice_discount_share'] = '2.0000';

        $saleId = $this->save($cycle['items'], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(['0' => '27.0000', '1' => '44.0000'], $this->sharesOf($saleId));
    }

    public function testSaveValue_ReplacesTheDiscountOfASuspendedSaleWhenItIsSavedAgainWithAnother(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);

        $this->save($cycle['items'], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $saleId, ['type' => FIXED, 'value' => '20']);

        $this->assertSame((string) FIXED, (string) $this->saleRow($saleId)->invoice_discount_type);
        $this->assertSame(['0' => '7.6100', '1' => '12.3900'], $this->sharesOf($saleId));

        $this->save($cycle['items'], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $saleId);

        $this->assertNull($this->saleRow($saleId)->invoice_discount_type);
        $this->assertSame(['0' => null, '1' => null], $this->sharesOf($saleId));
    }

    /**
     * @return array<string, array{int, string, InvoiceDiscountProblem}>
     */
    public static function refusedDiscountProvider(): array
    {
        return [
            'more than the lines come to'    => [FIXED, '710.01', InvoiceDiscountProblem::ExceedsTotal],
            'a percentage above a hundred'   => [PERCENT, '100.5', InvoiceDiscountProblem::PercentOver100],
            'a negative amount'              => [FIXED, '-1', InvoiceDiscountProblem::Negative],
            'text instead of a number'       => [FIXED, 'ten', InvoiceDiscountProblem::NotANumber],
            'a kind nobody knows'            => [9, '5', InvoiceDiscountProblem::UnknownType],
        ];
    }

    #[DataProvider('refusedDiscountProvider')]
    public function testSaveValue_RefusesADiscountOnTheWholeInvoiceThatCannotBeTakenOffAndLeavesNothingOfTheSale(int $type, string $value, InvoiceDiscountProblem $problem): void
    {
        $cycle  = $this->referenceInvoice();
        $sales  = $this->countSales();
        $stock  = $this->stock($cycle['cream']);

        $this->assertSame(INVOICE_DISCOUNT_INVALID, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, ['type' => $type, 'value' => $value]));
        $this->assertSame($problem, $this->sales->invoiceDiscountProblem());
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
    }

    public function testSaveValue_AcceptsADiscountOfExactlyWhatTheLinesComeTo(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, ['type' => FIXED, 'value' => '710']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(['0' => '270.0000', '1' => '440.0000'], $this->sharesOf($saleId));
        $this->assertSame([], $this->standing($saleId, 'revenue'), 'a sale that comes to nothing has no invoice journal');
    }

    public function testSaveValue_RefusesASaleWhoseTaxesWereWorkedOutWithAnotherShareThanTheOneSaved(): void
    {
        $cycle = $this->discountedReferenceInvoice();
        $sales = $this->countSales();

        $cycle['items'][0]['invoice_discount_share'] = '26.0000';
        $cycle['items'][1]['invoice_discount_share'] = '44.0000';

        $this->assertSame(INVOICE_DISCOUNT_INVALID, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));
        $this->assertSame(InvoiceDiscountProblem::Changed, $this->sales->invoiceDiscountProblem());
        $this->assertSame($sales, $this->countSales());
    }

    public function testSaveValue_AcceptsASaleWhoseTaxesWereWorkedOutWithTheShareSaved(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        $cycle['items'][0]['invoice_discount_share'] = '27.0000';
        $cycle['items'][1]['invoice_discount_share'] = '44.0000';

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));
    }

    public function testSaveValue_RefusesADiscountOnAReturnWhichTakesBackItsShareOfTheSaleItComesFrom(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $sales     = $this->countSales();

        $this->assertSame(INVOICE_DISCOUNT_INVALID, $this->save([$this->line($cycle['cream'], 0, '-1', '75', '40.00', '10')], [], [], SALE_TYPE_RETURN, -1, true, $invoiceId, COMPLETED, NEW_ENTRY, ['type' => FIXED, 'value' => '5']));
        $this->assertSame(InvoiceDiscountProblem::OnReturn, $this->sales->invoiceDiscountProblem());
        $this->assertSame($sales, $this->countSales());
    }

    public function testSaveValue_TakesBackTheShareOfTheDiscountOfItsUnitsInAReturnOfADiscountedInvoice(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $returnId  = $this->save([$this->line($cycle['cream'], 0, '-1', '75', '40.00', '10')], [$this->tax($cycle['cream'], 0, '-9.1125')], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame(['0' => '-6.7500'], $this->sharesOf($returnId));
        $this->assertSame((string) FIXED, (string) $this->saleRow($returnId)->invoice_discount_type);
        $this->assertSame('6.7500', $this->saleRow($returnId)->invoice_discount);

        $this->assertSame(
            [$this->accountId('sales_returns') . ':60.74:0.00', $this->accountId('tax_output') . ':9.11:0.00', $this->customerAccount() . ':0.00:69.86'],
            $this->standing($returnId, 'revenue')[0]['lines'],
        );
        $this->assertSame('664.99', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_GivesBackExactlyTheDiscountItWasGivenInTheReturnsOfADiscountedInvoice(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $given     = '0';

        foreach ([['1', '-9.1125'], ['1', '-9.1125'], ['2', '-18.2250']] as [$units, $tax]) {
            $returnId = $this->save([$this->line($cycle['cream'], 0, '-' . $units, '75', '40.00', '10')], [$this->tax($cycle['cream'], 0, $tax)], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

            $this->assertGreaterThan(0, $returnId);

            $given = bcadd($given, $this->sharesOf($returnId)['0'], 4);
        }

        $this->assertSame('-27.0000', $given, 'the three returns of the creams give back the whole of their share, to the last fraction of a cent');
    }

    public function testSaveValue_RefusesAReturnWhoseTaxesWereWorkedOutWithAnotherShareThanTheOneItTakesBack(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $stale = $this->line($cycle['cream'], 0, '-1', '75', '40.00', '10');
        $stale['invoice_discount_share'] = '-6.0000';
        $sales = $this->countSales();

        $this->assertSame(INVOICE_DISCOUNT_INVALID, $this->save([$stale], [$this->tax($cycle['cream'], 0, '-9.2250')], [], SALE_TYPE_RETURN, -1, true, $invoiceId));
        $this->assertSame(InvoiceDiscountProblem::Changed, $this->sales->invoiceDiscountProblem());
        $this->assertSame($sales, $this->countSales());

        $stale['invoice_discount_share'] = '-6.7500';

        $this->assertGreaterThan(0, $this->save([$stale], [$this->tax($cycle['cream'], 0, '-9.1125')], [], SALE_TYPE_RETURN, -1, true, $invoiceId));
    }

    public function testSaveValue_TakesBackNoShareInAReturnOfASaleWithoutADiscount(): void
    {
        $cycle     = $this->referenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes']);
        $returnId  = $this->save([$this->line($cycle['cream'], 0, '-1', '75', '40.00', '10')], [$this->tax($cycle['cream'], 0, '-10.125')], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $this->assertGreaterThan(0, $returnId);
        $this->assertNull($this->saleRow($returnId)->invoice_discount_type);
        $this->assertSame(['0' => null], $this->sharesOf($returnId));
    }

    public function testSaveValue_WorksOutTheShareOfAReturnOnlyAfterTheSaleItComesFromIsLocked(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '2', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '199.99')], SALE_TYPE_POS, null, true, null, COMPLETED, NEW_ENTRY, ['type' => FIXED, 'value' => '0.01']);

        $this->assertGreaterThan(0, $invoice);

        $db = $this->accountingDb();
        $db->transBegin();
        $db->query('SELECT sale_id FROM ' . $db->prefixTable('sales') . ' WHERE sale_id = ? FOR UPDATE', [$invoice]);

        try {
            $request = $this->startWorkerRequest('returnSale', (string) $invoice, (string) $item, '-1', '60.00', '100', '0', (string) $this->employeeId, lang('Sales.cash'), '-100.00');

            $this->waitUntilARequestWaitsForARowLock();

            $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '-99.99')], SALE_TYPE_RETURN, null, true, $invoice));
        } finally {
            $db->transCommit();
        }

        $finished = $this->finishWorkerRequest($request);
        $returns  = $db->table('sales')->where('return_of_sale_id', $invoice)->orderBy('sale_id', 'ASC')->get()->getResult();

        foreach ($returns as $return) {
            $this->saleIds[] = (int) $return->sale_id;
        }

        $this->assertSame('1', $finished['output'], $finished['error']);
        $this->assertCount(2, $returns);

        $taken = '0';

        foreach ($returns as $return) {
            $taken = bcadd($taken, ltrim((string) (array_values($this->sharesOf((int) $return->sale_id))[0] ?? '0'), '-'), 4);
        }

        $this->assertSame('0.0100', $taken, 'the return that waited found the share the other took and took no more of the discount');
        $this->assertSame('199.99', $this->balance($this->accountId('sales_returns')));
    }

    public function testUpdate_LeavesTheInvoiceJournalAsItWasWhenAPaymentIsAddedToADiscountedInvoice(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $before = $this->standing($saleId, 'revenue');

        $this->addPayment($saleId, '300.00');

        $this->assertSame($before, $this->standing($saleId, 'revenue'));
        $this->assertSame('434.85', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testPostCompleted_WritesNothingMoreWhenRunAgainOnADiscountedSale(): void
    {
        $cycle    = $this->discountedReferenceInvoice();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $journals = $this->countJournals();

        (new SalePosting())->postCompleted($saleId, $this->employeeId);

        $this->assertSame($journals, $this->countJournals());
    }

    public function testDelete_ReversesTheJournalsOfADiscountedSaleAndLeavesNothingOnTheAccounts(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $before = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $this->assertSame([], $this->standing($saleId));
        $this->assertEqualsWithDelta($before, $this->stock($cycle['cream']), 0.0001);

        foreach ([$this->customerAccount(), $this->accountId('sales_revenue'), $this->accountId('tax_output'), $this->accountId('cogs'), $this->stockAccount(), $this->accountId('cash')] as $accountId) {
            $this->assertSame('0.00', $this->balance($accountId), (string) $accountId);
        }
    }

    public function testDelete_PostsTheSameAmountsInANewRevisionWhenARestoredDiscountedSaleIsCompletedAgain(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $first  = $this->standing($saleId, 'revenue')[0]['lines'];

        $this->sales->delete($saleId, false, true, $this->employeeId);
        $this->sales->restore_list([$saleId], $this->employeeId);

        $this->assertSame($saleId, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS, -1, true, null, COMPLETED, $saleId, $cycle['discount']));
        $this->assertSame($first, $this->standing($saleId, 'revenue')[0]['lines']);
        $this->assertSame(2, $this->standing($saleId, 'revenue')[0]['revision']);
        $this->assertSame('734.85', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_RefusesADiscountedInvoiceInAClosedPeriodWithoutLeavingAnyOfIt(): void
    {
        $cycle = $this->discountedReferenceInvoice();
        $this->closeThrough(date('Y-m-d'));

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame(0, $this->countJournals());
    }

    /**
     * @return list<object>
     */
    private function discountAuditOf(int $saleId): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => 'invoice_discount_given'])->get()->getResult();
    }

    public function testSaveValue_WritesInTheAuditLogThatTheSaleWasGivenADiscountByWhomAndForHowMuch(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $audit  = $this->discountAuditOf($saleId);

        $this->assertCount(1, $audit);
        $this->assertSame((string) $this->employeeId, (string) $audit[0]->employee_id);
        $this->assertSame((string) $this->saleRow($saleId)->branch_id, (string) $audit[0]->branch_id);
        $this->assertSame(['type' => 'percent', 'value' => '10', 'amount' => '71.0000'], json_decode($audit[0]->details, true));
    }

    public function testSaveValue_WritesNothingInTheAuditLogForASaleWithoutADiscountAndForAReturn(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $plain     = $this->save($cycle['items'], $cycle['taxes']);
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $returnId  = $this->save([$this->line($cycle['cream'], 0, '-1', '75', '40.00', '10')], [$this->tax($cycle['cream'], 0, '-9.1125')], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame([], $this->discountAuditOf($plain));
        $this->assertSame([], $this->discountAuditOf($returnId));
        $this->assertCount(1, $this->discountAuditOf($invoiceId));
    }

    public function testSaveValue_WritesNothingInTheAuditLogForASaleThatWasRefused(): void
    {
        $cycle = $this->discountedReferenceInvoice();
        $this->closeThrough(date('Y-m-d'));

        $before = $this->accountingDb()->table('accounting_audit_log')->where('action', 'invoice_discount_given')->countAllResults();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']));
        $this->assertSame($before, $this->accountingDb()->table('accounting_audit_log')->where('action', 'invoice_discount_given')->countAllResults());
    }

    public function testSaveValue_WritesNothingInTheAuditLogWhileAccountingIsOffOrForASuspendedSale(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $suspended = $this->save($cycle['items'], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);

        $this->setConfigValue('accounting_enabled', '0');

        $unposted = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $this->assertSame([], $this->discountAuditOf($suspended));
        $this->assertSame([], $this->discountAuditOf($unposted));
    }

    public function testSaveValue_PostsTheJournalsOfADiscountedInvoiceInTheBranchOfTheSale(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $branches = array_unique(array_column($this->accountingDb()->table('accounting_journals')->select('branch_id')->whereIn('journal_id', array_column($this->journals($saleId), 'id'))->get()->getResultArray(), 'branch_id'));

        $this->assertSame([(string) $this->saleRow($saleId)->branch_id], array_values($branches));
    }

    public function testSaveValue_TiesTheDiscountedInvoiceToTheCustomerAccountAndTheOpenInvoicesOfTheCustomer(): void
    {
        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertTies();
        $this->assertSame('434.85', bcadd($this->position()->open(), '0', 2));
    }

    /**
     * What the sales report says of one sale: its subtotal, tax and total, as the lines of the report add up.
     *
     * @return array{subtotal: string, tax: string, total: string}
     */
    private function reportOf(int $saleId): array
    {
        $this->dropReportTables();
        $this->sales->create_temp_table(['sale_id' => $saleId]);

        $row = $this->accountingDb()->query('SELECT SUM(subtotal) AS subtotal, SUM(tax) AS tax, SUM(total) AS total FROM ' . $this->accountingDb()->prefixTable('sales_items_temp') . ' WHERE sale_id = ?', [$saleId])->getRow();

        $this->dropReportTables();

        return ['subtotal' => bcadd((string) $row->subtotal, '0', 2), 'tax' => bcadd((string) $row->tax, '0', 2), 'total' => bcadd((string) $row->total, '0', 2)];
    }

    public function testSaveValue_TiesTheSalesReportOfADiscountedInvoiceAndOfItsReturnToTheAccountsOfTheBooks(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $returnId  = $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-29.70')], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $this->assertGreaterThan(0, $returnId);

        $invoice = $this->reportOf($invoiceId);
        $return  = $this->reportOf($returnId);

        $this->assertSame(['subtotal' => '639.00', 'tax' => '95.85', 'total' => '734.85'], $invoice);
        $this->assertSame(['subtotal' => '-198.00', 'tax' => '-29.70', 'total' => '-227.70'], $return);
        $this->assertSame($invoice['subtotal'], bcsub('0', $this->balance($this->accountId('sales_revenue')), 2), 'the report of the invoice is the revenue account');
        $this->assertSame(bcsub('0', $return['subtotal'], 2), $this->balance($this->accountId('sales_returns')), 'the report of the return is the returns account');
        $this->assertSame(bcsub('0', bcadd($invoice['tax'], $return['tax'], 2), 2), $this->balance($this->accountId('tax_output')));
        $this->assertSame(bcadd($invoice['total'], $return['total'], 2), $this->balance($this->customerAccount()));
    }

    public function testSaveValue_TiesTheSalesReportOfAnInvoiceWhoseDiscountDoesNotShareOutEvenlyOverItsLinesToTheLastCent(): void
    {
        $items = [];
        $taxes = [];
        $first = $this->item('10.00');

        foreach ([0 => [$first, '14.5005'], 1 => [$this->item('10.00'), '14.5005'], 2 => [$this->item('10.00'), '14.4990']] as $line => [$item, $tax]) {
            $items[] = $this->line($item, $line, '1', '100', '10.00');
            $taxes[] = $this->tax($item, $line, $tax);
        }

        $invoiceId = $this->save($items, $taxes, [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, ['type' => FIXED, 'value' => '10']);

        $this->assertGreaterThan(0, $invoiceId);
        $this->assertSame(['0' => '3.3300', '1' => '3.3300', '2' => '3.3400'], $this->sharesOf($invoiceId));
        $this->assertSame(['subtotal' => '290.00', 'tax' => '43.50', 'total' => '333.50'], $this->reportOf($invoiceId));
        $this->assertSame('290.00', bcsub('0', $this->balance($this->accountId('sales_revenue')), 2));
        $this->assertSame('43.50', bcsub('0', $this->balance($this->accountId('tax_output')), 2));
        $this->assertSame('333.50', $this->balance($this->customerAccount()));

        $returnId = $this->save([$this->line($first, 0, '-1', '100', '10.00')], [$this->tax($first, 0, '-14.50')], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame(['0' => '-3.3300'], $this->sharesOf($returnId));
        $this->assertSame(['subtotal' => '-96.67', 'tax' => '-14.50', 'total' => '-111.17'], $this->reportOf($returnId));
        $this->assertSame('96.67', $this->balance($this->accountId('sales_returns')));
    }

    public function testSaveValue_KeepsTheSalesReportOfASaleWithoutADiscountAsItWasBefore(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame(['subtotal' => '710.00', 'tax' => '106.50', 'total' => '816.50'], $this->reportOf($saleId));
    }

    // ---------------------------------------------------------------------------------------
    // Against the reference system: fixtures T-06 to T-09
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function cycleProvider(): array
    {
        return ParityFixture::cases('T-06', 'cycle');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function returnCostProvider(): array
    {
        return ParityFixture::cases('T-08', 'return_cost');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cycleProvider')]
    public function testSaveValue_PostsEveryJournalOfTheReferenceCycleWithTheLinesTheReferenceSystemWrote(array $case): void
    {
        $this->assertReferenceCycle($case, null);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cycleProvider')]
    public function testSaveValue_PostsEveryJournalOfTheReferenceCycleTheSameWhenTheDiscountOnTheWholeInvoiceIsZero(array $case): void
    {
        $this->assertReferenceCycle($case, ['type' => PERCENT, 'value' => '0']);
    }

    /**
     * @param array<string, mixed>                 $case
     * @param array{type: int, value: string}|null $invoiceDiscount
     */
    private function assertReferenceCycle(array $case, ?array $invoiceDiscount): void
    {
        $this->assertSame('exact', $case['classification'], $case['id']);
        $this->assertSame(
            array_column($case['reference']['journals'], 'lines', 'step'),
            array_column($case['expected']['journals'], 'lines', 'step'),
            'a case recorded as exact must expect what the reference system wrote',
        );

        $invoice = $case['inputs']['invoice'];
        $note    = $case['inputs']['credit_note'];
        $items   = [];
        $taxes   = [];
        $ids     = [];

        foreach ($invoice['items'] as $line => $row) {
            $itemId          = $this->item($row['cost']);
            $ids[$row['name']] = $itemId;
            $items[]         = $this->line($itemId, $line, $row['quantity'], $row['price'], $row['cost'], $row['discount'], $row['discount_type'] === 'percent' ? PERCENT : FIXED);
            $taxes[]         = $this->tax($itemId, $line, $row['tax']);
        }

        $invoiceId = $this->save($items, $taxes, [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $invoiceDiscount);

        $this->accountingDb()->table('items')->where('item_id', $ids[$note['item']])->update(['cost_price' => $note['cost_of_the_item_now']]);

        $returnId = $this->save(
            [$this->line($ids[$note['item']], 0, '-' . $note['quantity'], $note['price'], $note['cost_of_the_item_now'])],
            [$this->tax($ids[$note['item']], 0, '-' . $note['tax'])],
            [],
            SALE_TYPE_RETURN,
            -1,
            true,
            $invoiceId,
        );

        $types    = ['cash' => lang('Sales.cash'), 'check' => lang('Sales.check'), 'bank_transfer' => lang('Sales.bank_transfer')];
        $codes    = ['cash' => null, 'check' => 'CHQ1234', 'bank_transfer' => 'TRF1234'];
        $payments = array_map(
            fn (array $payment): array => ['payment_id' => NEW_ENTRY, 'payment_type' => $types[$payment['type']], 'payment_amount' => $payment['amount'], 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => $codes[$payment['type']]],
            $case['inputs']['payments'],
        );

        $this->assertGreaterThan(0, $invoiceId);
        $this->assertGreaterThan(0, $returnId);
        $this->assertTrue($this->sales->update($invoiceId, ['comment' => 'paid', 'payments' => $payments]));

        $posted = [
            'invoice'          => $this->standing($invoiceId, 'revenue')[0],
            'invoice_cost'     => $this->standing($invoiceId, 'cogs')[0],
            'credit_note'      => $this->standing($returnId, 'revenue')[0],
            'credit_note_cost' => $this->standing($returnId, 'cogs')[0],
        ];

        foreach (array_values(array_filter($this->standing($invoiceId), static fn (array $journal): bool => $journal['source'] === 'sale_payment')) as $index => $journal) {
            $posted['payment_' . ($index + 1)] = $journal;
        }

        $this->assertSame(array_column($case['expected']['journals'], 'step'), array_keys($posted));
        $this->assertSame(count($case['expected']['journals']), count($this->journals($invoiceId)) + count($this->journals($returnId)));

        foreach ($case['expected']['journals'] as $expected) {
            $this->assertSame($expected['entry'], $posted[$expected['step']]['entry'], $expected['step']);
            $this->assertSame($this->shapeOfRoles($expected['lines']), $this->sortedLines($posted[$expected['step']]['lines']), $expected['step']);
        }

        $this->assertSame($case['expected']['customer_balance'], $this->balance($this->customerAccount()));
        $this->assertSame($case['reference']['customer_balance'], $this->balance($this->customerAccount()));
    }

    public function testSaveValue_DraftThenIssuePostsNothingUntilTheIssueAndThenTheInvoiceAndTheCost(): void
    {
        $case  = $this->lifecycleCase('draft-then-issue');
        $cycle = $this->referenceInvoice();
        $stock = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertSame($case['expected']['after_saving']['journals'], count($this->journals($saleId)));
        $this->assertSame($case['expected']['after_saving']['stock_taken'], $this->stockTaken($cycle['cream'], $stock));

        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $saleId);

        $this->assertEqualsCanonicalizing($case['expected']['after_issuing']['journals'], array_column($this->standing($saleId), 'entry'));
        $this->assertSame($case['expected']['after_issuing']['stock_taken'], $this->stockTaken($cycle['cream'], $stock));
    }

    public function testSaveValue_AnInvoiceIssuedBeforeItsGoodsLeavePostsOnlyTheRevenueAndTakesNoStock(): void
    {
        $case   = $this->lifecycleCase('undelivered-invoice');
        $cycle  = $this->referenceInvoice();
        $stock  = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, $case['inputs']['delivered']);

        $this->assertEqualsCanonicalizing($case['expected']['journals'], array_column($this->standing($saleId), 'entry'));
        $this->assertSame($case['expected']['stock_taken'], $this->stockTaken($cycle['cream'], $stock));
    }

    public function testConfirmDelivery_PostsTheCostAndTakesTheStockAfterTheInvoiceWasIssuedFirst(): void
    {
        $case   = $this->lifecycleCase('confirm-delivery');
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, $case['inputs']['delivered']);
        $stock  = $this->stock($cycle['cream']);

        $this->assertSame($saleId, $this->sales->confirm_delivery($saleId, $this->employeeId));

        $this->assertEqualsCanonicalizing($case['expected']['journals'], array_column($this->standing($saleId), 'entry'));
        $this->assertSame($case['expected']['stock_taken'], $this->stockTaken($cycle['cream'], $stock));
    }

    public function testConfirmDelivery_DatesTheCostOnTheDayOfTheInvoiceAtTheCostOfTheDelivery(): void
    {
        $case   = $this->lifecycleCase('cost-on-the-day-of-the-invoice-at-the-cost-of-the-delivery');
        $inputs = $case['inputs'];
        $item   = $this->item($inputs['cost_at_invoice']);
        $saleId = $this->save([$this->line($item, 0, $inputs['quantity'], '100', $inputs['cost_at_invoice'])], [], [], SALE_TYPE_INVOICE, -1, false);
        $day    = date('Y-m-d', strtotime($inputs['invoice_date_offset_days'] . ' days'));

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $day . ' 10:00:00']);
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => $inputs['cost_at_delivery']]);

        $this->assertSame($saleId, $this->sales->confirm_delivery($saleId, $this->employeeId));

        $cost = $this->standing($saleId, 'cogs');

        $this->assertCount(1, $cost);
        $this->assertSame($day, $cost[0]['date']);
        $this->assertSame([$this->accountId('cogs') . ':' . $case['expected']['cost'] . ':0.00', $this->stockAccount() . ':0.00:' . $case['expected']['cost']], $cost[0]['lines']);
    }

    public function testConfirmDelivery_TakesTheStockAndPostsNoCostWhenTheItemHasNeverBeenBought(): void
    {
        $case   = $this->lifecycleCase('delivery-before-the-first-purchase');
        $inputs = $case['inputs'];
        $item   = $this->item($inputs['cost_at_delivery']);
        $saleId = $this->save([$this->line($item, 0, $inputs['quantity'], '100', $inputs['cost_at_delivery'])], [], [], SALE_TYPE_INVOICE, -1, false);
        $stock  = $this->stock($item);

        $this->assertSame($saleId, $this->sales->confirm_delivery($saleId, $this->employeeId));

        $this->assertEqualsCanonicalizing($case['expected']['journals'], array_column($this->standing($saleId), 'entry'));
        $this->assertSame($case['expected']['stock_taken'], $this->stockTaken($item, $stock));
        $this->assertEqualsWithDelta($stock - (float) $inputs['quantity'], $this->stock($item), 0.0001);
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('returnCostProvider')]
    public function testSaveValue_CostsAReturnAtTheCostTheGoodsWereSoldAtWhateverTheCostIsNow(array $case): void
    {
        $this->assertSame('exact', $case['classification'], $case['id']);
        $this->assertSame($case['reference'], $case['expected'], $case['id']);

        $sold     = $case['inputs']['sold'];
        $returned = $case['inputs']['returned'];
        $item     = $this->item($sold['cost']);
        $invoice  = $this->save([$this->line($item, 0, $sold['quantity'], '100', $sold['cost'])]);

        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => $case['inputs']['cost_of_the_item_now']]);

        $returnId = $this->save([$this->line($item, 0, '-' . $returned['quantity'], '100', $case['inputs']['cost_of_the_item_now'])], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame(
            [$this->stockAccount() . ':' . $case['expected']['cost_journal'] . ':0.00', $this->accountId('cogs') . ':0.00:' . $case['expected']['cost_journal']],
            $this->standing($returnId, 'cogs')[0]['lines'],
            $case['id'],
        );
    }

    public function testSaveValue_KeepsOnlyWhatWasKeptOfAnOverpaymentWhereTheReferenceSystemKeepsTheWholePayment(): void
    {
        $case  = $this->behaviourCase('overpayment-at-the-register-gives-the-change-back', 'differs');
        $total = $case['inputs']['invoice_total'];
        $paid  = $case['inputs']['payment'];
        $item  = $this->item('100.00');
        $saleId = $this->save([$this->line($item, 0, '1', $total, '100.00')], [], [$this->payment(lang('Sales.cash'), $paid, bcsub($paid, $total, 2))]);

        $payments = array_values(array_filter($this->standing($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));
        $kept     = $case['expected']['payment_journal'];

        $this->assertCount(1, $payments);
        $this->assertSame([$this->accountId('cash') . ':' . $kept . ':0.00', $this->customerAccount() . ':0.00:' . $kept], $payments[0]['lines']);
        $this->assertSame($case['expected']['customer_balance'], $this->balance($this->customerAccount()));
        $this->assertNotSame($case['reference']['payment_journal'], $kept);
        $this->assertNotSame($case['reference']['customer_balance'], $case['expected']['customer_balance']);
    }

    public function testDelete_RefusesToCancelAnIssuedInvoiceAsTheReferenceSystemDoes(): void
    {
        $case   = $this->behaviourCase('an-issued-invoice-cannot-be-deleted', 'exact');
        $cycle  = $this->referenceInvoice();
        $stock  = $this->stock($cycle['cream']);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $before = $this->journals($saleId);

        $this->assertSame($case['reference'], $case['expected']);
        $this->assertSame('refused', $case['expected']['result']);
        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(AccountingError::IssuedDocumentLocked, $this->failure()->error);
        $this->assertSame($before, $this->journals($saleId));
        $this->assertEqualsWithDelta($stock - 4, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame('516.50', $this->balance($this->customerAccount()));
    }

    public function testDelete_RefusesToCancelACreditNoteAsTheReferenceSystemDoes(): void
    {
        $cycle    = $this->referenceInvoice();
        $invoice  = $this->save($cycle['items'], $cycle['taxes']);
        $returnId = $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $before   = $this->journals($returnId);

        $this->assertFalse($this->sales->delete($returnId, false, true, $this->employeeId));
        $this->assertSame(AccountingError::IssuedDocumentLocked, $this->failure()->error);
        $this->assertSame($before, $this->journals($returnId));
    }

    public function testDelete_StillCancelsAPointOfSaleSaleWhichTheReferenceSystemHasNoCounterpartFor(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame([], $this->standing($saleId));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testUpdate_RefusesToMoveAnIssuedInvoiceToAnotherCustomerOrDateAsTheReferenceSystemDoes(): void
    {
        $case   = $this->behaviourCase('an-issued-invoice-keeps-its-customer-and-its-date', 'exact');
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);
        $other  = $this->insertCustomer('Hind', 'Saad');

        $this->assertSame($case['reference'], $case['expected']);
        $this->assertFalse($this->sales->update($saleId, ['customer_id' => $other, 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);
        $this->assertFalse($this->sales->update($saleId, ['sale_time' => date('Y-m-d H:i:s', strtotime('-3 days')), 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);
        $this->assertSame((string) $this->customerId, (string) $this->saleRow($saleId)->customer_id);
    }

    public function testUpdate_ReversesTheJournalOfARemovedPaymentWhereTheReferenceSystemDeletesIt(): void
    {
        $case   = $this->behaviourCase('deleting-a-payment-removes-its-journal', 'differs');
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'removed', 'payments' => [
            ['payment_id' => (int) $row->payment_id, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $payments = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $row->payment_id])->get()->getResult();

        $this->assertSame('deleted', $case['reference']['journal']);
        $this->assertCount(1, $payments);
        $this->assertSame('reversed', $payments[0]->status);
        $this->assertSame('816.50', $this->balance($this->customerAccount()));
        $this->assertSame($case['reference']['invoice'], $case['expected']['invoice']);
    }

    public function testUpdate_IgnoresAPaymentStatusItDoesNotKnow(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'odd', 'payments' => [$this->editedPayment((int) $row->payment_id, '300.00', 'settled-by-magic')]]));
        $this->assertSame('completed', $this->accountingDb()->table('sales_payments')->where('payment_id', $row->payment_id)->get()->getRow()->payment_status);
        $this->assertSame('516.50', $this->balance($this->customerAccount()));
    }

    public function testUpdate_AddsAPaymentInTheStatusItIsGivenAndPostsNothingUntilItIsCompleted(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'pending', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.bank_transfer'), 'payment_amount' => '300.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => 'TRF1234', 'payment_status' => 'review'],
        ]]));

        $this->assertSame([], array_filter($this->standing($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));
        $this->assertSame('816.50', $this->balance($this->customerAccount()));
    }

    public function testUpdate_KeepsAPaymentThatIsNotCompletedOutOfTheBooksAsTheReferenceSystemDoes(): void
    {
        $case   = $this->behaviourCase('a-payment-status-decides-whether-it-has-a-journal', 'exact');
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')]);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();

        $this->assertSame($case['reference'], $case['expected']);
        $this->assertSame('516.50', $this->balance($this->customerAccount()));

        foreach (['review', 'failed', 'draft', 'incomplete'] as $status) {
            $this->assertTrue($this->sales->update($saleId, ['comment' => $status, 'payments' => [$this->editedPayment((int) $row->payment_id, '300.00', $status)]]));
            $this->assertSame('816.50', $this->balance($this->customerAccount()), $status);
            $this->assertSame([], array_filter($this->standing($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'), $status);
        }

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'back', 'payments' => [$this->editedPayment((int) $row->payment_id, '300.00', 'completed')]]));

        $payments = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment' && $journal['status'] === 'posted'));

        $this->assertCount(1, $payments);
        $this->assertSame(2, $payments[0]['revision']);
        $this->assertSame('516.50', $this->balance($this->customerAccount()));
    }

    // ---------------------------------------------------------------------------------------
    // The posting service
    // ---------------------------------------------------------------------------------------

    public function testJournalsOf_ListsTheJournalsOfTheSaleAndOfItsPaymentsOnly(): void
    {
        $cycle = $this->referenceInvoice();
        $one   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '100.00')]);
        $two   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '200.00')]);

        $posting = new SalePosting();

        $this->assertCount(3, $posting->journalsOf($one));
        $this->assertCount(3, $posting->journalsOf($two));
        $this->assertSame([], array_intersect(array_column($posting->journalsOf($one), 'journal_id'), array_column($posting->journalsOf($two), 'journal_id')));
    }

    public function testIsPosted_IsTrueOnlyWhileTheInvoiceJournalStands(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS);
        $posting = new SalePosting();

        $this->assertTrue($posting->isPosted($saleId));

        $this->sales->delete($saleId, false, true, $this->employeeId);

        $this->assertFalse($posting->isPosted($saleId));
    }

    public function testReverseAutomatic_ReversesOnlyTheJournalsOfSales(): void
    {
        $manual = $this->postManualJournal();

        $this->expectException(AccountingException::class);

        (new JournalService())->reverseAutomatic($manual, date('Y-m-d'), 'no', $this->employeeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function editedPayment(int $paymentId, string $amount, string $status, string $type = ''): array
    {
        return ['payment_id' => $paymentId, 'payment_type' => $type === '' ? lang('Sales.cash') : $type, 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'payment_status' => $status];
    }

    private function postManualJournal(): int
    {
        $draft = new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '10.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        );

        return (new PostingService())->post($draft)->journalId;
    }

    /**
     * @return array<string, mixed>
     */
    private function lifecycleCase(string $id): array
    {
        $case = ParityFixture::cases('T-07', 'lifecycle')[$id][0];

        $this->assertSame('exact', $case['classification'], $id);

        if ($case['scope'] === 'structure') {
            $this->assertSame($case['reference'], $case['expected'], $id);
        }

        return $case;
    }

    /**
     * @return array<string, mixed>
     */
    private function behaviourCase(string $id, string $classification): array
    {
        $case = ParityFixture::cases('T-09', 'behaviour')[$id][0];

        $this->assertSame($classification, $case['classification'], $id);

        if ($classification === 'differs') {
            $this->assertNotSame($case['reference'], $case['expected'], "{$id} is recorded as a difference and cannot expect what the reference system does");
        }

        return $case;
    }

    /**
     * The share each line of a sale carries, by line, as it was saved.
     *
     * @return array<string, ?string>
     */
    private function sharesOf(int $saleId): array
    {
        $shares = [];

        foreach ($this->accountingDb()->table('sales_items')->select('line, invoice_discount_share')->where('sale_id', $saleId)->orderBy('line', 'ASC')->get()->getResult() as $row) {
            $shares[(string) $row->line] = $row->invoice_discount_share;
        }

        return $shares;
    }

    private function stockTaken(int $itemId, float $before): bool
    {
        return $before - $this->stock($itemId) > 0.0001;
    }

    /**
     * @param list<array{string, string, string}> $lines Role, debit and credit of each line.
     *
     * @return list<string>
     */
    private function shapeOfRoles(array $lines): array
    {
        $accounts = [
            'customer'      => $this->customerAccount(),
            'sales_revenue' => $this->accountId('sales_revenue'),
            'sales_returns' => $this->accountId('sales_returns'),
            'tax_output'    => $this->accountId('tax_output'),
            'cogs'          => $this->accountId('cogs'),
            'inventory'     => $this->stockAccount(),
            'cash'          => $this->accountId('cash'),
        ];

        return $this->sortedLines(array_map(static fn (array $line): string => $accounts[$line[0]] . ':' . $line[1] . ':' . $line[2], $lines));
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function sortedLines(array $lines): array
    {
        sort($lines);

        return $lines;
    }

    // ---------------------------------------------------------------------------------------
    // A payment into or out of a treasury of another branch
    // ---------------------------------------------------------------------------------------

    /**
     * A second branch with a treasury of its own, and a seller who works in both branches.
     *
     * @param array<string, mixed> $more What else the treasury is created with.
     *
     * @return array{branch: int, treasury: int, account: int}
     */
    private function treasuryOfAnotherBranch(array $more = []): array
    {
        $branch   = $this->createBranch('B2');
        $treasury = (new TreasuryService())->create(['name' => 'Branch drawer', 'kind' => 'cash', 'branch_id' => $branch] + $more);

        $this->employeeId = $this->employeeWithGrants([], [1, $branch]);

        return ['branch' => $branch, 'treasury' => $treasury, 'account' => (int) (new TreasuryService())->accountOf($treasury)];
    }

    private function saleWithPaymentInto(int $treasury, string $amount = '100.00', int $type = SALE_TYPE_INVOICE): int
    {
        $item = $this->item('10.00');

        return $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), $amount) + ['treasury_id' => $treasury]], $type);
    }

    /**
     * The journals of the payments of a sale: standing ones by source, with their branch.
     *
     * @return array<string, array{branch: int, lines: list<string>, revision: int}>
     */
    private function paymentJournals(int $saleId, string $status = 'posted'): array
    {
        $found = [];

        foreach ($this->journals($saleId) as $journal) {
            if ($journal['status'] === $status && str_starts_with($journal['source'], 'sale_payment')) {
                $found[$journal['source']] = [
                    'branch'   => (int) $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal['id'])->get()->getRow()->branch_id,
                    'lines'    => $journal['lines'],
                    'revision' => $journal['revision'],
                ];
            }
        }

        return $found;
    }

    private function assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset(): void
    {
        foreach ($this->accountingDb()->table('branches')->get()->getResult() as $branch) {
            $sums = $this->accountingDb()->table('accounting_journal_lines l')->select('SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)->join('accounting_journals j', 'j.journal_id = l.journal_id')->where('j.branch_id', $branch->branch_id)->get()->getRow();

            $this->assertSame(bcadd((string) $sums->debit, '0', 4), bcadd((string) $sums->credit, '0', 4), 'The trial balance of branch ' . $branch->code . ' balances');
        }

        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }

    public function testSaveValue_PostsAPaymentIntoATreasuryOfAnotherBranchAsAJournalInEachBranch(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $saleId = $this->saleWithPaymentInto($treasury);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([
            'sale_payment'        => ['branch' => 1, 'lines' => [$this->accountId('branches_receivable') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'], 'revision' => 1],
            'sale_payment_branch' => ['branch' => $branch, 'lines' => [$account . ':100.00:0.00', $this->accountId('branches_payable') . ':0.00:100.00'], 'revision' => 1],
        ], $this->paymentJournals($saleId));
        $this->assertSame($branch, (int) $this->paymentRow($saleId)->treasury_branch_id);
        $this->assertSame('100.0000', (new TreasuryService())->position($treasury)->balance, 'The treasury holds what its account says');
        $this->assertSame('0.00', $this->balance($this->customerAccount()), 'The customer is settled in the branch of the sale');
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testSaveValue_SaysWhichBranchOwesWhichAfterAPaymentIntoATreasuryOfAnotherBranch(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->saleWithPaymentInto($treasury);

        $rows = [];

        foreach ((new InterBranchBalances())->report()->rows as $row) {
            $rows[$row->branchId . '>' . $row->counterpartId] = $row;
        }

        $this->assertSame(['100.0000', PairBalance::RECORDED, true], [$rows['1>' . $branch]->owed, $rows['1>' . $branch]->basis, $rows['1>' . $branch]->agrees], 'The branch of the sale is owed what the other holds for it');
        $this->assertSame('-100.0000', $rows[$branch . '>1']->owed);
    }

    public function testSaveValue_PostsAPaymentOfAnotherBranchOnlyWhenTheTreasuryIsNotOfTheBranchOfTheSale(): void
    {
        $this->treasuryOfAnotherBranch();

        $own    = (new TreasuryService())->create(['name' => 'Own drawer', 'kind' => 'cash']);
        $saleId = $this->saleWithPaymentInto($own);

        $this->assertNull($this->paymentRow($saleId)->treasury_branch_id);
        $this->assertSame(['sale_payment'], array_keys($this->paymentJournals($saleId)));
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
    }

    public function testUpdate_PostsARefundOutOfATreasuryOfAnotherBranchTheOtherWay(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $saleId = $this->saleWithPaymentInto($treasury);

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'refund', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '-30.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'treasury_id' => $treasury],
        ]]));

        $refundId = (int) $this->accountingDb()->table('sales_payments')->selectMax('payment_id', 'last')->where('sale_id', $saleId)->get()->getRow()->last;
        $journals = [];

        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['sale_payment', 'sale_payment_branch'])->where(['source_id' => $refundId, 'status' => 'posted'])->get()->getResult() as $journal) {
            $journals[$journal->source_type] = [
                (int) $journal->branch_id,
                array_map(
                    static fn (object $line): string => $line->account_id . ':' . bcadd((string) $line->debit, '0', 2) . ':' . bcadd((string) $line->credit, '0', 2),
                    $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
                ),
            ];
        }

        $this->assertSame([
            'sale_payment'        => [1, [$this->customerAccount() . ':30.00:0.00', $this->accountId('branches_payable') . ':0.00:30.00']],
            'sale_payment_branch' => [$branch, [$this->accountId('branches_receivable') . ':30.00:0.00', $account . ':0.00:30.00']],
        ], $journals, 'The branch of the sale now owes the other, which paid the customer back');
        $this->assertSame('70.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertSame('30.00', $this->balance($this->customerAccount()), 'The customer is owed the 30 back as a new debt of the sale');
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testSaveValue_RejectsATreasuryOfAnotherBranchToAnEmployeeWhoWorksInOnlyOneOfTheTwo(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([[1], [$branch]] as $works) {
            $this->employeeId = $this->employeeWithGrants([], $works);

            $before = [$this->countRows('sales'), $this->countRows('accounting_journals')];
            $result = $this->saleWithPaymentInto($treasury);

            $this->assertSame(ACCOUNTING_FAILED, $result);
            $this->assertSame(AccountingError::TreasuryNotFound, $this->sales->accountingFailure()->error);
            $this->assertSame($before, [$this->countRows('sales'), $this->countRows('accounting_journals')], 'Nothing of the sale was kept');
        }

        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
    }

    public function testSaveValue_AsksTheDepositScopeOfTheTreasuryOfTheOtherBranch(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch(['deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);

        $this->assertSame(ACCOUNTING_FAILED, $this->saleWithPaymentInto($treasury));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->sales->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('sales_payments'));
    }

    public function testSaveValue_RejectsATreasuryOfAnotherBranchThatIsDeactivated(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        (new TreasuryService())->deactivate($treasury);

        $this->assertSame(ACCOUNTING_FAILED, $this->saleWithPaymentInto($treasury));
        $this->assertSame(AccountingError::TreasuryInactive, $this->sales->accountingFailure()->error);
    }

    public function testSaveValue_LeavesNothingOfTheSaleWhenThePeriodOfEitherBranchIsClosed(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([$branch, 1] as $closed) {
            $this->closeThrough(date('Y-m-d'), $closed);

            $before = [$this->countRows('sales'), $this->countRows('accounting_journals'), $this->countRows('accounting_interbranch_legs')];

            $this->assertSame(ACCOUNTING_FAILED, $this->saleWithPaymentInto($treasury));
            $this->assertSame(PostingError::PeriodClosed, $this->sales->accountingFailure()->error);
            $this->assertSame($before, [$this->countRows('sales'), $this->countRows('accounting_journals'), $this->countRows('accounting_interbranch_legs')]);

            $this->accountingDb()->table('accounting_closed_periods')->truncate();
        }

        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
    }

    public function testSaveValue_LeavesNothingOfTheSaleWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.receivable')->update(['account_id' => null]);

        $this->assertSame(ACCOUNTING_FAILED, $this->saleWithPaymentInto($treasury));
        $this->assertSame(PostingError::InterBranchAccountsMissing, $this->sales->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('sales_payments'));
    }

    public function testSaveValue_WritesTheJournalOfTheLowerBranchFirstWhicheverBranchHoldsTheTreasury(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $home    = (new TreasuryService())->create(['name' => 'Home drawer', 'kind' => 'cash']);
        $store   = $this->insertStockLocation('Branch store');
        $service = $this->item('0', false);

        (new EntityAccountService())->ensure(EntityAccountService::STOCK_LOCATION, $store, 'Branch store');
        $this->accountingDb()->table('stock_locations')->where('location_id', $store)->update(['branch_id' => $branch]);

        $forward  = $this->saleWithPaymentInto($treasury);
        $backward = $this->save([$this->line($service, 0, '1', '100', '0', '0', PERCENT, $store)], [], [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $home]]);

        $this->assertGreaterThan(0, $backward);

        foreach ([$forward => 1, $backward => $branch] as $saleId => $documentBranch) {
            $ids = [];

            foreach ($this->journals($saleId) as $journal) {
                if (str_starts_with($journal['source'], 'sale_payment')) {
                    $ids[(int) $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal['id'])->get()->getRow()->branch_id] = $journal['id'];
                }
            }

            $this->assertSame([1, $branch], array_keys($ids), 'The sale of branch ' . $documentBranch);
            $this->assertLessThan($ids[$branch], $ids[1], 'The journal of the lower branch is written first');
        }

        $this->assertSame('sale_payment_branch', $this->accountingDb()->table('accounting_journals')->where('source_id', (int) $this->paymentRow($backward)->payment_id)->where('branch_id', 1)->whereIn('source_type', ['sale_payment', 'sale_payment_branch'])->get()->getRow()->source_type);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_ReversesBothJournalsAndPostsThePairAgainWhenThePaymentChanges(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $saleId    = $this->saleWithPaymentInto($treasury);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'less', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '60.00', 'treasury_id' => $treasury])]]));

        $this->assertSame([
            'sale_payment'        => ['branch' => 1, 'lines' => [$this->accountId('branches_receivable') . ':60.00:0.00', $this->customerAccount() . ':0.00:60.00'], 'revision' => 2],
            'sale_payment_branch' => ['branch' => $branch, 'lines' => [$account . ':60.00:0.00', $this->accountId('branches_payable') . ':0.00:60.00'], 'revision' => 2],
        ], $this->paymentJournals($saleId));
        $this->assertSame(['sale_payment', 'sale_payment_branch'], array_keys($this->paymentJournals($saleId, 'reversed')));
        $this->assertSame('60.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertSame('40.00', $this->balance($this->customerAccount()));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_LeavesThePairAloneWhenNothingAboutThePaymentChanges(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId    = $this->saleWithPaymentInto($treasury);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;
        $before    = $this->countRows('accounting_journals');

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'again', 'payments' => [$this->scopeEditedPayment($paymentId, ['treasury_id' => $treasury])]]));
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'and again', 'payments' => [$this->scopeEditedPayment($paymentId)]]));

        $this->assertSame($before, $this->countRows('accounting_journals'));
        $this->assertSame([1, 1], array_values(array_map(static fn (array $journal): int => $journal['revision'], $this->paymentJournals($saleId))));
    }

    public function testUpdate_MovesAPaymentFromATreasuryOfAnotherBranchToOneOfItsOwnAndBack(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $own       = (new TreasuryService())->create(['name' => 'Own drawer', 'kind' => 'cash']);
        $saleId    = $this->saleWithPaymentInto($treasury);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'moved', 'payments' => [$this->scopeEditedPayment($paymentId, ['treasury_id' => $own])]]));

        $this->assertSame(['sale_payment'], array_keys($this->paymentJournals($saleId)));
        $this->assertNull($this->paymentRow($saleId)->treasury_branch_id);
        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertSame('100.0000', (new TreasuryService())->position($own)->balance);

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'moved back', 'payments' => [$this->scopeEditedPayment($paymentId, ['treasury_id' => $treasury])]]));

        $this->assertSame(['sale_payment', 'sale_payment_branch'], array_keys($this->paymentJournals($saleId)));
        $this->assertSame($branch, (int) $this->paymentRow($saleId)->treasury_branch_id);
        $this->assertSame(3, $this->paymentJournals($saleId)['sale_payment']['revision']);
        $this->assertSame('100.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertSame('0.0000', (new TreasuryService())->position($own)->balance);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_TakesBothJournalsBackWhenThePaymentIsRemoved(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId    = $this->saleWithPaymentInto($treasury);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'removed', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '0.00'])]]));

        $this->assertSame([], $this->paymentJournals($saleId));
        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testUpdate_RejectsAnEmployeeWhoWorksInOnlyOneOfTheBranchesToChangeAPaymentAcrossThemAndChangesNothing(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId    = $this->saleWithPaymentInto($treasury);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->employeeId = $this->employeeWithGrants([], [1]);

        $before = $this->countRows('accounting_journals');

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'less', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '60.00', 'treasury_id' => $treasury])]]));
        $this->assertSame(AccountingError::InterBranchBranchesDenied, $this->sales->accountingFailure()->error);
        $this->assertSame($before, $this->countRows('accounting_journals'));
        $this->assertSame('100.00', $this->paymentRow($saleId)->payment_amount);
        $this->assertSame('100.0000', (new TreasuryService())->position($treasury)->balance);
    }

    public function testUpdate_AsksTheWithdrawScopeOfTheTreasuryOfTheOtherBranchToTakeAPaymentOutOfIt(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId    = $this->saleWithPaymentInto($treasury);
        $paymentId = (int) $this->paymentRow($saleId)->payment_id;

        $this->accountingDb()->table('treasuries')->where('treasury_id', $treasury)->update(['withdraw_scope' => 'staff']);
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $treasury, 'direction' => 'withdraw', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'less', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '60.00', 'treasury_id' => $treasury])]]));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame('100.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertSame(['sale_payment', 'sale_payment_branch'], array_keys($this->paymentJournals($saleId)));
    }

    public function testDelete_ReversesBothJournalsOfAPaymentIntoATreasuryOfAnotherBranch(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId = $this->saleWithPaymentInto($treasury, '100.00', SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $this->assertSame([], $this->paymentJournals($saleId));
        $this->assertSame(['sale_payment', 'sale_payment_branch'], array_keys($this->paymentJournals($saleId, 'reversed')));
        $this->assertSame('0.0000', (new TreasuryService())->position($treasury)->balance);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testDelete_ReversesTheJournalOfTheLowerBranchFirst(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId = $this->saleWithPaymentInto($treasury, '100.00', SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $reversals = [];

        foreach ($this->journals($saleId) as $journal) {
            if (str_starts_with($journal['source'], 'sale_payment')) {
                $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journal['id'])->get()->getRow();

                $reversals[(int) $reversal->branch_id] = (int) $reversal->journal_id;
            }
        }

        $this->assertLessThan($reversals[$branch], $reversals[1]);
    }

    public function testDelete_DatesTheReversalOfEachBranchByTheClosedPeriodOfThatBranchAlone(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([$branch, 1] as $closed) {
            $saleId = $this->saleWithPaymentInto($treasury, '100.00', SALE_TYPE_POS);

            $this->accountingDb()->table('accounting_journals')->whereIn('journal_id', array_column($this->journals($saleId), 'id'))->update(['journal_date' => '2026-03-10']);
            $this->closeThrough('2026-06-30', $closed);

            $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

            $dates = [];

            foreach ($this->journals($saleId) as $journal) {
                if (str_starts_with($journal['source'], 'sale_payment')) {
                    $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journal['id'])->get()->getRow();

                    $dates[(int) $reversal->branch_id] = $reversal->journal_date;
                }
            }

            $this->assertSame([1 => $closed === 1 ? date('Y-m-d') : '2026-03-10', $branch => $closed === $branch ? date('Y-m-d') : '2026-03-10'], $dates, 'Only the branch whose period is closed is reversed today (closed: ' . $closed . ')');
            $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();

            $this->accountingDb()->table('accounting_closed_periods')->truncate();
        }
    }

    public function testDelete_RejectsAnEmployeeWhoWorksInOnlyOneOfTheBranchesAndReversesNothing(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId = $this->saleWithPaymentInto($treasury, '100.00', SALE_TYPE_POS);

        $this->employeeId = $this->employeeWithGrants([], [1]);

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(['sale_payment', 'sale_payment_branch'], array_keys($this->paymentJournals($saleId)));
        $this->assertSame('100.0000', (new TreasuryService())->position($treasury)->balance);
    }

    public function testDelete_AsksTheWithdrawScopeOfTheTreasuryOfTheOtherBranchAndReversesNothingWhenItIsClosed(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $saleId = $this->saleWithPaymentInto($treasury, '100.00', SALE_TYPE_POS);

        $this->accountingDb()->table('treasuries')->where('treasury_id', $treasury)->update(['withdraw_scope' => 'staff']);
        $this->accountingDb()->table('treasury_scope_members')->insert(['treasury_id' => $treasury, 'direction' => 'withdraw', 'member_id' => $this->employeeWithGrants([])]);

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->sales->accountingFailure()->error);
        $this->assertSame(['sale_payment', 'sale_payment_branch'], array_keys($this->paymentJournals($saleId)));
        $this->assertSame(COMPLETED, (int) $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow()->sale_status);
    }

    public function testSyncPayments_KeepsAPaymentThatWasStampedWithATreasuryOfAnotherBranchBeforeThisAsTheOneJournalItHas(): void
    {
        ['treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $account]);

        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->update(['treasury_id' => $treasury]);

        $paymentId = (int) $this->paymentRow($saleId)->payment_id;
        $before    = $this->countRows('accounting_journals');

        $this->assertSame(['sale_payment'], array_keys($this->paymentJournals($saleId)), 'Posted as one journal of the branch of the sale, into the treasury of the other');
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'again', 'payments' => [$this->scopeEditedPayment($paymentId)]]));

        $this->assertSame($before, $this->countRows('accounting_journals'), 'A payment posted before is not posted again in a new form');
        $this->assertNull($this->paymentRow($saleId)->treasury_branch_id);
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'less', 'payments' => [$this->scopeEditedPayment($paymentId, ['payment_amount' => '60.00'])]]));
        $this->assertSame(['sale_payment'], array_keys($this->paymentJournals($saleId)), 'Changed, it is posted as it was');
    }

    public function testSaveValue_TakesATreasuryOfAnotherBranchOnlyWhileTheBooksAreOn(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->setConfigValue('accounting_enabled', '0');

        $saleId = $this->saleWithPaymentInto($treasury);

        $this->assertSame(ACCOUNTING_FAILED, $saleId);
        $this->assertSame(AccountingError::TreasuryNotFound, $this->sales->accountingFailure()->error);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixtures T-26 to T-30, T-32 and T-33
    // ---------------------------------------------------------------------------------------

    /**
     * Rings the invoice of a case up on the register and saves it.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array{sale: int, made: list<array{item: int, quantity: string, price: string, discount: string}>, cart: array<int, array<string, mixed>>, taxes: array<int, mixed>, totals: array<string, mixed>}
     */
    private function invoiceOfCase(array $inputs, ?string $taxDecimals = null): array
    {
        $made = $this->linesOfCase($inputs['lines']);
        $ring = $this->ringUp($made, $this->discountOfCase($inputs['invoice_discount'] ?? null), null, $inputs['delivered'] ?? true, ['tax_decimals' => $taxDecimals ?? $inputs['tax_decimals'] ?? '2']);

        $this->assertGreaterThan(0, $ring['sale']);

        return ['made' => $made] + $ring;
    }

    /**
     * The share of the discount on the whole invoice and the tax each line of a sale carries.
     *
     * @return array{shares: list<string>, taxes: list<string>}
     */
    private function sharesAndTaxesOf(int $saleId): array
    {
        $taxes  = [];
        $shares = [];

        foreach ($this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->get()->getResult() as $row) {
            $taxes[(int) $row->line] = bcadd((string) $row->item_tax_amount, '0', 2);
        }

        foreach ($this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->orderBy('line')->get()->getResult() as $row) {
            $shares[] = bcadd((string) ($row->invoice_discount_share ?? '0'), '0', 2);
            $taxes[(int) $row->line] ??= '0.00';
        }

        ksort($taxes);

        return ['shares' => $shares, 'taxes' => array_values($taxes)];
    }

    /**
     * The figures the invoice shows when it is printed: what the register worked out for it.
     *
     * @param array<string, mixed> $totals
     *
     * @return array{subtotal: string, discount: string, tax: string, total: string}
     */
    private function printedFigures(array $totals): array
    {
        return [
            'subtotal' => bcadd((string) $totals['prediscount_subtotal'], '0', 2),
            'discount' => bcadd((string) $totals['total_discount'], (string) $totals['invoice_discount'], 2),
            'tax'      => bcadd((string) $totals['tax_total'], '0', 2),
            'total'    => bcadd((string) $totals['total'], '0', 2),
        ];
    }

    public function testSaveValue_PostsTheJournalOfAPercentageDiscountOnTheWholeInvoiceWithTheTaxRoundedToTheTaxDecimalsWhereTheReferenceSystemKeepsSeven(): void
    {
        $case = $this->referenceCase('T-26', 'invoice_journal', 'percent-discount-3-3-journal', 'differs');
        $sale = $this->invoiceOfCase($case['inputs']);
        $four = $this->invoiceOfCase($case['inputs'], '4');

        $this->assertSameAsFixture($case['expected']['journals']['invoice'], $this->roleLinesOfSale($sale['sale'], 'revenue'), 'two tax decimals');
        $this->assertSameAsFixture($case['expected']['detail']['at_four_tax_decimals']['invoice'], $this->roleLinesOfSale($four['sale'], 'revenue'), 'four tax decimals');
        $this->assertRolesAgree($case['reference']['journals']['invoice'], $this->roleLinesOfSale($sale['sale'], 'revenue'), ['customer'], 'the customer owes the same in both systems');
    }

    public function testSaveValue_PrintsTheFiguresOfAPercentageDiscountOnTheWholeInvoiceAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-26', 'invoice_figures', 'percent-discount-3-3-printed-figures', 'exact');
        $sale = $this->invoiceOfCase($case['inputs']);

        $this->assertSameAsFixture($case['expected']['figures'], $this->printedFigures($sale['totals']), 'printed figures');
    }

    public function testSaveValue_SharesAPercentageDiscountOverTheLinesAsTheReportOfTheReferenceSystemShowsIt(): void
    {
        $case = $this->referenceCase('T-26', 'invoice_shares', 'percent-discount-3-3-line-shares', 'exact');
        $sale = $this->invoiceOfCase($case['inputs']);
        $line = $this->sharesAndTaxesOf($sale['sale']);

        $this->assertSameAsFixture(['line_shares' => $case['expected']['line_shares'], 'line_taxes' => $case['expected']['line_taxes']], ['line_shares' => $line['shares'], 'line_taxes' => $line['taxes']], 'lines');
    }

    public function testSaveValue_PostsTheJournalOfAFixedDiscountOnTheWholeInvoiceWithTheSharesRoundedToTheCentWhereTheReferenceSystemKeepsThemWhole(): void
    {
        $case = $this->referenceCase('T-27', 'invoice_journal', 'fixed-discount-20-journal', 'differs');
        $sale = $this->invoiceOfCase($case['inputs']);
        $four = $this->invoiceOfCase($case['inputs'], '4');

        $this->assertSameAsFixture($case['expected']['journals']['invoice'], $this->roleLinesOfSale($sale['sale'], 'revenue'), 'two tax decimals');
        $this->assertSameAsFixture($case['expected']['detail']['at_four_tax_decimals']['invoice'], $this->roleLinesOfSale($four['sale'], 'revenue'), 'four tax decimals');
        $this->assertRolesAgree($case['reference']['journals']['invoice'], $this->roleLinesOfSale($sale['sale'], 'revenue'), ['customer'], 'the customer owes the same in both systems');
    }

    public function testSaveValue_PrintsTheFiguresOfAFixedDiscountOnTheWholeInvoiceAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-27', 'invoice_figures', 'fixed-discount-20-printed-figures', 'exact');
        $sale = $this->invoiceOfCase($case['inputs']);
        $shown = $this->printedFigures($sale['totals']);

        $this->assertSameAsFixture($case['expected']['figures'], ['discount' => $shown['discount'], 'tax' => $shown['tax'], 'total' => $shown['total']], 'printed figures');
    }

    public function testSaveValue_SharesAFixedDiscountOverTheLinesAsTheReportOfTheReferenceSystemShowsIt(): void
    {
        $case = $this->referenceCase('T-27', 'invoice_shares', 'fixed-discount-20-line-shares', 'exact');
        $sale = $this->invoiceOfCase($case['inputs']);
        $line = $this->sharesAndTaxesOf($sale['sale']);
        $both = [];

        foreach ($sale['made'] as $index => $made) {
            $ofTheLine = bcmul(bcmul($made['quantity'], $made['price'], 4), bcdiv($made['discount'], '100', 4), 4);
            $both[]    = bcadd(bcadd($ofTheLine, $line['shares'][$index], 4), '0', 2);
        }

        $this->assertSameAsFixture(['line_discounts' => $case['expected']['line_discounts'], 'line_taxes' => $case['expected']['line_taxes']], ['line_discounts' => $both, 'line_taxes' => $line['taxes']], 'lines');
    }

    public function testSaveValue_PostsTheCostOfTheGoodsOfAnInvoiceWithAFixedDiscountAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-27', 'invoice_cost', 'fixed-discount-20-cost-journal', 'exact');
        $sale = $this->invoiceOfCase($case['inputs']);

        $this->assertSameAsFixture($case['expected']['journals']['cost'], $this->roleLinesOfSale($sale['sale'], 'cogs'), 'cost journal');
    }

    public function testSaveValue_PostsTheInvoiceOfAnUndeliveredSaleAsTheReferenceSystemDoes(): void
    {
        $case   = $this->referenceCase('T-28', 'undelivered_invoice', 'undelivered-invoice-journal', 'exact');
        $sale   = $this->invoiceOfCase($case['inputs']);
        $item   = $sale['made'][0]['item'];
        $actual = [
            'journals'    => ['invoice' => $this->roleLinesOfSale($sale['sale'], 'revenue'), 'cost' => $this->roleLinesOfSale($sale['sale'], 'cogs')],
            'delivered'   => (string) $this->saleRow($sale['sale'])->delivered === '1',
            'stock_taken' => (string) (int) round(0.0 - $this->stock($item)),
        ];

        $this->assertSameAsFixture($case['expected'], $actual, 'undelivered invoice');
    }

    /**
     * An invoice of a case and a credit note taken from it.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array{invoice: int, return: int, journals: int, tax_decimals: string}
     */
    private function invoiceWithACreditNote(array $inputs, ?string $taxDecimals = null): array
    {
        $taxDecimals ??= $inputs['tax_decimals'] ?? '2';
        $invoice       = $this->invoiceOfCase($inputs['invoice'], $taxDecimals);
        $note          = $inputs['credit_note'];
        $before        = $this->countJournals();
        $return        = $this->ringUp([['item' => $invoice['made'][$note['line']]['item'], 'quantity' => $note['quantity'], 'price' => $note['price'], 'discount' => $note['discount']]], null, $invoice['sale'], true, ['tax_decimals' => $taxDecimals]);

        $this->assertGreaterThan(0, $return['sale']);

        return ['invoice' => $invoice['sale'], 'return' => $return['sale'], 'journals' => $this->countJournals() - $before, 'tax_decimals' => $taxDecimals];
    }

    public function testSaveValue_TakesBackTheShareOfTheDiscountOfTheReturnedUnitWhereTheReferenceSystemLeavesTheWholeDiscountOnTheCreditNote(): void
    {
        $case = $this->referenceCase('T-29', 'credit_note_journal', 'partial-return-journal', 'deliberate_difference');
        $made = $this->invoiceWithACreditNote($case['inputs']);
        $four = $this->invoiceWithACreditNote($case['inputs'], '4');
        $row  = $this->accountingDb()->table('sales_items')->where('sale_id', $made['return'])->get()->getRow();

        $this->assertSameAsFixture($case['expected']['journals']['credit_note'], $this->roleLinesOfSale($made['return'], 'revenue'), 'two tax decimals');
        $this->assertSameAsFixture($case['expected']['detail']['at_four_tax_decimals']['credit_note'], $this->roleLinesOfSale($four['return'], 'revenue'), 'four tax decimals');
        $this->assertSame($case['expected']['detail']['share_of_the_invoice_discount_taken_back'], bcadd(ltrim((string) $row->invoice_discount_share, '-'), '0', 2));
    }

    public function testSaveValue_ReturnsTheCostOfTheUnitOfADiscountedInvoiceAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-29', 'credit_note_cost', 'partial-return-cost-journal', 'exact');
        $made = $this->invoiceWithACreditNote($case['inputs']);

        $this->assertSameAsFixture($case['expected']['journals']['cost'], $this->roleLinesOfSale($made['return'], 'cogs'), 'cost journal');
    }

    public function testSaveValue_PaysTheCreditNoteOfADiscountedInvoiceIntoItsInvoiceWithoutAJournalAsTheReferenceSystemDoes(): void
    {
        $case    = $this->referenceCase('T-29', 'credit_note_application', 'partial-return-is-applied-to-its-invoice', 'exact');
        $made    = $this->invoiceWithACreditNote($case['inputs']);
        $total   = $this->roleLinesOfSale($made['return'], 'revenue');
        $credit  = array_values(array_filter($total, static fn (array $line): bool => $line['role'] === 'customer'))[0]['credit'];
        $figures = $this->position()->invoice($made['invoice']);
        $actual  = [
            'applied_to'                   => $this->applications() === ['credit_note:' . $made['return'] . '->' . $made['invoice'] . ':' . bcadd($credit, '0', 2)] ? 'its own invoice' : 'another invoice',
            'applied_in_full'              => bccomp($figures->applied, $credit, 2) === 0,
            'journals_for_the_application' => (string) ($made['journals'] - 2),
            'payment_state'                => $figures->paymentState()->value,
            'return_state'                 => $figures->returnState()->value,
        ];

        $this->assertSameAsFixture($case['expected'], $actual, 'application');
    }

    public function testSaveValue_RefusesACreditNoteOnAnInvoiceThatWasNeverDeliveredWhereTheReferenceSystemAcceptsIt(): void
    {
        $case    = $this->referenceCase('T-30', 'undelivered_credit_note', 'credit-note-on-an-undelivered-invoice', 'deliberate_difference');
        $invoice = $this->invoiceOfCase($case['inputs']['invoice'] + ['tax_decimals' => '2']);
        $item    = $invoice['made'][0]['item'];
        $note    = $case['inputs']['credit_note'];
        $before  = ['journals' => $this->countJournals(), 'stock' => $this->stock($item)];
        $return  = $this->ringUp([['item' => $item, 'quantity' => $note['quantity'], 'price' => $note['price'], 'discount' => $note['discount']]], null, $invoice['sale']);
        $actual  = [
            'accepted'        => $return['sale'] > 0,
            'journals_posted' => (string) ($this->countJournals() - $before['journals']),
            'stock_change'    => (string) (int) round($this->stock($item) - $before['stock']),
        ];

        $this->assertSameAsFixture(['accepted' => $case['expected']['accepted'], 'journals_posted' => $case['expected']['journals_posted'], 'stock_change' => $case['expected']['stock_change']], $actual, 'refusal');
        $this->assertSame(ACCOUNTING_FAILED, $return['sale']);
        $this->assertSame($case['expected']['detail']['error'], $this->failure()->error->value);
    }

    public function testConfirmDelivery_LeavesTheSameCostAndStockAsTheReferenceSystemAfterTheDeliveryAndTheCreditNoteBothHappened(): void
    {
        $case    = $this->referenceCase('T-30', 'undelivered_credit_note', 'delivery-and-credit-note-leave-the-same-books', 'exact');
        $invoice = $this->invoiceOfCase($case['inputs']['invoice'] + ['tax_decimals' => '2']);
        $item    = $invoice['made'][0]['item'];
        $note    = $case['inputs']['credit_note'];

        $this->assertSame($invoice['sale'], $this->sales->confirm_delivery($invoice['sale'], $this->employeeId));

        $delivery = array_values(array_filter($this->roleLinesOfSale($invoice['sale'], 'cogs'), static fn (array $line): bool => $line['role'] === 'cogs'))[0]['debit'];
        $return   = $this->ringUp([['item' => $item, 'quantity' => $note['quantity'], 'price' => $note['price'], 'discount' => $note['discount']]], null, $invoice['sale']);

        $this->assertGreaterThan(0, $return['sale']);
        $this->assertSameAsFixture($case['expected'], [
            'delivery_cost'        => bcadd($delivery, '0', 2),
            'net_cost_after_both'  => $this->balance($this->accountId('cogs')),
            'net_stock_after_both' => (string) (int) round($this->stock($item)),
        ], 'books');
    }

    /**
     * Sells the units of a case, takes back the ones it says came back before, and asks for one more return.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array{result: string, saved: string, failure: ?array<string, mixed>}
     */
    private function attemptToReturn(array $inputs): array
    {
        $item   = $this->taxedItem('60.00', true, '15');
        $sold   = $this->ringUp([['item' => $item, 'quantity' => $inputs['sold'], 'price' => '100.00']]);
        $first  = $this->ringUp([['item' => $item, 'quantity' => '-' . $inputs['returned_before'], 'price' => '100.00']], null, $sold['sale']);

        $this->assertGreaterThan(0, $first['sale']);

        $sales   = $this->countSales();
        $journals = $this->countJournals();
        $attempt = $this->ringUp([['item' => $item, 'quantity' => '-' . $inputs['attempt'], 'price' => '100.00']], null, $sold['sale']);

        return [
            'result'  => $attempt['sale'] > 0 ? 'accepted' : 'refused',
            'saved'   => (string) ($this->countSales() - $sales + $this->countJournals() - $journals),
            'failure' => $attempt['sale'] > 0 ? null : ['error' => $this->failure()->error->value, 'max' => $this->failure()->context['max'] ?? null],
        ];
    }

    public function testSaveValue_RefusesACreditNoteAboveWhatTheInvoiceSoldAndSavesNothingAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-32', 'return_limit', 'a-return-above-what-the-invoice-sold-is-refused', 'exact');
        $made = $this->attemptToReturn($case['inputs']);

        $this->assertSameAsFixture(['result' => $case['expected']['result'], 'saved' => $case['expected']['saved']], ['result' => $made['result'], 'saved' => $made['saved']], 'attempt');
        $this->assertSame(['error' => $case['expected']['detail']['error'], 'max' => $case['expected']['detail']['most_that_may_still_be_returned']], $made['failure']);
    }

    public function testSaveValue_CountsTheEarlierReturnsOfTheInvoiceAgainstTheLimitAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-32', 'return_limit', 'earlier-returns-count-against-the-limit', 'exact');
        $made = $this->attemptToReturn($case['inputs']);

        $this->assertSameAsFixture(['result' => $case['expected']['result'], 'saved' => $case['expected']['saved']], ['result' => $made['result'], 'saved' => $made['saved']], 'attempt');
        $this->assertSame(['error' => $case['expected']['detail']['error'], 'max' => $case['expected']['detail']['most_that_may_still_be_returned']], $made['failure']);
    }

    public function testSaveValue_RefusesACreditNoteForStockWithNoInvoiceWhereTheReferenceSystemAcceptsIt(): void
    {
        $case   = $this->referenceCase('T-33', 'orphan_credit_note', 'credit-note-for-stock-without-an-invoice', 'deliberate_difference');
        $made   = $this->linesOfCase($case['inputs']['lines']);
        $before = ['journals' => $this->countJournals(), 'stock' => $this->stock($made[0]['item'])];
        $return = $this->ringUp($made, null, null, true, ['tax_decimals' => '2'], true);
        $actual = [
            'accepted'        => $return['sale'] > 0,
            'journals_posted' => (string) ($this->countJournals() - $before['journals']),
            'stock_change'    => (string) (int) round($this->stock($made[0]['item']) - $before['stock']),
        ];

        $this->assertSameAsFixture(['accepted' => $case['expected']['accepted'], 'journals_posted' => $case['expected']['journals_posted'], 'stock_change' => $case['expected']['stock_change']], $actual, 'refusal');
        $this->assertSame(ACCOUNTING_FAILED, $return['sale']);
        $this->assertSame($case['expected']['detail']['error'], $this->failure()->error->value);
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the third write batch: fixture T-57
    // ---------------------------------------------------------------------------------------

    /**
     * Pays 5.00 of a sale the way the case says into the treasury the case says, and returns the
     * lines of the journal of that payment.
     *
     * @param array<string, mixed> $inputs
     *
     * @return list<array{role: string, debit: string, credit: string}>
     */
    private function paymentJournalOfCase(array $inputs): array
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $treasury   = $inputs['treasury'] === 'bank' ? $treasuries->create(['name' => 'Fixture bank', 'kind' => 'bank', 'bank_name' => 'Riyad Bank', 'account_number' => '3500000002']) : $treasuries->ensureMain();
        $way        = ['check' => lang('Sales.check'), 'cash' => lang('Sales.cash'), 'bank_transfer' => lang('Sales.bank_transfer')][$inputs['way']];
        $item       = $this->item('10.00');
        $saleId     = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment($way, $inputs['amount']) + ['treasury_id' => $treasury]]);
        $payment    = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'));

        $this->assertCount(1, $payment);

        return $this->roleLines($payment[0]['id'], ['treasury' => (int) $treasuries->accountOf($treasury), 'customer' => $this->customerAccount()]);
    }

    public function testSaveValue_PostsAChequePaymentIntoTheBankAccountChosenAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-57', 'payment_method', 'cheque-method-into-a-bank-account', 'exact');

        $this->assertSameAsFixture($case['expected']['journals']['payment'], $this->paymentJournalOfCase($case['inputs']), 'payment by cheque into a bank account');
    }

    public function testSaveValue_PostsACashPaymentIntoTheBankAccountChosenAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-57', 'payment_method', 'cash-method-into-a-bank-account', 'exact');

        $this->assertSameAsFixture($case['expected']['journals']['payment'], $this->paymentJournalOfCase($case['inputs']), 'payment in cash into a bank account');
    }

    public function testSaveValue_PostsABankTransferPaymentIntoTheMainTreasuryChosenAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-57', 'payment_method', 'bank-transfer-method-into-the-main-treasury', 'exact');

        $this->assertSameAsFixture($case['expected']['journals']['payment'], $this->paymentJournalOfCase($case['inputs']), 'payment by bank transfer into the main treasury');
    }
}
