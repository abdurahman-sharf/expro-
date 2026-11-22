<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountService;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\DraftInvoice;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\InterBranchBalances;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PairBalance;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\QuoteLifecycle;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\ReturnOverride;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\RoutingType;
use App\Libraries\Accounting\SalePosting;
use App\Libraries\Accounting\ShippingCompanies;
use App\Libraries\Accounting\StockLedger;
use App\Libraries\Accounting\TaxDefinitions;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceService;
use App\Libraries\HeaderCharges;
use App\Libraries\InvoiceDiscountProblem;
use App\Libraries\Pricing\SalesControlSettings;
use App\Libraries\ShippingTaxOption;
use App\Libraries\Tax\VatLineResult;
use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\LineVat;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Sale_lib;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\TrackingType;
use App\Libraries\Stock\UnitBarcodes;
use App\Libraries\Stock\UnitTemplates;
use App\Libraries\Tax_lib;
use App\Models\Item_kit;
use App\Models\Item_kit_items;
use App\Models\Item_taxes;
use Config\OSPOS;
use CodeIgniter\Database\Query;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EInvoiceSaleFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ParityFixture;
use Tests\Support\RegisterFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\SaudiSaleFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;
use Tests\Support\WorkerRequestTrait;
use App\Libraries\Accounting\OperationsHold;

/**
 * The cycle of a sale through the books, driven through the real sale model: what is posted when a
 * sale is completed, delivered, paid, returned and cancelled, and that a refusal of the books
 * leaves nothing of the sale behind.
 */
class SalePostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use RegisterFixtureTrait;
    use SaleAccountingFixtureTrait;
    use EInvoiceSaleFixtureTrait;
    use SaudiSaleFixtureTrait;
    use ShelfFixtureTrait;
    use StockUnitsFixtureTrait;
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
        $this->leaveTheSaudiRules();
        $this->stopStockUnits();
        $this->stopSelling();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->whereIn('action', ['numbering_configured', 'quote_status_changed', 'quote_converted', 'draft_issued', 'header_charges_given'])->delete();
        $db->table('app_config')->whereIn('key', ['accounting_sales_adjustment', 'accounting_sales_shipping', 'accounting_shipping_tax_percent', 'accounting_journal_description_sales'])->delete();

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
        $this->accountingDb()->table('accounting_routing')->where('routing_key', RoutingKey::Customers->value)->update(['routing_type' => RoutingType::PerItem->value]);

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

        $this->assertSame($case['expected']['order'], implode(', ', array_map(static fn (string $entry): string => $steps[$entry], $this->entriesByNumber($saleId))));
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
        $this->assertSame(['revenue', 'general', 'general', 'general', 'cogs'], $this->entriesByNumber($saleId));
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
        $this->assertSame(['revenue', 'general', 'cogs'], $this->entriesByNumber($returnId));
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
     * The stock movements a sale wrote, without the ids that tell one sale from another.
     *
     * @return list<string>
     */
    private function stockEntriesOf(int $saleId): array
    {
        return array_map(
            static fn (array $row): string => implode(':', [$row['entry_type'], $row['item_id'], $row['location_id'], bcadd($row['quantity'], '0', 3), bcadd($row['value'], '0', 4)]),
            $this->accountingDb()->table('accounting_stock_entries')->select('entry_type, item_id, location_id, quantity, value')->where(['source_type' => 'sale', 'source_id' => $saleId])->orderBy('line_no', 'ASC')->get()->getResultArray(),
        );
    }

    /**
     * The kinds of the journals of a sale in the order of their journal numbers, read apart from the
     * order the list of journals comes in.
     *
     * @return list<string>
     */
    private function entriesByNumber(int $saleId): array
    {
        $rows = $this->accountingDb()->table('accounting_journals')
            ->select('entry_type')
            ->whereIn('journal_id', array_column($this->journals($saleId), 'id'))
            ->orderBy('CAST(number AS UNSIGNED)', 'ASC', false)
            ->get()
            ->getResultArray();

        return array_column($rows, 'entry_type');
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
        $this->assertSame($this->stockEntriesOf($note), $this->stockEntriesOf($after));
        $this->assertNotSame([], $this->stockEntriesOf($after));
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

        $ledger = new StockLedger();

        $this->assertSame([], $ledger->mismatchedJournals());
        $this->assertSame(0, bccomp($ledger->reconcile([self::LOCATION_ID])[0]['unattributed'], '0', 4));
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

    public function testSaveValue_AcceptsACreditNoteOnASaleThatWasNeverDeliveredAndMovesNoStockAndPostsNoCost(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);
        $stock   = $this->stock($item);

        $note = $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertGreaterThan(0, $note);
        $this->assertEqualsWithDelta($stock, $this->stock($item), 0.0001);
        $this->assertCount(1, $this->standing($note, 'revenue'));
        $this->assertSame([], $this->standing($note, 'cogs'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_stock_entries')->where(['source_type' => 'sale', 'source_id' => $note])->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('inventory')->where('trans_comment', 'POS ' . $note)->countAllResults());
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('cogs')));
        $this->assertSame('400.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testSaveValue_RefusesACreditNoteOnASaleThatWasNeverDeliveredThatTakesBackMoreThanWasInvoiced(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '-3', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-3', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice));
        $this->assertSame(PostingError::SaleReturnExceedsOrigin, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
    }

    public function testConfirmDelivery_TakesOutOnlyTheUnitsThatWereNotReturnedAndPostsTheirCost(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);
        $note    = $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $stock   = $this->stock($item);

        $this->assertSame($invoice, $this->sales->confirm_delivery($invoice, $this->employeeId));

        $cost = $this->standing($invoice, 'cogs');

        $this->assertCount(1, $cost);
        $this->assertSame([$this->accountId('cogs') . ':240.00:0.00', $this->stockAccount() . ':0.00:240.00'], $cost[0]['lines']);
        $this->assertEqualsWithDelta($stock - 4, $this->stock($item), 0.0001);
        $this->assertSame([], $this->standing($note, 'cogs'));
        $this->assertSame('240.00', $this->balance($this->accountId('cogs')));
        $this->assertSame('-240.00', $this->balance($this->stockAccount()));
        $this->assertSame('-4.000', bcadd((string) $this->accountingDb()->table('accounting_stock_entries')->selectSum('quantity', 'quantity')->where(['source_type' => 'sale', 'source_id' => $invoice])->get()->getRow()->quantity, '0', 3));
        $this->assertTies();
    }

    public function testConfirmDelivery_TakesNothingOutAndPostsNoCostWhenEverythingWasReturnedBeforeIt(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->save([$this->line($item, 0, '-5', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $stock = $this->stock($item);

        $this->assertSame($invoice, $this->sales->confirm_delivery($invoice, $this->employeeId));
        $this->assertSame('1', (string) $this->saleRow($invoice)->delivered);
        $this->assertSame([], $this->standing($invoice, 'cogs'));
        $this->assertEqualsWithDelta($stock, $this->stock($item), 0.0001);
        $this->assertSame('0.00', $this->balance($this->accountId('cogs')));
        $this->assertSame('0.00', $this->balance($this->stockAccount()));
        $this->assertTies();
    }

    public function testConfirmDelivery_SharesWhatCameBackOverTheLinesOfTheItemInOrder(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '2', '100', '60.00'), $this->line($item, 1, '4', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->save([$this->line($item, 0, '-3', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $stock = $this->stock($item);

        $this->assertSame($invoice, $this->sales->confirm_delivery($invoice, $this->employeeId));
        $this->assertEqualsWithDelta($stock - 3, $this->stock($item), 0.0001);
        $this->assertSame('180.00', $this->balance($this->accountId('cogs')));
    }

    public function testSaveValue_PostsTheCostOfACreditNoteAtTheOriginalCostOnceTheSaleWasDelivered(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->sales->confirm_delivery($invoice, $this->employeeId);

        $stock = $this->stock($item);
        $note  = $this->save([$this->line($item, 0, '-2', '100', '99.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertSame([$this->stockAccount() . ':120.00:0.00', $this->accountId('cogs') . ':0.00:120.00'], $this->standing($note, 'cogs')[0]['lines']);
        $this->assertEqualsWithDelta($stock + 2, $this->stock($item), 0.0001);
    }

    public function testSaveValue_AcceptsAReturnOfStockWithNoSaleForAnEmployeeHoldingTheOverridePermission(): void
    {
        $this->grantReturnOverride();

        $item  = $this->item('66.00');
        $stock = $this->stock($item);

        $returnId = $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [$this->payment(lang('Sales.cash'), '-200.00')], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'lost the receipt');

        $this->assertGreaterThan(0, $returnId);
        $this->assertEqualsWithDelta($stock + 2, $this->stock($item), 0.0001);
        $this->assertSame([$this->stockAccount() . ':132.00:0.00', $this->accountId('cogs') . ':0.00:132.00'], $this->standing($returnId, 'cogs')[0]['lines']);
        $this->assertSame('-132.00', $this->balance($this->accountId('cogs')));
        $this->assertSame('132.00', $this->balance($this->stockAccount()));
    }

    public function testSaveValue_RecordsTheOverrideItsAuditEntryAndTheMarkOnBothJournals(): void
    {
        $this->grantReturnOverride();

        $item     = $this->item('66.00');
        $returnId = $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'lost the receipt');

        $row = $this->accountingDb()->table('sale_return_overrides')->where('sale_id', $returnId)->get()->getRow();

        $this->assertSame(['lost the receipt', (string) $this->employeeId, '1'], [$row->reason, (string) $row->employee_id, (string) $row->branch_id]);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $returnId, 'action' => 'return_override'])->get()->getResult();

        $this->assertCount(1, $audit);
        $this->assertSame((string) $this->employeeId, (string) $audit[0]->employee_id);
        $this->assertSame('lost the receipt', json_decode((string) $audit[0]->details, true)['reason']);

        $descriptions = array_column($this->accountingDb()->table('accounting_journals')->select('description')->where(['source_type' => 'sale', 'source_id' => $returnId])->orderBy('journal_id')->get()->getResultArray(), 'description');

        $this->assertSame(['Return #' . $returnId . ' ' . ReturnOverride::MARKER, 'Cost of return #' . $returnId . ' ' . ReturnOverride::MARKER], $descriptions);
    }

    public function testSaveValue_CostsAReturnLetThroughAtTheCurrentCostAndNotAtTheCostTheItemWasSoldAt(): void
    {
        $this->grantReturnOverride();

        $item = $this->item('60.00');
        $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $this->accountingDb()->table('items')->where('item_id', $item)->update(['cost_price' => '80.00']);

        $returnId = $this->save([$this->line($item, 0, '-1', '100', '80.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'not from us');

        $this->assertSame([$this->stockAccount() . ':80.00:0.00', $this->accountId('cogs') . ':0.00:80.00'], $this->standing($returnId, 'cogs')[0]['lines']);
    }

    public function testSaveValue_RefusesTheOverrideOfAnEmployeeWithoutThePermissionAndLeavesNothingOfTheReturn(): void
    {
        $item     = $this->item('66.00');
        $stock    = $this->stock($item);
        $sales    = $this->countSales();
        $journals = $this->countJournals();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'lost the receipt'));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame(0, $this->countRows('sale_return_overrides'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'return_override')->countAllResults());
        $this->assertEqualsWithDelta($stock, $this->stock($item), 0.0001);
    }

    public function testSaveValue_RequiresAReasonOfOneToTwoHundredAndFiftyFiveCharactersForTheOverride(): void
    {
        $this->grantReturnOverride();

        $item = $this->item('66.00');

        foreach (['', '   ', str_repeat('x', 256)] as $reason) {
            $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: $reason));
            $this->assertSame(AccountingError::ReasonRequired, $this->failure()->error);
        }

        $this->assertGreaterThan(0, $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: str_repeat('x', 255)));
        $this->assertSame(1, $this->countRows('sale_return_overrides'));
    }

    public function testSaveValue_KeepsRefusingAReturnOfStockWithNoSaleWhenNoOverrideIsAskedForEvenForAnEmployeeWhoHoldsThePermission(): void
    {
        $this->grantReturnOverride();

        $item = $this->item('66.00');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], [], SALE_TYPE_RETURN));
        $this->assertSame(PostingError::ReturnOriginRequired, $this->failure()->error);
        $this->assertSame(0, $this->countRows('sale_return_overrides'));
    }

    public function testSaveValue_IgnoresAnOverrideOnAReturnThatNamesItsSaleAndKeepsTheReturnLimit(): void
    {
        $this->grantReturnOverride();

        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '2', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '200.00')]);

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-3', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'too many'));
        $this->assertSame(PostingError::SaleReturnExceedsOrigin, $this->failure()->error);

        $returnId = $this->save([$this->line($item, 0, '-2', '100', '99.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'two back');

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame(0, $this->countRows('sale_return_overrides'));
        $this->assertSame([$this->stockAccount() . ':120.00:0.00', $this->accountId('cogs') . ':0.00:120.00'], $this->standing($returnId, 'cogs')[0]['lines']);
    }

    public function testSaveValue_AsksNothingOfTheOverrideForAReturnOfServicesAndAddsNoMarkToIt(): void
    {
        $this->grantReturnOverride();

        $service  = $this->item('0', false);
        $returnId = $this->save([$this->line($service, 0, '-1', '100')], [], [$this->payment(lang('Sales.cash'), '-100.00')], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'not needed');

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame(0, $this->countRows('sale_return_overrides'));
        $this->assertSame('Return #' . $returnId, $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $returnId, 'entry_type' => 'revenue'])->get()->getRow()->description);
    }

    public function testSaveValue_LeavesAReturnWithItsSaleUnmarkedAndFreeOfAnyOverride(): void
    {
        $item     = $this->item('60.00');
        $invoice  = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $returnId = $this->save([$this->line($item, 0, '-1', '100', '99.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertSame(0, $this->countRows('sale_return_overrides'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'return_override')->countAllResults());
        $this->assertSame(['Return #' . $returnId, 'Cost of return #' . $returnId], array_column($this->accountingDb()->table('accounting_journals')->select('description')->where(['source_type' => 'sale', 'source_id' => $returnId])->orderBy('journal_id')->get()->getResultArray(), 'description'));
        $this->assertSame([$this->stockAccount() . ':60.00:0.00', $this->accountId('cogs') . ':0.00:60.00'], $this->standing($returnId, 'cogs')[0]['lines']);
    }

    public function testSaveValue_AcceptsAReturnOfStockWithNoSaleWhileAccountingIsOffWithoutAnyRecordOfAnOverride(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $item  = $this->item('66.00');
        $stock = $this->stock($item);

        $returnId = $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'ignored while accounting is off');

        $this->assertGreaterThan(0, $returnId);
        $this->assertEqualsWithDelta($stock + 2, $this->stock($item), 0.0001);
        $this->assertSame(0, $this->countRows('sale_return_overrides'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesAnOverriddenReturnInAClosedPeriodAndLeavesNoRecordOfTheOverride(): void
    {
        $this->grantReturnOverride();
        $this->closeThrough(date('Y-m-d'));

        $item   = $this->item('66.00');
        $stock  = $this->stock($item);
        $sales  = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'lost the receipt'));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countRows('sale_return_overrides'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'return_override')->countAllResults());
        $this->assertEqualsWithDelta($stock, $this->stock($item), 0.0001);
    }

    public function testSaveValue_RecordsTheBranchOfAnOverriddenReturn(): void
    {
        $this->grantReturnOverride();

        $branch   = $this->createBranch('B2');
        $location = $this->branchLocation($branch);
        $item     = $this->item('66.00');

        try {
            $returnId = $this->save([$this->line($item, 0, '-1', '100', '66.00', '0', PERCENT, $location)], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'branch return');

            $this->assertSame($branch, (int) $this->accountingDb()->table('sale_return_overrides')->where('sale_id', $returnId)->get()->getRow()->branch_id);
            $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'return_override', 'entity_id' => $returnId])->get()->getRow()->branch_id);
        } finally {
            $this->accountingDb()->table('item_quantities')->where('location_id', $location)->delete();
            $this->accountingDb()->table('inventory')->where('trans_location', $location)->delete();
        }
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

    public function testDelete_RefusesToCancelAPointOfSaleSaleWhileAReturnOfItStandsAndLeavesTheBooksAndTheStockAsTheyWere(): void
    {
        $cycle    = $this->referenceInvoice();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_POS);
        $returnId = $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $saleId);

        $this->assertGreaterThan(0, $returnId);

        $journals = $this->countJournals();
        $balance  = $this->balance($this->customerAccount());
        $cream    = $this->stock($cycle['cream']);
        $perfume  = $this->stock($cycle['perfume']);

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(AccountingError::SaleHasReturns, $this->failure()->error);
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($saleId)->sale_status);
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame($balance, $this->balance($this->customerAccount()));
        $this->assertEqualsWithDelta($cream, $this->stock($cycle['cream']), 0.0001);
        $this->assertEqualsWithDelta($perfume, $this->stock($cycle['perfume']), 0.0001);

        $ledger = new StockLedger();

        $this->assertSame([], ($ledger->mismatchedJournals()));

        foreach ($ledger->reconcile() as $location) {
            $this->assertSame(0, bccomp($location['unattributed'], '0', 4));
        }

        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testSaveValue_RefusesACreditNoteOfACancelledSaleOfServicesAndPostsNothing(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')], SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($invoice, false, true, $this->employeeId));

        $journals = $this->countJournals();
        $sales    = $this->countSales();
        $balance  = $this->balance($this->customerAccount());

        $this->assertSame(ACCOUNTING_FAILED, $this->creditNoteOf($invoice, '40.00'));
        $this->assertSame(PostingError::ReturnOriginInvalid, $this->failure()->error);
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($balance, $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testDelete_StillCancelsAPointOfSaleSaleWhoseReturnWasForAnotherSale(): void
    {
        $cycle  = $this->referenceInvoice();
        $first  = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_POS);
        $second = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_POS);

        $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $first);

        $this->assertTrue($this->sales->delete($second, false, true, $this->employeeId));
        $this->assertSame([], $this->standing($second));

        foreach ((new StockLedger())->reconcile() as $location) {
            $this->assertSame(0, bccomp($location['unattributed'], '0', 4));
        }
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

    public function testSaveValue_AcceptsACreditNoteOnAnInvoiceThatWasNeverDeliveredAndPostsNeitherTheCostReturnNorTheStockTheReferenceSystemPosts(): void
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

        $this->assertSameAsFixture(['accepted' => $case['expected']['accepted'], 'journals_posted' => $case['expected']['journals_posted'], 'stock_change' => $case['expected']['stock_change']], $actual, 'credit note on an undelivered invoice');
        $this->assertRolesAgree($case['expected']['journals']['credit_note'], $this->roleLinesOfSale($return['sale'], 'revenue'), ['sales_returns', 'tax_output', 'customer'], 'revenue return of the credit note');
        $this->assertSame([], $this->standing($return['sale'], 'cogs'));
    }

    public function testConfirmDelivery_LeavesTheSameNetCostAndStockAsTheReferenceSystemWhenTheCreditNoteComesBeforeTheDelivery(): void
    {
        $case    = $this->referenceCase('T-30', 'undelivered_credit_note', 'delivery-and-credit-note-leave-the-same-books', 'exact');
        $invoice = $this->invoiceOfCase($case['inputs']['invoice'] + ['tax_decimals' => '2']);
        $item    = $invoice['made'][0]['item'];
        $note    = $case['inputs']['credit_note'];
        $return  = $this->ringUp([['item' => $item, 'quantity' => $note['quantity'], 'price' => $note['price'], 'discount' => $note['discount']]], null, $invoice['sale']);

        $this->assertGreaterThan(0, $return['sale']);
        $this->assertSame($invoice['sale'], $this->sales->confirm_delivery($invoice['sale'], $this->employeeId));
        $this->assertSameAsFixture($case['expected'], [
            'net_cost_after_both'  => $this->balance($this->accountId('cogs')),
            'net_stock_after_both' => (string) (int) round($this->stock($item)),
        ], 'books');
    }

    public function testConfirmDelivery_PostsTheCostOfOnlyTheUnitsThatStayedSoldWhereTheReferenceSystemPostsTheWholeInvoice(): void
    {
        $case     = $this->referenceCase('T-30', 'undelivered_credit_note', 'delivery-takes-out-only-what-was-not-returned', 'deliberate_difference');
        $invoice  = $this->invoiceOfCase($case['inputs']['invoice'] + ['tax_decimals' => '2']);
        $item     = $invoice['made'][0]['item'];
        $note     = $case['inputs']['credit_note'];
        $this->ringUp([['item' => $item, 'quantity' => $note['quantity'], 'price' => $note['price'], 'discount' => $note['discount']]], null, $invoice['sale']);

        $stock = $this->stock($item);

        $this->assertSame($invoice['sale'], $this->sales->confirm_delivery($invoice['sale'], $this->employeeId));

        $delivery = array_values(array_filter($this->roleLinesOfSale($invoice['sale'], 'cogs'), static fn (array $line): bool => $line['role'] === 'cogs'))[0]['debit'];

        $this->assertSameAsFixture($case['expected'], [
            'delivery_cost'         => bcadd($delivery, '0', 2),
            'delivery_stock_change' => (string) (int) round($this->stock($item) - $stock),
        ], 'delivery');
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

    // ---------------------------------------------------------------------------------------
    // The date typed on an invoice, its payment terms and its due date (WP13)
    // ---------------------------------------------------------------------------------------

    /**
     * @return list<array{id: int, source: string, entry: string, revision: int, status: string, date: string, lines: list<string>}>
     */
    private function standingDated(int $saleId, string $day): array
    {
        $standing = $this->standing($saleId);

        $this->assertNotSame([], $standing);

        return array_values(array_filter($standing, static fn (array $journal): bool => $journal['date'] === $day));
    }

    public function testSaveValue_DatesTheJournalsOfAnInvoiceByTheDayTypedOnItAndKeepsTheTimeItWasSaved(): void
    {
        $this->grantInvoiceDate();

        $cycle  = $this->referenceInvoice();
        $day    = $this->daysAgo(10);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $day);
        $sale   = $this->saleRow($saleId);

        $this->assertSame($day, $sale->invoice_date);
        $this->assertSame(date('Y-m-d'), substr((string) $sale->sale_time, 0, 10));
        $this->assertEqualsCanonicalizing(['revenue', 'cogs'], array_column($this->standingDated($saleId, $day), 'entry'));
        $this->assertSame($day, $this->position()->invoice($saleId)->date);
        $this->assertGreaterThan(0, $this->accountingDb()->table('accounting_stock_entries')->where(['source_type' => 'sale', 'source_id' => $saleId, 'entry_date' => $day])->countAllResults());
        $this->assertTies();
    }

    public function testSaveValue_DatesAnInvoiceTodayWhenNoDayIsTypedAndStoresNoDay(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);
        $sale   = $this->saleRow($saleId);

        $this->assertNull($sale->invoice_date);
        $this->assertNull($sale->payment_terms_days);
        $this->assertNull($sale->due_date);
        $this->assertCount(2, $this->standingDated($saleId, date('Y-m-d')));
        $this->assertSame(date('Y-m-d'), $this->position()->invoice($saleId)->dueDate);
    }

    public function testDatedBySchedule_LetsTheScheduleDateAnInvoiceAndOnlyWhileTheCallRuns(): void
    {
        $cycle = $this->referenceInvoice();
        $day   = $this->daysAgo(3);

        $saleId = $this->sales->datedBySchedule(fn (): int => $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $day));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame($day, $this->saleRow($saleId)->invoice_date);

        $again = $this->referenceInvoice();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($again['items'], $again['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $day));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);
    }

    public function testDatedBySchedule_TakesTheRightToTypeTheDayBackWhenTheWorkFails(): void
    {
        try {
            $this->sales->datedBySchedule(static function (): never {
                throw new RuntimeException('the work failed');
            });
            $this->fail('The failure of the work reaches the caller.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $cycle = $this->referenceInvoice();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(2)));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);
    }

    public function testDatedBySchedule_StillRefusesADayThatHasNotComeAndOneBeforeTheBooks(): void
    {
        foreach ([[date('Y-m-d', strtotime('+1 day')), AccountingError::InvoiceDateInvalid], ['2019-12-31', AccountingError::InvoiceDateBeforeStart]] as [$day, $error]) {
            $cycle = $this->referenceInvoice();

            $result = $this->sales->datedBySchedule(fn (): int => $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $day));

            $this->assertSame(ACCOUNTING_FAILED, $result);
            $this->assertSame($error, $this->failure()->error);
        }
    }

    public function testSaveValue_RefusesADayTypedByAnEmployeeWhoMayNotTypeItAndSavesNothing(): void
    {
        $cycle = $this->referenceInvoice();
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(3)));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesAnInvoiceDatedInAClosedPeriodAndLeavesNothingOfIt(): void
    {
        $this->grantInvoiceDate();
        $this->closeThrough($this->daysAgo(5));

        $cycle = $this->referenceInvoice();
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10)));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_PostsAnInvoiceDatedTheDayAfterTheClosingAndTheDayOfTheClosingIsRefused(): void
    {
        $this->grantInvoiceDate();
        $this->closeThrough($this->daysAgo(5));

        $cycle  = $this->referenceInvoice();
        $closed = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(5));

        $this->assertSame(ACCOUNTING_FAILED, $closed);
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);

        $open = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(4));

        $this->assertGreaterThan(0, $open);
        $this->assertCount(2, $this->standingDated($open, $this->daysAgo(4)));
    }

    /**
     * @return array<string, array{string, AccountingError}>
     */
    public static function refusedDayProvider(): array
    {
        return [
            'tomorrow'           => [date('Y-m-d', strtotime('+1 day')), AccountingError::InvoiceDateInvalid],
            'not a date'         => ['yesterday', AccountingError::InvoiceDateInvalid],
            'a day that is not'  => ['2026-02-30', AccountingError::InvoiceDateInvalid],
            'before the books'   => ['2019-12-31', AccountingError::InvoiceDateBeforeStart],
        ];
    }

    #[DataProvider('refusedDayProvider')]
    public function testSaveValue_RefusesADayThatIsNotARealDayUpToTodayOrIsBeforeTheBooksStart(string $typed, AccountingError $error): void
    {
        $this->grantInvoiceDate();

        $cycle = $this->referenceInvoice();
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $typed));
        $this->assertSame($error, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testSaveValue_IssuesADraftWithATypedDayAtThatDayAsTheReferenceSystemDoes(): void
    {
        $case  = $this->lifecycleCase('draft-issued-at-its-typed-date');
        $day   = $this->daysAgo(abs((int) $case['inputs']['invoice_date_offset_days']));
        $cycle = $this->referenceInvoice();

        $this->grantInvoiceDate();

        $draft = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $day);

        $this->assertSame($day, $this->saleRow($draft)->invoice_date);
        $this->assertSame([], $this->journals($draft));
        $this->assertNull($this->saleRow($draft)->due_date);

        $this->assertSame($draft, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, invoiceDate: $day));

        $dates = array_values(array_unique(array_column($this->standing($draft), 'date')));

        $this->assertSameAsFixture($case['expected'], ['journal_date' => $dates === [$day] ? 'the date typed on the draft' : 'another date'], 'date of the journals of the issued draft');
        $this->assertTies();
    }

    public function testSaveValue_IssuesADraftWithNoTypedDayOnTheDayItIsIssued(): void
    {
        $cycle = $this->referenceInvoice();
        $draft = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->accountingDb()->table('sales')->where('sale_id', $draft)->update(['sale_time' => $this->daysAgo(6) . ' 10:00:00']);

        $this->assertSame($draft, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft));
        $this->assertNull($this->saleRow($draft)->invoice_date);
        $this->assertSame(date('Y-m-d'), substr((string) $this->saleRow($draft)->sale_time, 0, 10));
        $this->assertCount(2, $this->standingDated($draft, date('Y-m-d')));
    }

    public function testSaveValue_LetsAnEmployeeWithoutThePermissionIssueADraftAtTheDayItAlreadyCarriesButNotAtAnother(): void
    {
        $cycle = $this->referenceInvoice();
        $day   = $this->daysAgo(4);

        $this->grantInvoiceDate();

        $draft = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $day);
        $other = $this->createEmployee();
        $owner = $this->employeeId;

        $this->employeeId = $other;

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, invoiceDate: $this->daysAgo(2)));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);
        $this->assertSame([], $this->journals($draft));

        $this->assertSame($draft, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, invoiceDate: $day));
        $this->assertCount(2, $this->standingDated($draft, $day));

        $this->employeeId = $owner;
    }

    public function testSaveValue_TakesNoTypedDayForASaleThatIsNotAnInvoice(): void
    {
        $this->grantInvoiceDate();

        $pos = $this->save([$this->line($this->item('0', false), 0, '1', '40')], [], [$this->payment(lang('Sales.cash'), '40.00')], SALE_TYPE_POS, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(3), paymentTerms: 30);

        $this->assertGreaterThan(0, $pos);
        $this->assertNull($this->saleRow($pos)->invoice_date);
        $this->assertNull($this->saleRow($pos)->payment_terms_days);
        $this->assertSame([date('Y-m-d')], array_values(array_unique(array_column($this->standing($pos), 'date'))));
    }

    public function testSaveValue_SavesNoTypedDayOrTermsWhileAccountingIsOff(): void
    {
        $this->grantInvoiceDate();
        $this->setConfigValue('accounting_enabled', '0');

        $audit  = $this->countRows('accounting_audit_log');
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(3), paymentTerms: 30);

        $this->assertGreaterThan(0, $saleId);
        $this->assertNull($this->saleRow($saleId)->invoice_date);
        $this->assertNull($this->saleRow($saleId)->payment_terms_days);
        $this->assertNull($this->saleRow($saleId)->due_date);
        $this->assertSame(0, $this->countJournals());
        $this->assertSame($audit, $this->countRows('accounting_audit_log'));
    }

    public function testSaveValue_WorksOutTheDueDateFromTheDayOfTheInvoiceAndItsTerms(): void
    {
        $saleId = $this->invoiceDated('100.00', $this->daysAgo(5), 30);
        $sale   = $this->saleRow($saleId);

        $this->assertSame(30, (int) $sale->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+25 days')), $sale->due_date);
        $this->assertSame($sale->due_date, $this->position()->invoice($saleId)->dueDate);
        $this->assertSame(30, $this->position()->invoice($saleId)->termsDays);
    }

    public function testSaveValue_WorksOutTheDueDateFromTodayWhenTheInvoiceHasTermsAndNoTypedDay(): void
    {
        $this->grantInvoiceDate();

        $saleId = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, paymentTerms: '7');
        $sale   = $this->saleRow($saleId);

        $this->assertNull($sale->invoice_date);
        $this->assertSame(date('Y-m-d', strtotime('+7 days')), $sale->due_date);
    }

    public function testSaveValue_StoresNoTermsAndNoDueDateForAnInvoiceWithNoTermsOrZeroDays(): void
    {
        foreach ([null, '', '0', 0] as $terms) {
            $saleId = $this->invoiceDated('10.00', $this->daysAgo(2), $terms);

            $this->assertNull($this->saleRow($saleId)->payment_terms_days);
            $this->assertNull($this->saleRow($saleId)->due_date);
            $this->assertSame($this->daysAgo(2), $this->position()->invoice($saleId)->dueDate);
        }
    }

    public function testSaveValue_RefusesTermsThatAreNotAWholeNumberOfDaysAndSavesNothing(): void
    {
        $this->grantInvoiceDate();

        foreach (['-1', '2.5', 'soon', '3651'] as $terms) {
            $sales = $this->countSales();

            $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, paymentTerms: $terms), $terms);
            $this->assertSame(AccountingError::PaymentTermsInvalid, $this->failure()->error);
            $this->assertSame($sales, $this->countSales());
        }
    }

    public function testSaveValue_KeepsTheTermsOfADraftAndWorksOutItsDueDateWhenItIsIssued(): void
    {
        $this->grantInvoiceDate();

        $items = [$this->line($this->item('0', false), 0, '1', '100')];
        $draft = $this->save($items, [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, paymentTerms: 14);

        $this->assertSame(14, (int) $this->saleRow($draft)->payment_terms_days);
        $this->assertNull($this->saleRow($draft)->due_date);
        $this->assertSame(['invoice_date' => null, 'payment_terms_days' => 14, 'due_date' => null], $this->sales->getInvoiceTiming($draft));

        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, paymentTerms: 14));
        $this->assertSame(date('Y-m-d', strtotime('+14 days')), $this->saleRow($draft)->due_date);
    }

    /**
     * @return list<object>
     */
    private function dateAudit(int $saleId): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => 'invoice_date_typed'])->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    public function testSaveValue_WritesInTheAuditLogWhoTypedTheDayOfAnInvoiceAndWhenAtTheSaveThatTypedIt(): void
    {
        $this->grantInvoiceDate();

        $items = [$this->line($this->item('0', false), 0, '1', '100')];
        $draft = $this->save($items, [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(3));
        $typed = $this->dateAudit($draft);

        $this->assertCount(1, $typed, 'The day is on record from the save of the draft');
        $this->assertSame($this->employeeId, (int) $typed[0]->employee_id);
        $this->assertSame(['date' => $this->daysAgo(3), 'saved_on' => date('Y-m-d')], json_decode((string) $typed[0]->details, true));

        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $draft, null, invoiceDate: $this->daysAgo(3)));
        $this->assertCount(1, $this->dateAudit($draft), 'The same day typed again is not recorded again');

        $this->save($items, [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, invoiceDate: $this->daysAgo(3));

        $this->assertCount(1, $this->dateAudit($draft), 'Issuing the draft at the day it carries is not typing the day');
    }

    public function testSaveValue_WritesInTheAuditLogTheEmployeeWhoTypedTheDayOnADraftAndNotTheOneWhoIssuesIt(): void
    {
        $cycle = $this->referenceInvoice();
        $day   = $this->daysAgo(5);
        $typer = $this->employeeId;

        $this->grantInvoiceDate();

        $draft  = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $day);
        $issuer = $this->createEmployee();

        $this->employeeId = $issuer;

        $this->assertSame($draft, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, invoiceDate: $day));

        $this->employeeId = $typer;

        $typed = $this->dateAudit($draft);

        $this->assertCount(1, $typed);
        $this->assertSame($typer, (int) $typed[0]->employee_id, 'Whoever issues the draft did not type its day');
        $this->assertCount(2, $this->standingDated($draft, $day));
    }

    public function testSaveValue_WritesInTheAuditLogTheEmployeeWhoChangesTheDayOfADraftWhenIssuingIt(): void
    {
        $cycle = $this->referenceInvoice();
        $typer = $this->employeeId;

        $this->grantInvoiceDate();

        $draft  = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(5));
        $issuer = $this->createEmployee();

        $this->accountingDb()->table('grants')->ignore(true)->insert(['permission_id' => 'sales_invoice_date', 'person_id' => $issuer, 'menu_group' => 'home']);

        $this->employeeId = $issuer;

        $this->assertSame($draft, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, invoiceDate: $this->daysAgo(2)));

        $this->employeeId = $typer;

        $this->assertSame([$typer, $issuer], array_map(static fn (object $entry): int => (int) $entry->employee_id, $this->dateAudit($draft)));
    }

    public function testSaveValue_WritesNothingInTheAuditLogForAnInvoiceDatedToday(): void
    {
        $cycle = $this->referenceInvoice();

        $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'invoice_date_typed')->countAllResults());
    }

    public function testUpdate_RefusesToChangeTheDayTypedOnAPostedInvoiceAndLeavesItsJournalsAsTheyWere(): void
    {
        $saleId = $this->invoiceDated('250.00', $this->daysAgo(8), 10);
        $before = $this->journals($saleId);
        $row    = (array) $this->saleRow($saleId);

        $this->assertFalse($this->sales->update($saleId, ['invoice_date' => $this->daysAgo(2), 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);

        $this->assertFalse($this->sales->update($saleId, ['invoice_date' => date('Y-m-d'), 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);

        $this->assertSame($before, $this->journals($saleId));
        $this->assertSame($row, (array) $this->saleRow($saleId));
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testUpdate_RefusesToChangeTheDayTypedOnADraftToo(): void
    {
        $cycle = $this->referenceInvoice();

        $this->grantInvoiceDate();

        $draft = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(4));
        $row   = (array) $this->saleRow($draft);

        $this->assertSame([], $this->journals($draft));
        $this->assertFalse($this->sales->update($draft, ['invoice_date' => $this->daysAgo(1), 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);
        $this->assertSame($row, (array) $this->saleRow($draft));
    }

    public function testUpdate_AcceptsTheDayTypedOnAPostedInvoiceWhenItIsTheSameDay(): void
    {
        $saleId = $this->invoiceDated('250.00', $this->daysAgo(8));

        $this->assertTrue($this->sales->update($saleId, ['invoice_date' => $this->daysAgo(8), 'comment' => 'same day']));
        $this->assertSame('same day', $this->saleRow($saleId)->comment);
        $this->assertSame($this->daysAgo(8), $this->saleRow($saleId)->invoice_date);
    }

    public function testUpdate_RefusesToMoveTheTimeAnInvoiceWasSavedEvenToTheDayItIsDated(): void
    {
        $saleId = $this->invoiceDated('250.00', $this->daysAgo(8));

        $this->assertFalse($this->sales->update($saleId, ['sale_time' => $this->daysAgo(8) . ' 09:00:00', 'comment' => 'x']));
        $this->assertSame(AccountingError::SaleLocked, $this->failure()->error);
        $this->assertSame(date('Y-m-d'), substr((string) $this->saleRow($saleId)->sale_time, 0, 10));
    }

    public function testUpdate_ChangesThePaymentTermsOfAnIssuedInvoiceAndNothingInItsJournals(): void
    {
        $case   = $this->referenceCase('T-31', 'due_date', 'due-date-is-the-invoice-date-plus-its-payment-terms', 'exact');
        $saleId = $this->invoiceDated('395.39', $this->daysAgo(2), 1);
        $before = $this->journals($saleId);

        $this->assertSame($this->daysAgo(1), $this->saleRow($saleId)->due_date);

        foreach ([[2, $this->daysAgo(0)], [30, date('Y-m-d', strtotime('+28 days'))], [null, null]] as [$terms, $due]) {
            $this->assertTrue($this->sales->update($saleId, ['payment_terms_days' => $terms === null ? '' : (string) $terms, 'comment' => 'terms', 'changed_by' => $this->employeeId]));

            $sale = $this->saleRow($saleId);

            $this->assertSame($terms, $sale->payment_terms_days === null ? null : (int) $sale->payment_terms_days);
            $this->assertSame($due, $sale->due_date);
            $this->assertSame($due ?? $this->daysAgo(2), $this->position()->invoice($saleId)->dueDate);
            $this->assertSame($before, $this->journals($saleId));
        }

        $this->assertSameAsFixture(['terms_editable_after_issue' => $case['expected']['terms_editable_after_issue'], 'journal_changes_with_the_terms' => $case['expected']['journal_changes_with_the_terms']], ['terms_editable_after_issue' => true, 'journal_changes_with_the_terms' => $before !== $this->journals($saleId)], 'terms of an issued invoice');
        $this->assertTies();
    }

    public function testUpdate_WritesInTheAuditLogWhatTheTermsWereAndWhatTheyBecameAndWritesNothingWhenTheyStay(): void
    {
        $saleId = $this->invoiceDated('100.00', $this->daysAgo(2), 5);

        $this->assertTrue($this->sales->update($saleId, ['payment_terms_days' => '5', 'comment' => 'same', 'changed_by' => $this->employeeId]));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'payment_terms_changed')->countAllResults());

        $this->assertTrue($this->sales->update($saleId, ['payment_terms_days' => '9', 'comment' => 'longer', 'changed_by' => $this->employeeId]));

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => 'payment_terms_changed'])->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame($this->employeeId, (int) $entries[0]->employee_id);
        $this->assertSame(['from' => 5, 'to' => 9, 'due_date' => date('Y-m-d', strtotime('+7 days'))], json_decode((string) $entries[0]->details, true));
    }

    public function testUpdate_RefusesTermsThatAreNotAWholeNumberOfDaysAndChangesNothing(): void
    {
        $saleId = $this->invoiceDated('100.00', $this->daysAgo(2), 5);

        foreach (['-3', '1.5', 'x', '99999'] as $terms) {
            $this->assertFalse($this->sales->update($saleId, ['payment_terms_days' => $terms, 'comment' => 'bad']), $terms);
            $this->assertSame(AccountingError::PaymentTermsInvalid, $this->failure()->error);
        }

        $this->assertSame(5, (int) $this->saleRow($saleId)->payment_terms_days);
        $this->assertSame('test', $this->saleRow($saleId)->comment);
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testUpdate_TakesNoTermsForASaleThatIsNotAnIssuedInvoiceOrWhileAccountingIsOff(): void
    {
        $pos = $this->save([$this->line($this->item('0', false), 0, '1', '40')], [], [$this->payment(lang('Sales.cash'), '40.00')], SALE_TYPE_POS);

        $this->assertTrue($this->sales->update($pos, ['payment_terms_days' => '30', 'comment' => 'pos']));
        $this->assertNull($this->saleRow($pos)->payment_terms_days);

        $invoice = $this->invoiceOf('100.00');

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertTrue($this->sales->update($invoice, ['payment_terms_days' => '30', 'comment' => 'off']));
        $this->assertNull($this->saleRow($invoice)->payment_terms_days);
        $this->assertNull($this->saleRow($invoice)->due_date);
    }

    public function testUpdate_ChangesTheTermsOfAnInvoiceInAClosedPeriodBecauseNoJournalMoves(): void
    {
        $saleId = $this->invoiceDated('100.00', $this->daysAgo(10), 5);

        $this->closeThrough($this->daysAgo(2));

        $before = $this->journals($saleId);

        $this->assertTrue($this->sales->update($saleId, ['payment_terms_days' => '60', 'comment' => 'late', 'changed_by' => $this->employeeId]));
        $this->assertSame(60, (int) $this->saleRow($saleId)->payment_terms_days);
        $this->assertSame($before, $this->journals($saleId));
    }

    // ---------------------------------------------------------------------------------------
    // The draft invoice and the quote: their lifecycle (WP14)
    // ---------------------------------------------------------------------------------------

    /**
     * The reference invoice with its two items taxed 15%, the way the register taxes them.
     *
     * @return array{items: list<array<string, mixed>>, taxes: list<array<string, mixed>>, cream: int, perfume: int}
     */
    private function taxedReference(): array
    {
        $cycle = $this->referenceInvoice();

        foreach ([$cycle['cream'], $cycle['perfume']] as $itemId) {
            $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => '15']);
        }

        return $cycle;
    }

    /**
     * @return list<object>
     */
    private function lifecycleAudit(int $saleId, string $action): array
    {
        return $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => $action])->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    public function testIssueDraft_PostsNothingForTheDraftAndTheInvoiceAndTheCostWhenItIsIssuedAsTheReferenceSystemDoes(): void
    {
        $case  = $this->lifecycleCase('draft-then-issue');
        $cycle = $this->taxedReference();
        $stock = $this->stock($cycle['cream']);
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame($case['expected']['after_saving']['journals'], count($this->journals($draft)));
        $this->assertSame($case['expected']['after_saving']['stock_taken'], $this->stockTaken($cycle['cream'], $stock));
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        $this->assertEqualsCanonicalizing($case['expected']['after_issuing']['journals'], array_column($this->standing($draft), 'entry'));
        $this->assertSame($case['expected']['after_issuing']['stock_taken'], $this->stockTaken($cycle['cream'], $stock));
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame(
            [$this->customerAccount() . ':816.50:0.00', $this->accountId('sales_revenue') . ':0.00:710.00', $this->accountId('tax_output') . ':0.00:106.50'],
            $this->standing($draft, 'revenue')[0]['lines'],
        );
        $this->assertSame([$this->accountId('cogs') . ':400.00:0.00', $this->stockAccount() . ':0.00:400.00'], $this->standing($draft, 'cogs')[0]['lines']);
        $this->assertTies();
    }

    public function testIssueDraft_WorksTheTaxesOutAsTheRegisterDoesForTheSameLines(): void
    {
        $cream   = $this->taxedItem('40.00', true, '15');
        $service = $this->taxedItem('0', false, '15');
        $lines   = [['item' => $cream, 'quantity' => '3', 'price' => '75', 'discount' => '10'], ['item' => $service, 'quantity' => '2', 'price' => '33.33']];

        $rung  = $this->ringUp($lines);
        $draft = $this->draftInvoice([$this->line($cream, 0, '3', '75', '40.00', '10'), $this->line($service, 1, '2', '33.33')]);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        $taxesOf = fn (int $saleId): array => array_map(
            static fn (object $row): string => $row->tax_type . ':' . bcadd((string) $row->item_tax_amount, '0', 4),
            $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->orderBy('line', 'ASC')->get()->getResult(),
        );

        $this->assertNotSame([], $taxesOf($rung['sale']));
        $this->assertSame($taxesOf($rung['sale']), $taxesOf($draft));
        $this->assertSame($this->standing($rung['sale'], 'revenue')[0]['lines'], $this->standing($draft, 'revenue')[0]['lines']);
    }

    public function testIssueDraft_IssuesARegisterDraftLikeOneThatTheRegisterTookUpAndCompleted(): void
    {
        $cream = $this->taxedItem('40.00', true, '15');
        $lines = [['item' => $cream, 'quantity' => '2', 'price' => '100']];

        $viaRegister = $this->ringUp($lines);
        $draft       = $this->draftInvoice([$this->line($cream, 0, '2', '100', '40.00')]);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        foreach (['revenue', 'cogs'] as $entry) {
            $this->assertSame($this->standing($viaRegister['sale'], $entry)[0]['lines'], $this->standing($draft, $entry)[0]['lines'], $entry);
        }
    }

    public function testIssueDraft_WorksTheTaxOutWithTheScaleOfTheRegisterWhateverScaleTheCallerLeft(): void
    {
        $cycle  = $this->taxedReference();
        $draft  = $this->draftInvoice($cycle['items']);
        $before = bcscale();

        bcscale(0);

        try {
            $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
            $this->assertSame(0, bcscale(), 'the scale of the caller is put back');
        } finally {
            bcscale($before);
        }

        $this->assertSame($this->accountId('tax_output') . ':0.00:106.50', $this->standing($draft, 'revenue')[0]['lines'][2]);
    }

    public function testIssueDraft_SharesTheDiscountOnTheWholeOfTheDraftAsTheRegisterDoes(): void
    {
        $cycle = $this->discountedReferenceInvoice();

        foreach ([$cycle['cream'], $cycle['perfume']] as $itemId) {
            $this->accountingDb()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => '15']);
        }

        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, $cycle['discount']);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame(
            [$this->customerAccount() . ':734.85:0.00', $this->accountId('sales_revenue') . ':0.00:639.00', $this->accountId('tax_output') . ':0.00:95.85'],
            $this->standing($draft, 'revenue')[0]['lines'],
        );
        $this->assertSame(['0' => '27.0000', '1' => '44.0000'], $this->sharesOf($draft));
    }

    public function testIssueDraft_TakesTheCostOfTheItemAtTheMomentOfTheIssue(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->accountingDb()->table('items')->where('item_id', $cycle['cream'])->update(['cost_price' => '45.00']);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame([$this->accountId('cogs') . ':420.00:0.00', $this->stockAccount() . ':0.00:420.00'], $this->standing($draft, 'cogs')[0]['lines']);
    }

    public function testIssueDraft_PostsThePaymentsTheDraftHolds(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->save($cycle['items'], [], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertSame([], $this->journals($draft));
        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertCount(3, $this->journals($draft), 'the invoice, the payment and the cost');
        $this->assertSame('300.00', bcadd((string) $this->accountingDb()->table('sales_payments')->where('sale_id', $draft)->get()->getRow()->payment_amount, '0', 2));
        $this->assertTies();
    }

    public function testIssueDraft_PostsInTheBranchOfTheDraft(): void
    {
        $second  = $this->createBranch();
        $service = $this->item('0', false);
        $draft   = $this->save([$this->line($service, 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        $branches = array_unique(array_column($this->accountingDb()->table('accounting_journals')->select('branch_id')->whereIn('journal_id', array_column($this->journals($draft), 'id'))->get()->getResultArray(), 'branch_id'));

        $this->assertSame([(string) $second], array_values($branches));
    }

    public function testIssueDraft_DatesTheInvoiceByTheDayTypedAtTheIssueAndWritesWhoTypedItAndWhoIssuedTheDraft(): void
    {
        $this->grantInvoiceDate();

        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $day   = $this->daysAgo(3);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId, invoiceDate: $day));

        $this->assertSame($day, $this->saleRow($draft)->invoice_date);
        $this->assertEqualsCanonicalizing(['revenue', 'cogs'], array_column($this->standingDated($draft, $day), 'entry'));
        $this->assertSame([$day], array_values(array_unique(array_column($this->standing($draft), 'date'))));
        $this->assertCount(1, $this->dateAudit($draft));

        $issued = $this->lifecycleAudit($draft, 'draft_issued');

        $this->assertCount(1, $issued);
        $this->assertSame($this->employeeId, (int) $issued[0]->employee_id);
        $this->assertSame(['number' => null, 'date' => $day], json_decode((string) $issued[0]->details, true));
        $this->assertTies();
    }

    public function testIssueDraft_WritesTheNumberTheInvoiceTookInTheAuditOfTheIssue(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesInvoice, ['enabled' => true, 'prefix' => 'INV-', 'padding' => 4]);

        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        $issued = $this->lifecycleAudit($draft, 'draft_issued');

        $this->assertCount(1, $issued);
        $this->assertSame('INV-0001', json_decode((string) $issued[0]->details, true)['number']);
        $this->assertSame($this->sales->getInvoiceNumber($draft), json_decode((string) $issued[0]->details, true)['number']);
    }

    public function testIssueDraft_KeepsTheDayTheDraftWasSavedWithWhenNoneIsGivenAtTheIssue(): void
    {
        $this->grantInvoiceDate();

        $cycle = $this->taxedReference();
        $day   = $this->daysAgo(4);
        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $day);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame($day, $this->saleRow($draft)->invoice_date);
        $this->assertSame([$day], array_values(array_unique(array_column($this->standing($draft), 'date'))));
    }

    public function testIssueDraft_DatesTheInvoiceTodayWhenNoDayWasTypedAnywhere(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertNull($this->saleRow($draft)->invoice_date);
        $this->assertSame([date('Y-m-d')], array_values(array_unique(array_column($this->standing($draft), 'date'))));
    }

    public function testIssueDraft_RejectsADayInAClosedPeriodAndLeavesTheDraftAsItWas(): void
    {
        $this->grantInvoiceDate();
        $this->closeThrough($this->daysAgo(5));

        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $stock = $this->stock($cycle['cream']);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId, invoiceDate: $this->daysAgo(10)));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame([], $this->journals($draft));
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertNull($this->saleRow($draft)->invoice_number);
        $this->assertSame([], $this->lifecycleAudit($draft, 'draft_issued'));

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId, invoiceDate: $this->daysAgo(4)), 'the day after the closing is open');
        $this->assertSame([$this->daysAgo(4)], array_values(array_unique(array_column($this->standing($draft), 'date'))));
    }

    public function testIssueDraft_RejectsTodayWhenThePeriodOfTodayIsClosedAndLeavesTheDraft(): void
    {
        $this->closeThrough(date('Y-m-d'));

        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame(0, $this->countJournals());
    }

    public function testIssueDraft_RejectsADayInTheClosedPeriodOfTheBranchOfTheDraftOnly(): void
    {
        $this->grantInvoiceDate();

        $second = $this->createBranch();

        $this->closeThrough($this->daysAgo(5), $second);

        $inSecond = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);
        $inFirst  = $this->draftInvoice([$this->line($this->item('0', false), 0, '1', '100')]);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($inSecond, $this->employeeId, invoiceDate: $this->daysAgo(8)));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($inFirst, $this->sales->issueDraft($inFirst, $this->employeeId, invoiceDate: $this->daysAgo(8)));
    }

    /**
     * @return array<string, array{0: string, 1: AccountingError}>
     */
    public static function badDayProvider(): array
    {
        return [
            'a day after today'      => ['+1 day', AccountingError::InvoiceDateInvalid],
            'a day that is not one'  => ['2026-02-30', AccountingError::InvoiceDateInvalid],
            'not a date'             => ['yesterday', AccountingError::InvoiceDateInvalid],
            'a day before the books' => ['2019-12-31', AccountingError::InvoiceDateBeforeStart],
        ];
    }

    #[DataProvider('badDayProvider')]
    public function testIssueDraft_RejectsADayThatIsNotUpToTodayOrIsBeforeTheBooksAndLeavesTheDraft(string $day, AccountingError $error): void
    {
        $this->grantInvoiceDate();

        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $typed = str_starts_with($day, '+') ? date('Y-m-d', strtotime($day)) : $day;

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId, invoiceDate: $typed));
        $this->assertSame($error, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame([], $this->journals($draft));
    }

    public function testIssueDraft_RejectsADayTypedByAnEmployeeWhoMayNotTypeItAndAcceptsOneWhoMay(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId, invoiceDate: $this->daysAgo(2)));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);
        $this->assertSame([], $this->journals($draft));

        $this->grantInvoiceDate();

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId, invoiceDate: $this->daysAgo(2)));
    }

    public function testIssueDraft_TakesTheTermsTypedAtTheIssueAndKeepsTheOnesOfTheDraftOtherwise(): void
    {
        $cycle  = $this->taxedReference();
        $first  = $this->draftInvoice($cycle['items']);
        $second = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, paymentTerms: 14);

        $this->assertSame($first, $this->sales->issueDraft($first, $this->employeeId, paymentTerms: '30'));
        $this->assertSame(30, (int) $this->saleRow($first)->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $this->saleRow($first)->due_date);

        $this->assertSame($second, $this->sales->issueDraft($second, $this->employeeId));
        $this->assertSame(14, (int) $this->saleRow($second)->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+14 days')), $this->saleRow($second)->due_date);
    }

    public function testIssueDraft_RejectsTermsThatAreNotAWholeNumberOfDays(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId, paymentTerms: 'a month'));
        $this->assertSame(AccountingError::PaymentTermsInvalid, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
    }

    public function testIssueDraft_IssuesADraftOnlyOnceAndTheSecondTryMovesNothing(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        $stock    = $this->stock($cycle['cream']);
        $journals = $this->countJournals();
        $audit    = count($this->lifecycleAudit($draft, 'draft_issued'));

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame(AccountingError::DraftNotFound, $this->failure()->error);
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame($journals, $this->countJournals());
        $this->assertCount($audit, $this->lifecycleAudit($draft, 'draft_issued'));
    }

    public function testSaveValue_RejectsCompletingASaleThatIsNotWaitingToBeCompleted(): void
    {
        $cycle    = $this->taxedReference();
        $invoice  = $this->save($cycle['items'], $cycle['taxes']);
        $stock    = $this->stock($cycle['cream']);
        $journals = $this->countJournals();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $invoice));
        $this->assertSame(AccountingError::DraftNotFound, $this->failure()->error);
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame($journals, $this->countJournals());

        $cancelled = $this->draftInvoice($cycle['items']);
        $this->sales->update_sale_status($cancelled, CANCELED);

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $cancelled));
        $this->assertSame(AccountingError::DraftNotFound, $this->failure()->error);
    }

    public function testSaveValue_RejectsSuspendingAnIssuedInvoiceAndKeepsItsLinesItsPaymentsAndItsNumber(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesInvoice, ['enabled' => true, 'prefix' => 'INV-', 'padding' => 4]);

        $cycle = $this->taxedReference();
        $cash  = [$this->payment(lang('Sales.cash'), '100.00')];
        $draft = $this->save($cycle['items'], [], $cash, SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        $before     = $this->saleRow($draft);
        $paymentIds = array_column($this->accountingDb()->table('sales_payments')->where('sale_id', $draft)->get()->getResultArray(), 'payment_id');
        $lines      = $this->accountingDb()->table('sales_items')->where('sale_id', $draft)->countAllResults();
        $stock      = $this->stock($cycle['cream']);
        $journals   = $this->countJournals();

        $this->assertSame('INV-0001', $before->invoice_number);
        $this->assertCount(1, $paymentIds);

        foreach ([SALE_TYPE_INVOICE, SALE_TYPE_QUOTE, SALE_TYPE_WORK_ORDER] as $type) {
            $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [], $type, -1, true, null, SUSPENDED, $draft), "saved as {$type}");
            $this->assertSame(AccountingError::DraftNotFound, $this->failure()->error);
        }

        $this->assertEquals($before, $this->saleRow($draft));
        $this->assertSame($paymentIds, array_column($this->accountingDb()->table('sales_payments')->where('sale_id', $draft)->get()->getResultArray(), 'payment_id'), 'its payments were not written again');
        $this->assertSame($lines, $this->accountingDb()->table('sales_items')->where('sale_id', $draft)->countAllResults());
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame($journals, $this->countJournals());

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId), 'and it is not issued a second time');
        $this->assertSame($journals, $this->countJournals());
        $this->assertTies();
    }

    public function testSaveValue_RejectsSuspendingACancelledDraftAndLeavesItCancelled(): void
    {
        $cycle     = $this->taxedReference();
        $cancelled = $this->cancelledDraft($cycle['items']);
        $lines     = $this->accountingDb()->table('sales_items')->where('sale_id', $cancelled)->countAllResults();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $cancelled));
        $this->assertSame(AccountingError::DraftNotFound, $this->failure()->error);
        $this->assertSame((string) CANCELED, (string) $this->saleRow($cancelled)->sale_status);
        $this->assertSame($lines, $this->accountingDb()->table('sales_items')->where('sale_id', $cancelled)->countAllResults());
    }

    public function testIssueDraft_RejectsADraftThatWasSavedAgainAfterItWasReadAndIssuesNothingOfWhatItHeldBefore(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $stock = $this->stock($cycle['cream']);
        $phase = 'waiting';

        $listen = function (Query $query) use (&$phase, $cycle, $draft): void {
            $sql = $query->getQuery();

            if (str_contains($sql, 'GET_LOCK')) {
                $phase = $phase === 'waiting' ? 'locked' : $phase;

                return;
            }

            if ($phase !== 'locked' || str_contains($sql, 'IS_USED_LOCK')) {
                return;
            }

            $phase = 'saved again';

            $db     = $this->accountingDb();
            $result = $db->resultID;

            $this->save($cycle['items'], [], [$this->payment(lang('Sales.cash'), '100.00')], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $draft);

            $db->resultID = $result;
        };

        Events::on('DBQuery', $listen);

        try {
            $result = $this->sales->issueDraft($draft, $this->employeeId);
        } finally {
            Events::removeListener('DBQuery', $listen);
        }

        $this->assertSame('saved again', $phase, 'the register saved the draft between the read and the lock');
        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame(AccountingError::DraftChanged, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame(1, $this->accountingDb()->table('sales_payments')->where('sale_id', $draft)->countAllResults(), 'what the register saved is still there');
        $this->assertSame(0, $this->countJournals());
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId), 'read again, it is issued with what it holds now');
        $this->assertSame(1, $this->accountingDb()->table('sales_payments')->where('sale_id', $draft)->countAllResults());
        $this->assertTies();
    }

    public function testSaveValue_RejectsIssuingADraftWhoseFingerprintIsNotTheOneItHasAnymore(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $drafts = new DraftInvoice($this->accountingDb());
        $read  = $drafts->forIssue($draft)->fingerprint;

        $this->assertSame($read, $drafts->fingerprint($draft), 'nothing changed it');
        $this->assertSame($draft, $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $draft, null, ReturnDocument::CreditNote, null, null, null, null, $read), 'saved again with the same lines the fingerprint is the same');

        $this->assertSame($draft, $this->save([$this->line($this->item('0', false), 0, '1', '10')], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $draft));
        $this->assertNotSame($read, $drafts->fingerprint($draft), 'other lines are another fingerprint');
        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft, null, ReturnDocument::CreditNote, null, null, null, null, $read));
        $this->assertSame(AccountingError::DraftChanged, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame(0, $this->countJournals());
    }

    public function testIssueDraft_IssuesTheDraftOnceAndTakesTheStockOnceWhenTwoRequestsRunAtTheSameTime(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $stock = $this->stock($cycle['cream']);

        $outcomes = $this->raceTwoProcesses('issueDraft', [$draft, $this->employeeId], [$draft, $this->employeeId]);

        $this->assertSame([false, true], [min($outcomes), max($outcomes)], 'one issues the draft and the other is told it is gone');
        $this->assertEqualsWithDelta($stock - 4, $this->stock($cycle['cream']), 0.0001);
        $this->assertCount(2, $this->standing($draft));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $draft, 'action' => 'draft_issued'])->countAllResults());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notADraftProvider(): array
    {
        return [
            'a sale completed at the register' => ['pos'],
            'an invoice that was issued'       => ['invoice'],
            'a quote'                          => ['quote'],
            'a draft that was cancelled'       => ['cancelled'],
            'a sale that is not there'         => ['missing'],
        ];
    }

    #[DataProvider('notADraftProvider')]
    public function testIssueDraft_RejectsWhatIsNotADraftInvoice(string $kind): void
    {
        $cycle = $this->taxedReference();

        $saleId = match ($kind) {
            'pos'       => $this->save([$this->line($this->item('0', false), 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS),
            'invoice'   => $this->save($cycle['items'], $cycle['taxes']),
            'quote'     => $this->quote(),
            'cancelled' => $this->cancelledDraft($cycle['items']),
            default     => 999999,
        };

        $journals = $this->countJournals();
        $before   = $this->saleRow($saleId);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($saleId, $this->employeeId));
        $this->assertSame(AccountingError::DraftNotFound, $this->failure()->error);
        $this->assertSame($journals, $this->countJournals());
        $this->assertEquals($before, $this->saleRow($saleId));
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function cancelledDraft(array $items): int
    {
        $draft = $this->draftInvoice($items);

        $this->sales->update_sale_status($draft, CANCELED);

        return $draft;
    }

    public function testIssueDraft_IssuesADraftPaidWithAGiftCardAndDebitsTheCardOnce(): void
    {
        $db = $this->accountingDb();
        $db->table('giftcards')->insert(['giftcard_number' => 7771235, 'value' => 500, 'deleted' => 0, 'person_id' => null]);

        $cycle = $this->taxedReference();
        $draft = $this->save($cycle['items'], [], [$this->payment(lang('Sales.giftcard') . ':7771235', '100.00')], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertEqualsWithDelta(400.0, (float) $db->table('giftcards')->where('giftcard_number', 7771235)->get()->getRow()->value, 0.001, 'the card is debited when the draft is saved');

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertEqualsWithDelta(400.0, (float) $db->table('giftcards')->where('giftcard_number', 7771235)->get()->getRow()->value, 0.001, 'the card is not debited a second time');
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame(1, $db->table('sales_payments')->where('sale_id', $draft)->like('payment_type', ':7771235')->countAllResults());

        $db->table('giftcards')->where('giftcard_number', 7771235)->delete();
    }

    public function testIssueDraft_IssuesADraftPaidWithRewardPointsAndSpendsThePointsOnce(): void
    {
        $db = $this->accountingDb();
        $db->table('customers')->where('person_id', $this->customerId)->update(['points' => 200]);

        $cycle = $this->taxedReference();
        $draft = $this->save($cycle['items'], [], [$this->payment(lang('Sales.rewards'), '50.00')], SALE_TYPE_INVOICE, $this->customerId, true, null, SUSPENDED);

        $this->assertSame('150', (string) $db->table('customers')->where('person_id', $this->customerId)->get()->getRow()->points);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame('150', (string) $db->table('customers')->where('person_id', $this->customerId)->get()->getRow()->points, 'the points are spent once');

        $db->table('customers')->where('person_id', $this->customerId)->update(['points' => null]);
    }

    public function testIssueDraft_KeepsTheCardOfADraftTheCardWasUsedUpOnElsewhereBecauseTheDraftHeldTheValue(): void
    {
        $db = $this->accountingDb();
        $db->table('giftcards')->insert(['giftcard_number' => 7771237, 'value' => 100, 'deleted' => 0, 'person_id' => null]);

        $cycle = $this->taxedReference();
        $draft = $this->save($cycle['items'], [], [$this->payment(lang('Sales.giftcard') . ':7771237', '100.00')], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->assertEqualsWithDelta(0.0, (float) $db->table('giftcards')->where('giftcard_number', 7771237)->get()->getRow()->value, 0.001);
        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId), 'the value the draft held is still its own when it is issued');
        $this->assertEqualsWithDelta(0.0, (float) $db->table('giftcards')->where('giftcard_number', 7771237)->get()->getRow()->value, 0.001);

        $db->table('giftcards')->where('giftcard_number', 7771237)->delete();
    }

    public function testIssueDraft_UndoesTheWholeIssueWhenTheCostCannotBePostedAndKeepsTheDraft(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->stockAccount())->update(['active' => 0]);

        $stock = $this->stock($cycle['cream']);

        $this->assertSame(ACCOUNTING_FAILED, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame(PostingError::AccountNotPostable, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame(0, $this->countJournals());
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame([], $this->lifecycleAudit($draft, 'draft_issued'));
        $this->assertCount(2, $this->accountingDb()->table('sales_items')->where('sale_id', $draft)->get()->getResult(), 'the lines of the draft are still there');
    }

    public function testIssueDraft_IssuesTheDraftOfAnInvoiceNotYetDeliveredWithoutTheCostAndWithoutTakingTheStock(): void
    {
        $cycle = $this->taxedReference();
        $stock = $this->stock($cycle['cream']);
        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, false, null, SUSPENDED);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame(['revenue'], array_column($this->standing($draft), 'entry'));
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame('0', (string) $this->saleRow($draft)->delivered);
    }

    public function testIssueDraft_WorksWhileAccountingIsOffAndPostsNothing(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $cycle = $this->taxedReference();
        $stock = $this->stock($cycle['cream']);
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId, invoiceDate: $this->daysAgo(3), paymentTerms: '30'));
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($draft)->sale_status);
        $this->assertEqualsWithDelta($stock - 4, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame(0, $this->countJournals());
        $this->assertNull($this->saleRow($draft)->invoice_date);
        $this->assertNull($this->saleRow($draft)->payment_terms_days);
        $this->assertSame([], $this->lifecycleAudit($draft, 'draft_issued'));
    }

    public function testSaveValue_PostsNothingAndMovesNoStockForAQuoteInAnyStateAsTheReferenceSystemDoes(): void
    {
        $case  = $this->lifecycleCase('quote-states-and-no-posting');
        $cycle = $this->referenceInvoice();
        $stock = $this->stock($cycle['cream']);

        foreach ([QuoteStatus::Draft, QuoteStatus::Open] as $state) {
            $quote = $this->quote($state, $cycle['items']);

            $this->assertSame([], $this->journals($quote), $state->name);
            $this->assertSame($case['expected']['stock_taken'], $this->stockTaken($cycle['cream'], $stock), $state->name);
        }

        $this->allowEmployeeEverywhere();

        $quote = $this->quote(null, $cycle['items']);
        $this->assertSame(['open'], array_slice($case['expected']['states'], 0, 1));

        (new QuoteLifecycle())->change($quote, QuoteStatus::Approved, $this->employeeId);

        $this->assertSame(strtolower(QuoteStatus::Approved->name), $case['expected']['states'][1]);
        $this->assertSame($case['expected']['journals'], count($this->journals($quote)));
        $this->assertSame(0, $this->countJournals());
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
    }

    public function testSaveValue_SavesANewQuoteOpenAndKeepsItsStateApartFromTheStatusOfTheSale(): void
    {
        $quote = $this->quote();
        $row   = $this->saleRow($quote);

        $this->assertSame((string) SUSPENDED, (string) $row->sale_status);
        $this->assertSame((string) QuoteStatus::Open->value, (string) $row->quote_status);
        $this->assertNull($row->source_quote_id);
    }

    public function testSaveValue_SavesAQuoteAsADraftTakingNoNumberAndOpensItWhenItIsSavedAsAQuote(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesQuote, ['enabled' => true, 'prefix' => 'QT-', 'padding' => 3]);

        $items = $this->referenceInvoice()['items'];
        $draft = $this->quote(QuoteStatus::Draft, $items);

        $this->assertSame((string) QuoteStatus::Draft->value, (string) $this->saleRow($draft)->quote_status);
        $this->assertNull($this->saleRow($draft)->quote_number);
        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $draft, null, ReturnDocument::CreditNote, null, null, QuoteStatus::Draft), 'saved as a draft again');
        $this->assertNull($this->saleRow($draft)->quote_number);

        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $draft), 'saved as a quote');
        $this->assertSame((string) QuoteStatus::Open->value, (string) $this->saleRow($draft)->quote_status);
        $this->assertSame('QT-001', $this->saleRow($draft)->quote_number);
    }

    public function testSaveValue_KeepsAQuoteThatIsNoLongerADraftFromGoingBackToOne(): void
    {
        $this->allowEmployeeEverywhere();

        $items = $this->referenceInvoice()['items'];
        $quote = $this->quote(null, $items);

        (new QuoteLifecycle())->change($quote, QuoteStatus::Approved, $this->employeeId);

        $this->assertSame($quote, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $quote, null, ReturnDocument::CreditNote, null, null, QuoteStatus::Draft));
        $this->assertSame((string) QuoteStatus::Approved->value, (string) $this->saleRow($quote)->quote_status);

        $this->assertSame($quote, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $quote));
        $this->assertSame((string) QuoteStatus::Approved->value, (string) $this->saleRow($quote)->quote_status, 'saving a quote again does not reopen it');
    }

    public function testSaveValue_KeepsTheStateOfAQuoteThatWasSavedBeforeStatesExistedAsOpenWhenItIsSavedAgain(): void
    {
        $items = $this->referenceInvoice()['items'];
        $quote = $this->quote(null, $items);

        $this->accountingDb()->table('sales')->where('sale_id', $quote)->update(['quote_status' => null]);

        $this->assertSame($quote, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $quote));
        $this->assertSame(QuoteStatus::Open, (new QuoteLifecycle())->statusOf($quote));
    }

    public function testSaveValue_ConvertsTheQuoteExactlyWhenTheInvoiceMadeFromItIsSavedAsAnIssuedInvoice(): void
    {
        $case  = $this->lifecycleCase('quote-converted-by-saving-the-invoice');
        $this->allowEmployeeEverywhere();
        $cycle = $this->referenceInvoice();
        $quote = $this->quote(null, $cycle['items']);

        (new QuoteLifecycle())->change($quote, QuoteStatus::Approved, $this->employeeId);

        $this->assertSame(strtolower(QuoteStatus::Approved->name), $case['expected']['after_converting']['quote_state']);
        $this->assertSame($case['expected']['after_converting']['journals'], count($this->journals($quote)));
        $this->assertSame(0, $this->countJournals(), 'converting posts nothing: the quote is converted when the invoice is saved');

        $invoice = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote);

        $this->assertGreaterThan(0, $invoice);
        $this->assertNotSame($quote, $invoice, 'the quote stays and the invoice is a sale of its own');
        $this->assertSame(strtolower(QuoteStatus::Converted->name), $case['expected']['after_saving_the_invoice']['quote_state']);
        $this->assertSame(QuoteStatus::Converted, (new QuoteLifecycle())->statusOf($quote));
        $this->assertEqualsCanonicalizing($case['expected']['after_saving_the_invoice']['journals'], array_column($this->standing($invoice), 'entry'));
        $this->assertSame([], $this->journals($quote));
        $this->assertSame((string) $quote, (string) $this->saleRow($invoice)->source_quote_id);
        $this->assertSame($invoice, (new QuoteLifecycle())->invoiceOf($quote));

        $audit = $this->lifecycleAudit($quote, 'quote_converted');

        $this->assertCount(1, $audit);
        $this->assertSame(['from' => 'Approved', 'invoice_sale_id' => $invoice], json_decode((string) $audit[0]->details, true));
        $this->assertTies();
    }

    public function testSaveValue_ConvertsAnOpenQuoteToo(): void
    {
        $this->allowEmployeeEverywhere();

        $cycle = $this->referenceInvoice();
        $quote = $this->quote(null, $cycle['items']);

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote));
        $this->assertSame(QuoteStatus::Converted, (new QuoteLifecycle())->statusOf($quote));
    }

    public function testSaveValue_KeepsTheQuoteWhileTheInvoiceMadeFromItIsOnlyADraftAndConvertsItWhenTheDraftIsIssued(): void
    {
        $this->allowEmployeeEverywhere();

        $cycle = $this->taxedReference();
        $quote = $this->quote(QuoteStatus::Open, $cycle['items']);
        $draft = $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote);

        $this->assertSame(QuoteStatus::Open, (new QuoteLifecycle())->statusOf($quote), 'a draft is not the invoice yet');
        $this->assertSame($quote, $this->sales->getSourceQuote($draft));
        $this->assertNull((new QuoteLifecycle())->invoiceOf($quote));

        $this->assertSame($draft, $this->save($cycle['items'], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $draft), 'the draft saved again keeps the quote it came from');
        $this->assertSame($quote, $this->sales->getSourceQuote($draft));

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame(QuoteStatus::Converted, (new QuoteLifecycle())->statusOf($quote));
        $this->assertSame($draft, (new QuoteLifecycle())->invoiceOf($quote));
    }

    public function testSaveValue_RejectsASecondInvoiceFromTheSameQuoteAndLeavesNothingOfIt(): void
    {
        $this->allowEmployeeEverywhere();

        $cycle = $this->referenceInvoice();
        $quote = $this->quote(null, $cycle['items']);

        $first = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote);

        $this->assertGreaterThan(0, $first);

        $sales    = $this->countSales();
        $journals = $this->countJournals();
        $stock    = $this->stock($cycle['cream']);
        $lines    = $this->countRows('sales_items');

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote));
        $this->assertSame(AccountingError::QuoteNotConvertible, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame($lines, $this->countRows('sales_items'));
        $this->assertEqualsWithDelta($stock, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame($first, (new QuoteLifecycle())->invoiceOf($quote));
    }

    public function testSaveValue_RejectsAnInvoiceFromAQuoteThatIsStillADraft(): void
    {
        $this->allowEmployeeEverywhere();

        $cycle = $this->referenceInvoice();
        $quote = $this->quote(QuoteStatus::Draft, $cycle['items']);
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote));
        $this->assertSame(AccountingError::QuoteNotConvertible, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(QuoteStatus::Draft, (new QuoteLifecycle())->statusOf($quote));
    }

    public function testSaveValue_RejectsAnInvoiceFromASaleThatIsNotAQuote(): void
    {
        $this->allowEmployeeEverywhere();

        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $sales   = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $invoice));
        $this->assertSame(AccountingError::QuoteNotFound, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
    }

    public function testSaveValue_RejectsAnInvoiceFromAQuoteOfABranchTheEmployeeDoesNotWorkInAndLeavesNothingOfIt(): void
    {
        $second   = $this->createBranch();
        $service  = $this->item('0', false);
        $quote    = $this->save([$this->line($service, 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);
        $clerk    = $this->employeeWithGrants([], [1]);
        $sales    = $this->countSales();
        $before   = $this->employeeId;

        $this->employeeId = $clerk;

        try {
            $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote));
        } finally {
            $this->employeeId = $before;
        }

        $failure = $this->sales->accountingFailure();

        $this->assertInstanceOf(BranchException::class, $failure);
        $this->assertSame(BranchError::NotAllowed, $failure->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(QuoteStatus::Open, (new QuoteLifecycle())->statusOf($quote));
    }

    public function testSaveValue_RejectsToSaveAConvertedQuoteAgainWhateverItIsSavedAs(): void
    {
        $items = $this->referenceInvoice()['items'];
        $quote = $this->quote(null, $items);

        $this->accountingDb()->table('sales')->where('sale_id', $quote)->update(['quote_status' => QuoteStatus::Converted->value]);

        $row = $this->saleRow($quote);

        foreach ([SALE_TYPE_QUOTE => SUSPENDED, SALE_TYPE_INVOICE => COMPLETED] as $type => $status) {
            $this->assertSame(ACCOUNTING_FAILED, $this->save($items, [], [], $type, -1, true, null, $status, $quote), "saved as type {$type}");
            $this->assertSame(AccountingError::QuoteLocked, $this->failure()->error);
        }

        $this->assertEquals($row, $this->saleRow($quote));
        $this->assertSame(0, $this->countJournals());
    }

    public function testDelete_RejectsToCancelAConvertedQuoteAndCancelsAnotherQuoteWithNothingToReverse(): void
    {
        $converted = $this->quote();
        $other     = $this->quote();

        $this->accountingDb()->table('sales')->where('sale_id', $converted)->update(['quote_status' => QuoteStatus::Converted->value]);

        $this->assertFalse($this->sales->delete($converted, false, true, $this->employeeId));
        $this->assertSame(AccountingError::QuoteLocked, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($converted)->sale_status);

        $this->assertTrue($this->sales->delete($other, false, true, $this->employeeId));
        $this->assertSame((string) CANCELED, (string) $this->saleRow($other)->sale_status);
        $this->assertSame(0, $this->countJournals());
    }

    public function testDeleteSuspendedSale_RejectsAConvertedQuoteAndDiscardsAnotherQuote(): void
    {
        $converted = $this->quote();
        $other     = $this->quote(QuoteStatus::Draft);

        $this->accountingDb()->table('sales')->where('sale_id', $converted)->update(['quote_status' => QuoteStatus::Converted->value]);

        $this->assertFalse($this->sales->delete_suspended_sale($converted));
        $this->assertSame(AccountingError::QuoteLocked, $this->failure()->error);
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($converted)->sale_status);

        $this->assertTrue($this->sales->delete_suspended_sale($other));
        $this->assertSame((string) CANCELED, (string) $this->saleRow($other)->sale_status);
    }

    public function testSaveValue_ConvertsTheQuoteWhileAccountingIsOffAndWritesNothingInTheAuditLog(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->allowEmployeeEverywhere();

        $cycle = $this->referenceInvoice();
        $quote = $this->quote(null, $cycle['items']);

        $invoice = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote);

        $this->assertGreaterThan(0, $invoice);
        $this->assertSame(QuoteStatus::Converted, (new QuoteLifecycle())->statusOf($quote));
        $this->assertSame([], $this->lifecycleAudit($quote, 'quote_converted'));
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_LeavesAQuoteStateOffAnInvoiceThatNamesNoQuoteAndASuspendedSaleOfThePointOfSale(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $draft   = $this->draftInvoice($cycle['items']);
        $pos     = $this->save([$this->line($this->item('0', false), 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, -1, true, null, SUSPENDED);

        foreach ([$invoice, $draft, $pos] as $saleId) {
            $this->assertNull($this->saleRow($saleId)->quote_status, (string) $saleId);
            $this->assertNull($this->saleRow($saleId)->source_quote_id, (string) $saleId);
        }

        $this->assertSame([], $this->journals($pos));
        $this->assertSame($pos, $this->save([$this->line($this->item('0', false), 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, -1, true, null, COMPLETED, $pos), 'a suspended sale of the point of sale is completed as it always was');
        $this->assertNotSame([], $this->journals($pos));
        $this->assertSame([], $this->lifecycleAudit($pos, 'draft_issued'), 'a sale of the point of sale is not a draft invoice');
    }

    public function testSaveValue_LeavesTheStateOfARegisterQuoteBehindWhenItIsCompletedAsAnInvoice(): void
    {
        $cycle = $this->referenceInvoice();
        $quote = $this->quote(null, $cycle['items']);

        $this->assertSame($quote, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $quote));
        $this->assertNull($this->saleRow($quote)->quote_status);
        $this->assertNull((new QuoteLifecycle())->statusOf($quote));
    }

    public function testSaveValue_WritesTheMoveOfADraftQuoteToOpenInTheAuditLogWithTheNumberItTook(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesQuote, ['enabled' => true, 'prefix' => 'QT-', 'padding' => 3]);

        $items = $this->referenceInvoice()['items'];
        $draft = $this->quote(QuoteStatus::Draft, $items);

        $this->assertSame([], $this->lifecycleAudit($draft, 'quote_status_changed'), 'a quote saved for the first time moves from nowhere');
        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $draft, null, ReturnDocument::CreditNote, null, null, QuoteStatus::Draft));
        $this->assertSame([], $this->lifecycleAudit($draft, 'quote_status_changed'), 'saved as a draft again it stays one');

        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $draft));

        $moves = $this->lifecycleAudit($draft, 'quote_status_changed');

        $this->assertCount(1, $moves);
        $this->assertSame($this->employeeId, (int) $moves[0]->employee_id);
        $this->assertSame(['from' => 'Draft', 'to' => 'Open', 'number' => 'QT-001'], json_decode((string) $moves[0]->details, true));

        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $draft));
        $this->assertCount(1, $this->lifecycleAudit($draft, 'quote_status_changed'), 'saved again in the state it has, nothing moved');
    }

    public function testSaveValue_WritesNoMoveOfAQuoteWhileTheBooksAreOff(): void
    {
        $items = $this->referenceInvoice()['items'];
        $draft = $this->quote(QuoteStatus::Draft, $items);

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame($draft, $this->save($items, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, $draft));

        $this->assertSame((string) QuoteStatus::Open->value, (string) $this->saleRow($draft)->quote_status);
        $this->assertSame([], $this->lifecycleAudit($draft, 'quote_status_changed'));
    }

    public function testSaveValue_WritesNoDraftIssuedForAQuoteOrASaleOfThePointOfSaleCompletedAsAnInvoice(): void
    {
        $cycle    = $this->referenceInvoice();
        $service  = [$this->line($this->item('0', false), 0, '1', '50')];
        $quote    = $this->quote(null, $cycle['items']);
        $pos      = $this->save($service, [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, -1, true, null, SUSPENDED);
        $draft    = $this->draftInvoice($cycle['items']);

        $this->assertSame($quote, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $quote));
        $this->assertSame($pos, $this->save($service, [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $pos));
        $this->assertSame($draft, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, $draft));

        $this->assertSame([], $this->lifecycleAudit($quote, 'draft_issued'), 'a quote was never a draft invoice');
        $this->assertSame([], $this->lifecycleAudit($pos, 'draft_issued'), 'neither was a sale of the point of sale');
        $this->assertCount(1, $this->lifecycleAudit($draft, 'draft_issued'), 'a draft invoice that the register completes is an issued one');
    }

    public function testIssueDraft_WritesNoStateOnTheInvoiceAndNoNumberFromTheQuoteSequence(): void
    {
        (new DocumentNumbering($this->accountingDb()))->configure(DocumentType::SalesQuote, ['enabled' => true, 'prefix' => 'QT-', 'padding' => 3]);

        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertNull($this->saleRow($draft)->quote_status);
        $this->assertNull($this->saleRow($draft)->quote_number);
        $this->assertSame(1, (new DocumentNumbering($this->accountingDb()))->sequence(DocumentType::SalesQuote)->nextNumber);
    }

    public function testIssueDraft_RejectsADiscountThatNoLongerFitsTheLinesAndLeavesTheDraftAsItWas(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items']);

        $this->accountingDb()->table('sales')->where('sale_id', $draft)->update(['invoice_discount_type' => PERCENT, 'invoice_discount' => '150']);

        $this->assertSame(INVOICE_DISCOUNT_INVALID, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame(InvoiceDiscountProblem::PercentOver100, $this->sales->invoiceDiscountProblem());
        $this->assertSame((string) SUSPENDED, (string) $this->saleRow($draft)->sale_status);
        $this->assertSame(0, $this->countJournals());
    }

    public function testIssueDraft_KeepsTheCustomerOfTheDraftAndThePostedBalanceOfTheCustomerFollowsTheInvoice(): void
    {
        $other = $this->anotherCustomer();
        $cycle = $this->taxedReference();
        $draft = $this->draftInvoice($cycle['items'], $other);

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame((string) $other, (string) $this->saleRow($draft)->customer_id);
        $this->assertSame('816.50', $this->balance((int) $this->position($other)->accountId));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertTies($other);
    }

    public function testIssueDraft_IssuesADraftOfNoCustomerOnceWhenTwoRequestsRunAtTheSameTime(): void
    {
        $cycle = $this->taxedReference();
        $draft = $this->save($cycle['items'], [], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_INVOICE, null, true, null, SUSPENDED);
        $stock = $this->stock($cycle['cream']);

        $outcomes = $this->raceTwoProcesses('issueDraft', [$draft, $this->employeeId], [$draft, $this->employeeId]);

        $this->assertSame([false, true], [min($outcomes), max($outcomes)], 'with no customer there is no lock of the customer: the row of the draft is what stops the second request');
        $this->assertEqualsWithDelta($stock - 4, $this->stock($cycle['cream']), 0.0001);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $draft, 'action' => 'draft_issued'])->countAllResults());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function waysOfPaymentAndKindsOfTreasury(): array
    {
        $sets = [];

        foreach (['cash', 'debit', 'credit', 'check', 'bank_transfer', 'wallet'] as $way) {
            foreach (['cash', 'bank'] as $kind) {
                $sets["{$way} into a {$kind} treasury"] = [$way, $kind];
            }
        }

        return $sets;
    }

    #[DataProvider('waysOfPaymentAndKindsOfTreasury')]
    public function testSaveValue_PostsAPaymentOfAnyWayToTheTreasuryNamedWhateverItsKindAndWritesNoChequeRecord(string $way, string $kind): void
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $treasury   = $treasuries->create($kind === 'bank'
            ? ['name' => 'Independent bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000001']
            : ['name' => 'Independent drawer', 'kind' => 'cash']);
        $saleId     = $this->save([$this->line($this->item('10.00'), 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.' . $way), '100.00') + ['treasury_id' => $treasury]]);
        $payment    = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['source'] === 'sale_payment'))[0];

        $this->assertSame([(int) $treasuries->accountOf($treasury) . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00'], $payment['lines']);
        $this->assertSame($treasury, (int) $this->paymentRow($saleId)->treasury_id);
        $this->assertSame(lang('Sales.' . $way), $this->paymentRow($saleId)->payment_type);
        $this->assertSame(0, $this->countRows('cheques'), 'Only the cheque screens write a cheque');
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
    }

    public function testSaveValue_PostsACheckPaymentThatNamesNoTreasuryToTheAccountRoutedForChecks(): void
    {
        $held = $this->customAccount('12', '1230', 'Checks held');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.check')->update(['account_id' => $held]);

        $saleId = $this->save([$this->line($this->item('10.00'), 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.check'), '100.00')]);

        $this->assertNull($this->paymentRow($saleId)->treasury_id);
        $this->assertSame('100.00', $this->balance($held));
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
        $this->assertSame('0.00', $this->balance($this->accountId('notes_receivable')));
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testUpdate_KeepsAnEarlierPaymentOnItsTreasuryWhenALaterOneNamesAnotherAndTheJournalOfTheEarlierOneStays(): void
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'First drawer', 'kind' => 'cash']);
        $bank       = $treasuries->create(['name' => 'Later bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '5500000002']);
        $saleId     = $this->save([$this->line($this->item('10.00'), 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '60.00') + ['treasury_id' => $drawer]]);
        $first      = $this->paymentRow($saleId);
        $journal    = (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $first->payment_id, 'status' => 'posted'])->get()->getRow()->journal_id;

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'a check later', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.check'), 'payment_amount' => '20.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'treasury_id' => $bank],
        ]]));

        $rows = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->orderBy('payment_id')->get()->getResult();

        $this->assertSame([$drawer, $bank], array_map(static fn (object $payment): int => (int) $payment->treasury_id, $rows));
        $this->assertSame('posted', $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal)->get()->getRow()->status);
        $this->assertSame('60.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('20.0000', $treasuries->position($bank)->balance);
    }

    public function testJournalsOf_LeavesOutThePreviewsOfABatch(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')]);

        $before = $this->journals($saleId);

        $this->previewOfSource('sale', $saleId);

        $paymentId = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;

        $this->previewOfSource('sale_payment', $paymentId);

        $this->assertSame($before, $this->journals($saleId));
        $this->assertNotContains('preview', array_column($this->journals($saleId), 'status'));
    }

    // ---------------------------------------------------------------------------------------
    // A mode and a liability account for each tax (WP25)
    // ---------------------------------------------------------------------------------------

    /**
     * @return list<string> "account:debit:credit" of each line of a journal at four decimals, in order.
     */
    private function exactLines(int $journalId): array
    {
        return array_map(
            static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4),
            $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journalId)->orderBy('line_no', 'ASC')->get()->getResult(),
        );
    }

    public function testSaveValue_PostsALineWithATaxInsideThePriceAndOneAddedToItAsOneInvoiceOfTheNetAndTheWholeTax(): void
    {
        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '13.0435'), $this->taxRow($item, 0, 'Service', '5.0000', 1, '4.3478')]);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(
            [$this->customerAccount() . ':104.3500:0.0000', $this->accountId('sales_revenue') . ':0.0000:86.9587', $this->accountId('tax_output') . ':0.0000:17.3913'],
            $this->exactLines($this->standing($saleId, 'revenue')[0]['id']),
        );
    }

    public function testSaveValue_PostsTheTaxOfATaxWithAnAccountOfItsOwnToThatAccountAndTheRestToTheRoutedOne(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $saleId  = $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '13.0435', $account), $this->taxRow($item, 0, 'Service', '5.0000', 1, '4.3478')]);

        $this->assertSame(
            [$this->customerAccount() . ':104.3500:0.0000', $this->accountId('sales_revenue') . ':0.0000:86.9587', $this->accountId('tax_output') . ':0.0000:4.3478', $account . ':0.0000:13.0435'],
            $this->exactLines($this->standing($saleId, 'revenue')[0]['id']),
        );
        $this->assertSame('-13.04', $this->balance($account));
    }

    public function testSaveValue_PostsTheSameJournalAsBeforeWhenNoTaxHasAnAccountOfItsOwn(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame(
            [$this->customerAccount() . ':816.50:0.00', $this->accountId('sales_revenue') . ':0.00:710.00', $this->accountId('tax_output') . ':0.00:106.50'],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
        $this->assertSame(0, $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->where('liability_account_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testDelete_ReversesTheTaxOfAnAccountOfItsOwnToTheSameAccountAndMirrorsTheInvoice(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $saleId  = $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '13.0435', $account), $this->taxRow($item, 0, 'Service', '5.0000', 1, '4.3478')], [], SALE_TYPE_POS, -1, true, null, COMPLETED, NEW_ENTRY);
        $invoice = $this->journals($saleId)[0]['id'];

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $reversal = (int) $this->accountingDb()->table('accounting_journals')->select('journal_id')->where('reverses_journal_id', $invoice)->get()->getRow()->journal_id;

        $this->assertSame(
            [$this->customerAccount() . ':0.0000:104.3500', $this->accountId('sales_revenue') . ':86.9587:0.0000', $this->accountId('tax_output') . ':4.3478:0.0000', $account . ':13.0435:0.0000'],
            $this->exactLines($reversal),
            'the reversal takes back what the invoice posted, account by account',
        );

        foreach ([$this->customerAccount(), $this->accountId('sales_revenue'), $this->accountId('tax_output'), $account] as $accountId) {
            $this->assertSame('0.00', $this->balance($accountId), (string) $accountId);
        }
    }

    public function testSaveValue_PostsACreditNoteOfAnInvoiceWithTheTaxInsideThePricesAsTheMirrorOfTheInvoice(): void
    {
        $account  = $this->customAccount('215', '215003', 'Tax on services');
        $item     = $this->item('0', false);
        $invoice  = $this->save([$this->line($item, 0, '1', '115')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '15.00', $account)]);
        $returnId = $this->save([$this->line($item, 0, '-1', '115')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '-15.00', $account)], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertSame(
            [$this->accountId('sales_returns') . ':100.0000:0.0000', $account . ':15.0000:0.0000', $this->customerAccount() . ':0.0000:115.0000'],
            $this->exactLines($this->standing($returnId, 'revenue')[0]['id']),
        );
        $this->assertSame('0.00', $this->balance($account));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_PostsARefundInvoiceOfAnInvoiceWithTheTaxInsideThePricesWithTheLinesOfACreditNote(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $invoice = $this->save([$this->line($item, 0, '2', '115')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '30.00', $account)]);
        $rows    = [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '-15.00', $account)];

        $credit = $this->save([$this->line($item, 0, '-1', '115')], $rows, [], SALE_TYPE_RETURN, -1, true, $invoice);
        $refund = $this->save([$this->line($item, 0, '-1', '115')], $rows, [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice);

        $this->assertGreaterThan(0, $refund);
        $this->assertSame($this->standing($credit, 'revenue')[0]['lines'], $this->standing($refund, 'revenue')[0]['lines']);
        $this->assertSame('0.00', $this->balance($account));
    }

    public function testSaveValue_RefusesASaleWhoseTaxAccountCannotTakeEntriesAndLeavesNothingOfIt(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);

        (new AccountService())->deactivate($account);

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 1, '15.00', $account)]));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->accountingDb()->table('sales_items_taxes')->where('liability_account_id', $account)->countAllResults());
    }

    public function testSaveValue_KeepsTheModeAndTheAccountEachTaxRowWasSavedWithWhateverTheTaxListSaysLater(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $taxes   = new TaxDefinitions();
        $id      = $taxes->save(['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED, 'account_id' => $account], null, $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]));

        try {
            $saleId = $this->save([$this->line($item, 0, '1', '115')], [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '15.00', $account)]);
            $before = $this->exactLines($this->standing($saleId, 'revenue')[0]['id']);

            $taxes->save(['tax_mode' => TaxDefinitions::EXCLUDED, 'account_id' => ''], $id, $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]));

            $stored = $this->accountingDb()->table('sales_items_taxes')->where('sale_id', $saleId)->get()->getRow();

            $this->assertSame(['0', (string) $account], [(string) $stored->tax_type, (string) $stored->liability_account_id]);
            $this->assertSame($before, $this->exactLines($this->standing($saleId, 'revenue')[0]['id']));
            $this->assertSame('15.0000', bcadd((string) $stored->item_tax_amount, '0', 4));
        } finally {
            $this->accountingDb()->table('tax_definitions')->truncate();
        }
    }

    public function testSaveValue_PostsTheTaxesTheRegisterWorkedOutFromTheTaxListWithTheirModeAndAccount(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $router  = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
        $taxes   = new TaxDefinitions();
        $itemTax = [['name' => 'VAT', 'percent' => '15'], ['name' => 'Service', 'percent' => '5']];

        $taxes->save(['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED, 'account_id' => $account], null, $router);
        $taxes->save(['tax_name' => 'Service', 'tax_mode' => TaxDefinitions::EXCLUDED], null, $router);
        model(Item_taxes::class)->save_value($itemTax, $item);
        $this->setConfigValue('tax_decimals', '4');

        try {
            $cart    = [0 => ['item_id' => $item, 'line' => 0, 'quantity' => '1', 'price' => '100', 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null]];
            $before  = bcscale();
            bcscale(max(2, totals_decimals() + tax_decimals()));
            $details = (new Tax_lib())->get_taxes($cart, NEW_ENTRY, $this->customerId, 'sale_invoice');
            bcscale($before);

            $saleId = $this->save([$this->line($item, 0, '1', '100')], $details[1]);
        } finally {
            $this->setConfigValue('tax_decimals', '2');
            $this->accountingDb()->table('tax_definitions')->truncate();
        }

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(
            [$this->customerAccount() . ':104.3500:0.0000', $this->accountId('sales_revenue') . ':0.0000:86.9587', $this->accountId('tax_output') . ':0.0000:4.3478', $account . ':0.0000:13.0435'],
            $this->exactLines($this->standing($saleId, 'revenue')[0]['id']),
        );
    }

    public function testSaveValue_PostsManyLinesWithTaxesOfBothModesAsOneBalancedInvoiceWhoseTotalIsTheRoundedSumOfTheLines(): void
    {
        $router = $this->employeeWithGrants([AccountingGuard::ROUTING_MANAGE]);
        $taxes  = new TaxDefinitions();
        $kinds  = [
            $this->item('0', false) => [['name' => 'VAT', 'percent' => '15'], ['name' => 'Service', 'percent' => '5']],
            $this->item('0', false) => [['name' => 'Levy', 'percent' => '10']],
            $this->item('0', false) => [['name' => 'Service', 'percent' => '5']],
        ];

        $taxes->save(['tax_name' => 'VAT', 'tax_mode' => TaxDefinitions::INCLUDED], null, $router);
        $taxes->save(['tax_name' => 'Levy', 'tax_mode' => TaxDefinitions::INCLUDED], null, $router);
        $taxes->save(['tax_name' => 'Service', 'tax_mode' => TaxDefinitions::EXCLUDED], null, $router);

        foreach ($kinds as $itemId => $rows) {
            model(Item_taxes::class)->save_value($rows, $itemId);
        }

        $this->setConfigValue('tax_decimals', '4');

        try {
            $items  = [];
            $cart   = [];
            $prices = [['19.99', '3'], ['33.33', '2'], ['7.77', '7'], ['101.01', '1'], ['0.99', '13'], ['250.50', '2'], ['12.34', '5'], ['0.37', '29']];
            $ids    = array_keys($kinds);

            foreach ($prices as $line => [$price, $quantity]) {
                $itemId       = $ids[$line % 3];
                $items[]      = $this->line($itemId, $line, $quantity, $price);
                $cart[$line]  = ['item_id' => $itemId, 'line' => $line, 'quantity' => $quantity, 'price' => $price, 'discount' => '0', 'discount_type' => PERCENT, 'tax_category_id' => null];
            }

            $before  = bcscale();
            bcscale(max(2, totals_decimals() + tax_decimals()));
            $details = (new Tax_lib())->get_taxes($cart, NEW_ENTRY, $this->customerId, 'sale_invoice');
            bcscale($before);

            $saleId = $this->save($items, $details[1]);
        } finally {
            $this->setConfigValue('tax_decimals', '2');
            $this->accountingDb()->table('tax_definitions')->truncate();
        }

        $this->assertGreaterThan(0, $saleId);

        $gross = '0';
        $added = '0';
        $all   = '0';

        foreach ($prices as [$price, $quantity]) {
            $gross = bcadd($gross, bcmul($price, $quantity, 4), 4);
        }

        foreach ($details[1] as $tax) {
            $stored = bcadd($this->accountingDb()->table('sales_items_taxes')->select('item_tax_amount')->where(['sale_id' => $saleId, 'item_id' => $tax['item_id'], 'line' => $tax['line'], 'name' => $tax['name']])->get()->getRow()->item_tax_amount, '0', 4);
            $all   = bcadd($all, $stored, 4);
            $added = (string) $tax['tax_type'] === '1' ? bcadd($added, $stored, 4) : $added;
        }

        $lines = $this->exactLines($this->standing($saleId, 'revenue')[0]['id']);
        $total = bcadd(bcadd(bcadd($gross, $added, 4), '0.005', 3), '0', 2);

        $this->assertSame($this->customerAccount() . ':' . bcadd($total, '0', 4) . ':0.0000', $lines[0], 'the customer owes the rounded sum of the lines and the tax added to them');
        $this->assertSame($this->accountId('tax_output') . ':0.0000:' . $all, $lines[2], 'the tax is posted as it was worked out, inside the price or not');
        $this->assertSame($this->accountId('sales_revenue') . ':0.0000:' . bcsub($total, $all, 4), $lines[1], 'and the revenue is what is left of the total (E14)');
    }

    // ---------------------------------------------------------------------------------------
    // The adjustment and the shipping on the header of an invoice, a quote or a credit note
    // ---------------------------------------------------------------------------------------

    private function turnOnHeaderCharges(string $shippingTax = '15'): void
    {
        $this->setConfigValue('accounting_sales_adjustment', '1');
        $this->setConfigValue('accounting_sales_shipping', '1');
        $this->setConfigValue('accounting_shipping_tax_percent', $shippingTax);
    }

    private function chargeColumns(int $saleId): array
    {
        $row = $this->saleRow($saleId);

        return [$row->adjustment_label, $row->adjustment_amount, $row->shipping_amount, $row->shipping_tax_option, $row->shipping_tax_amount];
    }

    public function testSaveValue_PostsTheShippingAndTheAdjustmentOfAnInvoiceToTheAccountsOfTheirRoutingRows(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Handling', '-10', '20'));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(
            [
                $this->customerAccount() . ':826.50:0.00',
                $this->accountId('sales_adjustment') . ':10.00:0.00',
                $this->accountId('sales_revenue') . ':0.00:710.00',
                $this->accountId('tax_output') . ':0.00:106.50',
                $this->accountId('sales_shipping') . ':0.00:20.00',
            ],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
        $this->assertSame(['Handling', '-10.0000', '20.0000', null, null], $this->chargeColumns($saleId));
        $this->assertSame('826.50', $this->balance($this->customerAccount()));
        $this->assertSame([$this->accountId('cogs') . ':400.00:0.00', $this->stockAccount() . ':0.00:400.00'], $this->standing($saleId, 'cogs')[0]['lines'], 'the cost of the goods does not depend on the charges');
    }

    public function testSaveValue_SavesAnInvoiceOfFreeGoodsWithShippingAndTiesTheCustomerPosition(): void
    {
        $this->turnOnHeaderCharges();

        $free   = $this->line($this->item('4.00'), 0, '1', '0', '4.00');
        $saleId = $this->save([$free], [], headerCharges: new HeaderCharges(shipping: '20'));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([$this->customerAccount() . ':20.00:0.00', $this->accountId('sales_shipping') . ':0.00:20.00'], $this->standing($saleId, 'revenue')[0]['lines']);
        $this->assertSame('20.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('sales_revenue')));
        $this->assertTies();
    }

    public function testSaveValue_SavesAnInvoiceOfFreeGoodsWithTaxedShippingWhoseRoundingFallsOnTheShipping(): void
    {
        $this->turnOnHeaderCharges('4.5');

        $free   = $this->line($this->item('4.00'), 0, '1', '0', '4.00');
        $saleId = $this->save([$free], [], headerCharges: new HeaderCharges(shipping: '10.01', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '4.5'));

        $this->assertGreaterThan(0, $saleId);
        $shipping = $this->accountingDb()->table('accounting_journal_lines')->where('account_id', $this->accountId('sales_shipping'))->get()->getRow();

        $this->assertSame('10.46', $this->balance($this->customerAccount()));
        $this->assertSame('10.0095', bcadd($shipping->credit, '0', 4), 'the shipping carries the rounding of the cent because there are no goods');
        $this->assertSame('0.4505', bcadd($this->accountingDb()->table('accounting_journal_lines')->where('account_id', $this->accountId('tax_output'))->get()->getRow()->credit, '0', 4));
        $this->assertTies();
    }

    public function testSaveValue_SavesACreditNoteOfFreeGoodsWithShippingAndTiesTheCustomerPosition(): void
    {
        $this->turnOnHeaderCharges();

        $cycle    = $this->referenceInvoice();
        $original = $this->save($cycle['items'], $cycle['taxes']);
        $return   = $this->save([$this->line($cycle['cream'], 0, '-1', '0')], [], [], SALE_TYPE_RETURN, origin: $original, headerCharges: new HeaderCharges(shipping: '20'));

        $this->assertGreaterThan(0, $return);
        $this->assertSame([$this->accountId('sales_shipping') . ':20.00:0.00', $this->customerAccount() . ':0.00:20.00'], $this->standing($return, 'revenue')[0]['lines']);
        $this->assertTies();
    }

    public function testSaveValue_PostsTheTaxOnShippingWithTheOutputTaxWhenItIsOnTopOfTheAmount(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '100', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'));

        $this->assertSame(
            [
                $this->customerAccount() . ':931.50:0.00',
                $this->accountId('sales_revenue') . ':0.00:710.00',
                $this->accountId('tax_output') . ':0.00:121.50',
                $this->accountId('sales_shipping') . ':0.00:100.00',
            ],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
        $this->assertSame([null, null, '100.0000', 'added', '15.0000'], $this->chargeColumns($saleId));
        $this->assertSame('15.0000', $this->saleRow($saleId)->shipping_tax_percent === null ? '' : bcadd($this->saleRow($saleId)->shipping_tax_percent, '0', 4));
    }

    public function testSaveValue_PostsTheTaxOnShippingInsideTheAmountWhenThePricesIncludeTax(): void
    {
        $this->turnOnHeaderCharges();

        $settings = config(OSPOS::class)->settings;
        config(OSPOS::class)->settings['tax_included'] = '1';

        try {
            $cycle  = $this->referenceInvoice();
            $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '100', shippingTaxOption: ShippingTaxOption::Included, shippingTaxPercent: '15'));
        } finally {
            config(OSPOS::class)->settings = $settings;
        }

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([null, null, '100.0000', 'included', '13.0435'], $this->chargeColumns($saleId));

        $lines = $this->accountingDb()->table('accounting_journal_lines l')->select('l.account_id, l.debit, l.credit')->join('accounting_journals j', 'j.journal_id = l.journal_id')->where(['j.source_type' => 'sale', 'j.source_id' => $saleId, 'j.entry_type' => 'revenue'])->get()->getResult();
        $shape = array_map(static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $lines);

        $this->assertContains($this->accountId('sales_shipping') . ':0.0000:86.9565', $shape);
        $this->assertContains($this->accountId('tax_output') . ':0.0000:119.5435', $shape);
        $this->assertContains($this->customerAccount() . ':916.5000:0.0000', $shape);
    }

    public function testSaveValue_RoundsTheCustomerTotalAndLeavesTheShippingAndItsTaxToTheFourthDecimal(): void
    {
        $this->turnOnHeaderCharges();

        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '0.70')], [$this->tax($item, 0, '0.105')], headerCharges: new HeaderCharges(shipping: '0.07', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'));

        $lines = $this->accountingDb()->table('accounting_journal_lines l')->select('l.account_id, l.debit, l.credit')->join('accounting_journals j', 'j.journal_id = l.journal_id')->where(['j.source_type' => 'sale', 'j.source_id' => $saleId, 'j.entry_type' => 'revenue'])->orderBy('l.line_no')->get()->getResult();

        $this->assertSame(
            [
                $this->customerAccount() . ':0.8900:0.0000',
                $this->accountId('sales_revenue') . ':0.0000:0.7045',
                $this->accountId('tax_output') . ':0.0000:0.1155',
                $this->accountId('sales_shipping') . ':0.0000:0.0700',
            ],
            array_map(static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $lines),
        );
    }

    public function testSaveValue_PostsTheChargesBesideTheDiscountOnTheWholeInvoice(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount'], headerCharges: new HeaderCharges('Fee', '5', '20'));

        $this->assertSame(
            [
                $this->customerAccount() . ':759.85:0.00',
                $this->accountId('sales_revenue') . ':0.00:639.00',
                $this->accountId('tax_output') . ':0.00:95.85',
                $this->accountId('sales_shipping') . ':0.00:20.00',
                $this->accountId('sales_adjustment') . ':0.00:5.00',
            ],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
    }

    public function testSaveValue_PostsACreditNoteWithTheChargesOnTheOppositeSides(): void
    {
        $this->turnOnHeaderCharges();

        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $service = $this->item('0', false);
        $note    = $this->save([$this->line($service, 0, '-1', '50')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, headerCharges: new HeaderCharges('Goodwill', '-5', '20'));

        $this->assertGreaterThan(0, $note);
        $this->assertSame(
            [
                $this->accountId('sales_returns') . ':50.00:0.00',
                $this->accountId('sales_shipping') . ':20.00:0.00',
                $this->accountId('sales_adjustment') . ':0.00:5.00',
                $this->customerAccount() . ':0.00:65.00',
            ],
            $this->standing($note, 'revenue')[0]['lines'],
        );
        $this->assertSame('751.50', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testSaveValue_PostsARefundInvoiceWithTheChargesAsACreditNoteDoes(): void
    {
        $this->turnOnHeaderCharges();

        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $service = $this->item('0', false);
        $refund  = $this->save([$this->line($service, 0, '-1', '50')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice, headerCharges: new HeaderCharges('', '0', '20'));

        $this->assertSame(
            [$this->accountId('sales_returns') . ':50.00:0.00', $this->accountId('sales_shipping') . ':20.00:0.00', $this->customerAccount() . ':0.00:70.00'],
            $this->standing($refund, 'revenue')[0]['lines'],
        );
    }

    public function testDelete_RefusesToCancelAnInvoiceWithChargesAsEveryIssuedInvoiceIsLocked(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Handling', '-10', '20'));
        $before = $this->journals($saleId);

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(AccountingError::IssuedDocumentLocked, $this->failure()->error);
        $this->assertSame($before, $this->journals($saleId));
        $this->assertSame(['Handling', '-10.0000', '20.0000', null, null], $this->chargeColumns($saleId));
    }

    public function testReverseAutomatic_MirrorsTheStoredLinesOfAnInvoiceWithChargesEvenAfterTheSettingsWereTurnedOff(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Handling', '-10', '20'));

        $this->setConfigValue('accounting_sales_adjustment', '0');
        $this->setConfigValue('accounting_sales_shipping', '0');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.shipping')->update(['routing_type' => 'cancelled', 'account_id' => null]);

        (new JournalService())->reverseAutomatic($this->standing($saleId, 'revenue')[0]['id'], date('Y-m-d'), 'correction', $this->employeeId);

        foreach (['customer' => $this->customerAccount(), 'adjustment' => $this->accountId('sales_adjustment'), 'shipping' => $this->accountId('sales_shipping'), 'tax' => $this->accountId('tax_output'), 'revenue' => $this->accountId('sales_revenue')] as $name => $accountId) {
            $this->assertSame('0.00', $this->balance($accountId), $name);
        }
    }

    public function testSaveValue_KeepsTheTotalOfAnInvoiceWhoseChargesWereSavedWhenTheCustomerPaysPartOfIt(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], headerCharges: new HeaderCharges('Handling', '-10', '20'));

        $position = $this->position();

        $this->assertSame('526.50', bcadd($position->open(), '0', 2));
        $this->assertSame('826.50', bcadd($position->invoices[0]->total, '0', 2));
        $this->assertTies();
        $this->assertSame([], array_filter($position->aging(date('Y-m-d')), static fn (string $amount, string $bucket): bool => $bucket !== 'today' && bccomp($amount, '0', 4) !== 0, ARRAY_FILTER_USE_BOTH));
        $this->assertGreaterThan(0, $saleId);
    }

    public function testSaveValue_RefusesChargesWhileTheirSettingIsOffAndLeavesNothingOfTheSale(): void
    {
        $cycle = $this->referenceInvoice();
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Fee', '5')));
        $this->assertSame(AccountingError::HeaderChargesOff, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());

        $this->setConfigValue('accounting_sales_adjustment', '1');

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('', '0', '5')));
        $this->assertSame(AccountingError::HeaderChargesOff, $this->failure()->error, 'the shipping has its own setting');

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Fee', '5')));
    }

    public function testSaveValue_RefusesChargesOnASaleThatIsNeitherAnInvoiceNorAQuoteNorACreditNote(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '900.00')], SALE_TYPE_POS, headerCharges: new HeaderCharges('', '0', '20')));
        $this->assertSame(AccountingError::HeaderChargesNotAllowed, $this->failure()->error);
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesAnAdjustmentThatTakesTheTotalBelowZero(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Fee', '-816.51')));
        $this->assertSame(AccountingError::AdjustmentExceedsTotal, $this->failure()->error);
        $this->assertSame(0, $this->countJournals());
        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Fee', '-816.50')) ?: 0, 'an adjustment that brings the total to zero is allowed');
    }

    public function testSaveValue_RefusesAnAdjustmentUnderTheSaudiRules(): void
    {
        $this->turnOnHeaderCharges();

        $itemId          = $this->item('0', false);
        [$taxes, $total] = $this->saudiTaxes($itemId, '100.00');
        $items           = [$this->line($itemId, 0, '1', '100.00')];
        $cycle           = $this->referenceInvoice();

        $this->underTheSaudiRules();

        try {
            $this->assertSame(ACCOUNTING_FAILED, $this->save($items, $taxes, headerCharges: new HeaderCharges('Fee', '-10'), categories: $total));
            $this->assertSame(AccountingError::AdjustmentInSaudiMode, $this->failure()->error);
            $this->assertSame(0, $this->countJournals());
            $this->assertGreaterThan(0, $this->save($items, $taxes, status: SUSPENDED, headerCharges: new HeaderCharges('Fee', '-10'), categories: $total) ?: 0, 'a sale set aside is refused only when it is completed');
        } finally {
            $this->leaveTheSaudiRules();
        }

        $this->assertGreaterThan(0, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Fee', '-10')) ?: 0, 'outside the Saudi rules the adjustment is taken as before');
    }

    public function testSaveValue_PutsTheShippingOfASaudiSaleInTheCategoryOfItsRate(): void
    {
        $this->turnOnHeaderCharges();

        $db     = $this->accountingDb();
        $itemId = $this->item('0', false);
        $taxes  = [[], [[...$this->taxRow($itemId, 0, 'VAT', '15.0000', 1, '0.015000'), 'vat' => new LineVat(null, 'S', '15.000', null, null, 'VAT'), 'vat_amounts' => new VatLineResult('0.10', '0.02', '0.12', '0.015000', 'S|15.000|')]], ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '0.10', '0.02', false)]];
        $status = COMPLETED;

        $this->underTheSaudiRules();

        try {
            $items           = [$this->line($itemId, 0, '1', '0.10')];
            $saleId          = $this->sales->save_value(NEW_ENTRY, $status, $items, $this->customerId, $this->employeeId, 'shipped', null, null, null, SALE_TYPE_INVOICE, [], null, $taxes, true, headerCharges: new HeaderCharges(shipping: '0.10', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'));
            $this->saleIds[] = $saleId;
            $total           = $db->table('sales_vat_totals')->where('sale_id', $saleId)->get()->getRow();

            $this->assertSame(['0.20', '0.03', '0.10'], [$total->taxable, $total->vat, $total->charges], 'the tax of the category is rounded once on the goods and the shipping');
            $this->assertEqualsCanonicalizing(
                [$this->customerAccount() . ':0.24:0.00', $this->accountId('sales_revenue') . ':0.00:0.10', $this->accountId('sales_shipping') . ':0.00:0.10', $this->accountId('tax_output') . ':0.00:0.03', $this->accountId('tax_rounding') . ':0.00:0.01'],
                $this->standing($saleId, 'revenue')[0]['lines'],
                'the register charged 0.10 + 0.02 + 0.10 + 0.015, which comes to 0.24',
            );
            $this->assertStringContainsString('<cbc:ChargeTotalAmount currencyID="SAR">0.10</cbc:ChargeTotalAmount>', (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $saleId)->xml, 'the document issued with the sale carries the shipping as a charge');
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testSaveValue_RefusesShippingWithoutTaxUnderTheSaudiRules(): void
    {
        $this->turnOnHeaderCharges();

        $itemId          = $this->item('0', false);
        [$taxes, $total] = $this->saudiTaxes($itemId, '100.00');

        $this->underTheSaudiRules();

        try {
            $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($itemId, 0, '1', '100.00')], $taxes, headerCharges: new HeaderCharges(shipping: '20'), categories: $total));
            $this->assertSame(AccountingError::ShippingUntaxedInSaudiMode, $this->failure()->error);
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testSaveValue_IssuesTheDocumentOfASaleCompletedUnderTheSaudiRules(): void
    {
        $this->underTheSaudiRules();

        try {
            $saleId   = $this->saudiSaleOf('100.00', null, [$this->payment('Cash', '115.00')], SALE_TYPE_POS);
            $document = (new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $saleId);

            $this->assertGreaterThan(0, $saleId);
            $this->assertNotNull($document, 'no sale is completed under the rules without its document');
            $this->assertSame(['115.00', '15.00'], [$document->taxInclusive, $document->taxAmount]);
            $this->assertSame($document->qr, $this->sales->documentQrOf($saleId), 'the code printed is the one issued');

            $itemId          = $this->item('0', false);
            [$taxes, $total] = $this->saudiTaxes($itemId, '10.00');
            $setAside        = $this->save([$this->line($itemId, 0, '1', '10.00')], $taxes, type: SALE_TYPE_POS, customerId: null, status: SUSPENDED, categories: $total);

            $this->assertNull((new EInvoiceService())->documentOf(EInvoiceService::SOURCE_SALE, $setAside), 'a sale set aside has no document');
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testSaveValue_UndoesTheSaleWhenItsDocumentCannotBeIssued(): void
    {
        $this->underTheSaudiRules();
        $this->configureEInvoicing(['einvoice_key_path' => '/nonexistent/expro-test.key', 'einvoice_certificate_path' => '/nonexistent/expro-test.pem']);

        $db       = $this->accountingDb();
        $sales    = $db->table('sales')->countAllResults();
        $journals = $this->countJournals();

        try {
            $this->assertSame(EINVOICE_FAILED, $this->saudiSaleOf('100.00', null, [$this->payment('Cash', '115.00')], SALE_TYPE_POS));
            $this->assertInstanceOf(EInvoiceException::class, $this->sales->accountingFailure());
            $this->assertSame($sales, $db->table('sales')->countAllResults(), 'no sale without its document');
            $this->assertSame($journals, $this->countJournals());
            $this->assertSame(0, (int) $db->table('einvoice_counters')->get()->getRow()->last_icv, 'no counter is spent');

            $failure = $db->table('einvoice_failures')->orderBy('failure_id', 'DESC')->get()->getRow();

            $this->assertSame([EInvoiceService::SOURCE_SALE, (string) SALE_TYPE_POS, (string) $this->employeeId], [$failure->source, (string) $failure->sale_type, (string) $failure->employee_id]);
            $this->assertSame(64, strlen((string) $failure->fingerprint));
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testSaveValue_RefusesASaleWhoseLinesAreNotClassifiedUnderTheSaudiRules(): void
    {
        $cycle = $this->referenceInvoice();

        $this->underTheSaudiRules();

        try {
            $this->assertSame(EINVOICE_FAILED, $this->save($cycle['items'], $cycle['taxes']));
            $this->assertSame(EInvoiceError::LinesNotClassified, $this->sales->accountingFailure()->error);
            $this->assertSame(0, $this->countJournals());
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testSaveValue_ReturnsTheSaleOfARepeatedCompletionToken(): void
    {
        $itemId = $this->item('0', false);
        $items  = [$this->line($itemId, 0, '1', '50.00')];

        $this->sales->useCompletionToken('token-1');

        $first  = $this->save($items);
        $before = $this->accountingDb()->table('sales')->countAllResults();

        $this->sales->useCompletionToken('token-1');

        $this->assertSame($first, $this->save($items), 'the same token gives back the sale it completed');
        $this->assertSame($before, $this->accountingDb()->table('sales')->countAllResults());

        $this->sales->useCompletionToken('token-2');

        $this->assertNotSame($first, $this->save($items), 'another token completes another sale');
        $this->assertNotSame($first, $this->save($items), 'a sale saved without a token is a sale of its own');
    }

    public function testCancel_RefusesAPosSaleThatHasADocument(): void
    {
        $this->underTheSaudiRules();

        try {
            $saleId = $this->saudiSaleOf('100.00', null, [$this->payment('Cash', '115.00')], SALE_TYPE_POS);

            $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));
            $this->assertSame(AccountingError::ElectronicDocumentLocked, $this->failure()->error);
            $this->assertSame(COMPLETED, (int) $this->saleRow($saleId)->sale_status);

            try {
                (new SalePosting())->cancel($saleId, $this->employeeId);
                $this->fail('The sale was cancelled.');
            } catch (AccountingException $refusal) {
                $this->assertSame(AccountingError::ElectronicDocumentLocked, $refusal->error);
            }
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testRestoreList_RefusesASaleThatHasADocument(): void
    {
        $this->underTheSaudiRules();

        try {
            $saleId = $this->saudiSaleOf('100.00', null, [$this->payment('Cash', '115.00')], SALE_TYPE_POS);

            $this->assertFalse($this->sales->restore_list([$saleId], $this->employeeId));
            $this->assertSame(AccountingError::ElectronicDocumentLocked, $this->failure()->error);
            $this->assertSame(COMPLETED, (int) $this->saleRow($saleId)->sale_status);
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testUpdate_RefusesAChangeToTheCustomerOfASaleThatHasADocument(): void
    {
        $this->underTheSaudiRules();

        try {
            $saleId = $this->saudiSaleOf('100.00', null, [$this->payment('Cash', '115.00')], SALE_TYPE_POS);

            $this->assertFalse($this->sales->update($saleId, ['customer_id' => $this->customerId]));
            $this->assertSame(AccountingError::ElectronicDocumentLocked, $this->failure()->error);
            $this->assertTrue($this->sales->update($saleId, ['customer_id' => null, 'comment' => 'Wrapped as a gift']), 'a field sent as it is stored is no change');
            $this->assertSame('Wrapped as a gift', $this->saleRow($saleId)->comment);
        } finally {
            $this->leaveTheSaudiRules();
        }
    }

    public function testSaveValue_RefusesAShippingTaxThatIsNotTheRateAndTheModeTheCompanyWorksIn(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('', '0', '100', ShippingTaxOption::Added, '5')));
        $this->assertSame(AccountingError::HeaderChargesInvalid, $this->failure()->error, 'another rate');

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('', '0', '100', ShippingTaxOption::Included, '15')));
        $this->assertSame(AccountingError::HeaderChargesInvalid, $this->failure()->error, 'the prices do not include tax');

        $this->setConfigValue('accounting_shipping_tax_percent', '0');

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('', '0', '100', ShippingTaxOption::Added, '15')));
        $this->assertSame(AccountingError::HeaderChargesInvalid, $this->failure()->error, 'the company charges no tax on shipping');
    }

    public function testSaveValue_RefusesChargesThatAreNotNumbersOrShippingBelowZero(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();

        foreach ([new HeaderCharges('', 'x'), new HeaderCharges('', '0', '-1'), new HeaderCharges(str_repeat('a', 101), '5'), new HeaderCharges('', '0', '5', ShippingTaxOption::None, '0', 9)] as $charges) {
            $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: $charges));
            $this->assertSame(AccountingError::HeaderChargesInvalid, $this->failure()->error);
        }

        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesAnInvoiceWithChargesInAClosedPeriodWithoutLeavingAnyOfIt(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();
        $this->closeThrough(date('Y-m-d'));
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Fee', '5', '20')));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesChargesWhoseRoutingRowIsCancelledAndLeavesNoSale(): void
    {
        $this->turnOnHeaderCharges();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.shipping')->update(['routing_type' => 'cancelled']);

        $cycle = $this->referenceInvoice();
        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('', '0', '20')));
        $this->assertSame(PostingError::RoutingRowCancelled, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
    }

    public function testSaveValue_StoresTheChargesOfASuspendedInvoiceAndPostsThemWhenTheDraftIsIssued(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $draft  = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, headerCharges: new HeaderCharges('Handling', '-10', '20'));

        $this->assertGreaterThan(0, $draft);
        $this->assertSame([], $this->standing($draft));
        $this->assertSame(['Handling', '-10.0000', '20.0000', null, null], $this->chargeColumns($draft));

        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));
        $this->assertSame('720.00', $this->balance($this->customerAccount()), 'the goods of the draft carry no tax of their own here: 710 and 20 of shipping less 10');
        $this->assertSame(['Handling', '-10.0000', '20.0000', null, null], $this->chargeColumns($draft));
    }

    public function testSaveValue_ChangesTheFingerprintOfADraftWhoseChargesChanged(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();
        $draft = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, headerCharges: new HeaderCharges('Handling', '-10', '20'));
        $print = (new DraftInvoice())->fingerprint($draft);

        $this->accountingDb()->table('sales')->where('sale_id', $draft)->update(['shipping_amount' => '25.0000']);

        $this->assertNotSame($print, (new DraftInvoice())->fingerprint($draft));
    }

    public function testSaveValue_KeepsTheChargesOfAQuoteAndPostsNothing(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();
        $quote = $this->save($cycle['items'], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, headerCharges: new HeaderCharges('Fee', '5', '20'));

        $this->assertGreaterThan(0, $quote);
        $this->assertSame(['Fee', '5.0000', '20.0000', null, null], $this->chargeColumns($quote));
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_SavesAnInvoiceWithChargesWithoutJournalsWhileAccountingIsOff(): void
    {
        $this->turnOnHeaderCharges();
        $this->setConfigValue('accounting_enabled', '0');

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Handling', '-10', '20'));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(['Handling', '-10.0000', '20.0000', null, null], $this->chargeColumns($saleId));
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'header_charges_given')->countAllResults());
    }

    public function testSaveValue_WritesWhoGaveTheChargesToTheAuditLogWhileAccountingIsOn(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Handling', '-10', '20'));

        $entry = $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'header_charges_given', 'entity_id' => $saleId])->get()->getRow();

        $this->assertNotNull($entry);
        $this->assertSame($this->employeeId, (int) $entry->employee_id);
        $this->assertSame(['adjustment' => '-10.0000', 'shipping' => '20.0000', 'shipping_tax' => '0.0000'], json_decode((string) $entry->details, true));
    }

    public function testSaveValue_LeavesTheColumnsOfTheChargesEmptyOnASaleWithoutAnyAndJournalsItAsBefore(): void
    {
        $this->turnOnHeaderCharges();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: HeaderCharges::none());

        $this->assertSame([null, null, null, null, null], $this->chargeColumns($saleId));
        $this->assertSame(
            [$this->customerAccount() . ':816.50:0.00', $this->accountId('sales_revenue') . ':0.00:710.00', $this->accountId('tax_output') . ':0.00:106.50'],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
    }

    public function testSaveValue_ForgetsTheChargesOfASuspendedInvoiceThatIsSavedAgainWithout(): void
    {
        $this->turnOnHeaderCharges();

        $cycle = $this->referenceInvoice();
        $draft = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, headerCharges: new HeaderCharges('Fee', '5', '20'));

        $this->assertSame($draft, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, $draft));
        $this->assertSame([null, null, null, null, null], $this->chargeColumns($draft));
    }

    public function testSaveValue_IgnoresTheAdjustmentOfAnInvoiceWhoseLinesCameToNothing(): void
    {
        $this->turnOnHeaderCharges();

        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '0')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, headerCharges: new HeaderCharges('Fee', '50'));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([null, null, null, null, null], $this->chargeColumns($saleId));
        $this->assertSame(0, $this->countJournals() - count($this->accountingDb()->table('accounting_journals')->where('source_type', 'sale_payment')->get()->getResult()), 'nothing to post for an invoice that comes to nothing');
    }

    // ---------------------------------------------------------------------------------------
    // The description template of the journals of sales
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, string> The description of each journal of a sale, by "source/entry".
     */
    private function descriptionsOf(int $saleId): array
    {
        $rows = $this->accountingDb()->table('accounting_journals')->select('source_type, entry_type, description')->groupStart()->where(['source_type' => 'sale', 'source_id' => $saleId])->groupEnd()->orderBy('journal_id')->get()->getResult();
        $out  = [];

        foreach ($rows as $row) {
            $out[$row->source_type . '/' . $row->entry_type] = (string) $row->description;
        }

        return $out;
    }

    public function testSaveValue_DescribesTheInvoiceJournalWithTheTemplateOfSales(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', '{type} {number} - {party} - {date}');

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame(lang('Accounting.template_type_invoice') . ' ' . $saleId . ' - Omar Khaled - ' . date('Y-m-d'), $this->descriptionsOf($saleId)['sale/revenue']);
    }

    public function testSaveValue_TakesTheNumberOfTheInvoiceSequenceIntoTheTemplate(): void
    {
        $manager = $this->employeeWithGrants(['config']);

        (new DocumentNumbering())->configure(DocumentType::SalesInvoice, ['enabled' => true, 'prefix' => 'INV-', 'padding' => 4], $manager);
        $this->setConfigValue('accounting_journal_description_sales', 'Invoice {number}');

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame('Invoice INV-0001', $this->descriptionsOf($saleId)['sale/revenue']);
    }

    public function testSaveValue_LeavesTheDescriptionsTheApplicationWritesWhenThereIsNoTemplate(): void
    {
        $cycle     = $this->referenceInvoice();
        $saleId    = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '100.00')]);
        $paymentId = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
        $payment   = (string) $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $paymentId])->get()->getRow()->description;

        $this->assertSame(['sale/revenue' => 'Sale #' . $saleId, 'sale/cogs' => 'Cost of sale #' . $saleId], $this->descriptionsOf($saleId));
        $this->assertSame('Payment #' . $paymentId . ' of sale #' . $saleId, $payment);
    }

    public function testSaveValue_AppliesTheTemplateToTheMainJournalOnlyAndNotToTheCostOrThePayments(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', 'Doc {number}');

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '100.00')]);

        $descriptions = $this->descriptionsOf($saleId);

        $this->assertSame('Doc ' . $saleId, $descriptions['sale/revenue']);
        $this->assertSame('Cost of sale #' . $saleId, $descriptions['sale/cogs']);
        $this->assertStringStartsWith('Payment #', (string) $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale_payment')->get()->getRow()->description);
    }

    public function testSaveValue_KeepsTheDescriptionOfAJournalThatStandsWhenTheTemplateIsSetLater(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->setConfigValue('accounting_journal_description_sales', 'Changed {number}');
        $this->addPayment($saleId, '100.00');

        $this->assertSame('Sale #' . $saleId, $this->descriptionsOf($saleId)['sale/revenue']);
    }

    public function testSaveValue_NamesACreditNoteAndARefundInvoiceInTheTemplate(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', '{type} {number}');

        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $note    = $this->creditNoteOf($invoice, '30');
        $refund  = $this->refundInvoiceOf($invoice, '20');

        $this->assertSame(lang('Accounting.template_type_credit_note') . ' POS ' . $note, $this->descriptionsOf($note)['sale/revenue']);
        $this->assertSame(lang('Accounting.template_type_refund_invoice') . ' POS ' . $refund, $this->descriptionsOf($refund)['sale/revenue']);
    }

    public function testSaveValue_KeepsTheMarkOfAReturnWithoutASaleWhenTheTemplateDescribesIt(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', '{type} {number}');
        $this->grantReturnOverride();

        $item     = $this->item('66.00');
        $returnId = $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'lost the receipt');

        $this->assertSame(lang('Accounting.template_type_credit_note') . ' POS ' . $returnId . ' ' . ReturnOverride::MARKER, $this->descriptionsOf($returnId)['sale/revenue']);
    }

    public function testSaveValue_WritesNothingOfAPartyForASaleWithoutACustomer(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', 'Sale for [{party}]');

        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS, null);

        $this->assertSame('Sale for []', $this->descriptionsOf($saleId)['sale/revenue']);
    }

    public function testDelete_LeavesTheDescriptionOfTheOriginalJournalAndGivesTheReversalTheReasonAndNotTheTemplate(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', 'Doc {number}');

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')], SALE_TYPE_POS);

        $this->sales->delete($saleId, false, true, $this->employeeId);

        $db       = $this->accountingDb();
        $original = $db->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $saleId, 'entry_type' => 'revenue'])->get()->getRow();
        $reversal = $db->table('accounting_journals')->where('reverses_journal_id', $original->journal_id)->get()->getRow();

        $this->assertSame('reversed', $original->status);
        $this->assertSame('Doc ' . $saleId, (string) $original->description, 'the journal that stands keeps the text it was posted with');
        $this->assertNotNull($reversal);
        $this->assertNotSame('', trim((string) $reversal->description), 'a reversal says why it was made');
        $this->assertStringNotContainsString('Doc ' . $saleId, (string) $reversal->description, 'the template describes the journal of a new document and not its reversal');
    }

    public function testSaveValue_IgnoresATemplateStoredByHandThatIsNotValid(): void
    {
        $this->setConfigValue('accounting_journal_description_sales', '<b>{number}</b>');

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame('Sale #' . $saleId, $this->descriptionsOf($saleId)['sale/revenue']);
    }

    // ---------------------------------------------------------------------------------------
    // The routing types per item and main account per entity (WP24)
    // ---------------------------------------------------------------------------------------

    private function routeRow(RoutingKey $key, RoutingType $type): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', $key->value)->update(['routing_type' => $type->value]);
    }

    private function nameItemAccount(int $itemId, RoutingKey $key, int $accountId): void
    {
        $this->accountingDb()->table('item_routing_accounts')->replace(['item_id' => $itemId, 'routing_key' => $key->value, 'account_id' => $accountId]);
    }

    /**
     * The reference invoice with the creams under an account of their own and the row set to per item.
     *
     * @return array{items: list<array<string, mixed>>, taxes: list<array<string, mixed>>, cream: int, perfume: int, creamAccount: int}
     */
    private function perItemCycle(): array
    {
        $cycle                 = $this->referenceInvoice();
        $cycle['creamAccount'] = $this->customAccount('41', '4101', 'Cream revenue');

        $this->nameItemAccount($cycle['cream'], RoutingKey::SalesRevenue, $cycle['creamAccount']);
        $this->routeRow(RoutingKey::SalesRevenue, RoutingType::PerItem);

        return $cycle;
    }

    /**
     * @return list<string> The lines of the reversal of a journal, as "account:debit:credit".
     */
    private function reversalLinesOf(int $journalId): array
    {
        $db       = $this->accountingDb();
        $reversal = $db->table('accounting_journals')->select('journal_id')->where('reverses_journal_id', $journalId)->get()->getRow();

        $this->assertNotNull($reversal, 'The journal was not reversed.');

        return array_map(
            static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2),
            $db->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $reversal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
        );
    }

    public function testSaveValue_PostsTheRevenueOfEachItemToTheAccountItNamesWhenTheRowIsPerItem(): void
    {
        $cycle  = $this->perItemCycle();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame(
            [
                $this->customerAccount() . ':816.50:0.00',
                $cycle['creamAccount'] . ':0.00:270.00',
                $this->accountId('sales_revenue') . ':0.00:440.00',
                $this->accountId('tax_output') . ':0.00:106.50',
            ],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
        $this->assertSame('270.00', bcmul('-1', $this->balance($cycle['creamAccount']), 2));
        $this->assertSame('440.00', bcmul('-1', $this->balance($this->accountId('sales_revenue')), 2));
    }

    public function testSaveValue_KeepsTheCostJournalAndTheStockTiesWhenTheRevenueRowIsPerItem(): void
    {
        $cycle  = $this->perItemCycle();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);
        $stock  = new StockLedger();

        $this->assertSame([$this->accountId('cogs') . ':400.00:0.00', $this->stockAccount() . ':0.00:400.00'], $this->standing($saleId, 'cogs')[0]['lines']);
        $this->assertSame([], $stock->mismatchedJournals());

        foreach ($stock->reconcile() as $row) {
            $this->assertSame('0.0000', $row['unattributed'], 'location ' . $row['location_id']);
        }

        $this->assertTies();
    }

    public function testSaveValue_PostsAPerItemSaleInTheBranchOfTheSale(): void
    {
        $cycle  = $this->perItemCycle();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $branches = array_unique(array_column($this->accountingDb()->table('accounting_journals')->select('branch_id')->whereIn('journal_id', array_column($this->journals($saleId), 'id'))->get()->getResultArray(), 'branch_id'));

        $this->assertSame([(string) $this->saleRow($saleId)->branch_id], array_values($branches));
    }

    public function testSaveValue_RejectsAPerItemSaleWhoseItemNamesAnAccountThatWasDeactivatedAndLeavesNoSale(): void
    {
        $cycle = $this->perItemCycle();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $cycle['creamAccount'])->update(['active' => 0]);

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes']));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::ItemAccountUnusable, $this->failure()->error);
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_PostsNothingForAPerItemSaleWhileAccountingIsOff(): void
    {
        $cycle = $this->perItemCycle();
        $this->accountingDb()->table('accounting_accounts')->where('account_id', $cycle['creamAccount'])->update(['active' => 0]);
        $this->setConfigValue('accounting_enabled', '0');

        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([], $this->journals($saleId));
    }

    public function testSaveValue_PostsAReturnToTheReturnsAccountOfTheItemWhenThatRowIsPerItem(): void
    {
        $cycle         = $this->referenceInvoice();
        $creamReturns = $this->customAccount('41', '4101', 'Cream returns');
        $this->nameItemAccount($cycle['cream'], RoutingKey::SalesReturns, $creamReturns);
        $this->routeRow(RoutingKey::SalesReturns, RoutingType::PerItem);
        $invoice = $this->save($cycle['items'], $cycle['taxes']);

        $return = $this->save(
            [$this->line($cycle['cream'], 0, '-1', '75', '40.00', '10'), $this->line($cycle['perfume'], 1, '-1', '220', '120.00')],
            [$this->tax($cycle['cream'], 0, '-10.125'), $this->tax($cycle['perfume'], 1, '-33.00')],
            [],
            SALE_TYPE_RETURN,
            -1,
            true,
            $invoice,
        );

        $revenue = $this->standing($return, 'revenue')[0]['lines'];

        $this->assertContains($creamReturns . ':67.50:0.00', $revenue);
        $this->assertContains($this->accountId('sales_returns') . ':220.00:0.00', $revenue);
        $this->assertContains($this->accountId('sales_revenue') . ':0.00:710.00', $this->standing($invoice, 'revenue')[0]['lines']);
    }

    public function testSaveValue_PostsAMainPerEntitySaleWithTheLinesTheAutomaticRowGives(): void
    {
        $cycle     = $this->referenceInvoice();
        $automatic = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->routeRow(RoutingKey::Customers, RoutingType::MainPerEntity);
        $perEntity = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '100.00')]);

        $shape = static fn (array $journals): array => array_map(static fn (array $journal): array => [$journal['entry'], $journal['lines']], $journals);

        $this->assertSame($shape($this->journals($automatic)), $shape($this->journals($perEntity)));
        $this->assertSame(['revenue', 'general', 'cogs'], array_column($this->journals($perEntity), 'entry'));
    }

    public function testSaveValue_RejectsAMainPerEntitySaleOfACustomerWithoutAnAccountAndLeavesNoSale(): void
    {
        $cycle = $this->referenceInvoice();
        $this->routeRow(RoutingKey::Customers, RoutingType::MainPerEntity);
        $other = $this->insertCustomer('No', 'Account');

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $other));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::EntityAccountMissing, $this->failure()->error);
    }

    public function testDelete_ReversalMirrorsTheOriginalJournalWhateverTheItemAccountAndTheRoutingBecameSince(): void
    {
        $cycle    = $this->perItemCycle();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS);
        $original = $this->standing($saleId, 'revenue')[0];
        $cost     = $this->standing($saleId, 'cogs')[0];

        $this->nameItemAccount($cycle['cream'], RoutingKey::SalesRevenue, $this->customAccount('41', '4102', 'Moved revenue'));
        $this->nameItemAccount($cycle['perfume'], RoutingKey::SalesRevenue, $this->customAccount('41', '4103', 'Perfume revenue'));
        $this->routeRow(RoutingKey::SalesRevenue, RoutingType::Auto);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $mirror = static fn (string $line): string => implode(':', [explode(':', $line)[0], explode(':', $line)[2], explode(':', $line)[1]]);

        $this->assertSame(array_map($mirror, $original['lines']), $this->reversalLinesOf($original['id']));
        $this->assertSame(array_map($mirror, $cost['lines']), $this->reversalLinesOf($cost['id']));
        $this->assertContains($cycle['creamAccount'] . ':270.00:0.00', $this->reversalLinesOf($original['id']));
        $this->assertSame($original['lines'], $this->journals($saleId)[0]['lines']);
        $this->assertSame('0.00', $this->balance($cycle['creamAccount']));
        $this->assertSame('0.00', $this->balance($this->accountId('sales_revenue')));
    }

    public function testDelete_ARestoredPerItemSaleResolvesWithTheSettingsOfTheMomentAndLeavesTheOldJournalUntouched(): void
    {
        $cycle    = $this->perItemCycle();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS);
        $original = $this->standing($saleId, 'revenue')[0];

        $moved = $this->customAccount('41', '4102', 'Moved revenue');
        $this->nameItemAccount($cycle['cream'], RoutingKey::SalesRevenue, $moved);

        $this->sales->delete($saleId, false, true, $this->employeeId);
        $this->sales->restore_list([$saleId], $this->employeeId);
        $this->assertSame($saleId, $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS, -1, true, null, COMPLETED, $saleId));

        $revenue = array_values(array_filter($this->journals($saleId), static fn (array $journal): bool => $journal['entry'] === 'revenue'));

        $this->assertSame([1, 2], array_column($revenue, 'revision'));
        $this->assertSame(['reversed', 'posted'], array_column($revenue, 'status'));
        $this->assertSame($original['lines'], $revenue[0]['lines']);
        $this->assertContains($moved . ':0.00:270.00', $revenue[1]['lines']);
        $this->assertNotContains($cycle['creamAccount'] . ':0.00:270.00', $revenue[1]['lines']);

        $this->routeRow(RoutingKey::SalesRevenue, RoutingType::Auto);
        $this->sales->delete($saleId, false, true, $this->employeeId);
        $this->sales->restore_list([$saleId], $this->employeeId);
        $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS, -1, true, null, COMPLETED, $saleId);

        $third = array_values(array_filter($this->standing($saleId), static fn (array $journal): bool => $journal['entry'] === 'revenue'))[0];

        $this->assertSame(3, $third['revision']);
        $this->assertContains($this->accountId('sales_revenue') . ':0.00:710.00', $third['lines']);
    }

    public function testSaveValue_RejectsAPerItemSaleInAClosedPeriodWithoutLeavingAnyOfIt(): void
    {
        $cycle = $this->perItemCycle();
        $this->closeThrough(date('Y-m-d'));

        $sales = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes']));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame(0, $this->countJournals());
    }

    public function testDelete_MirrorsAPerItemSaleOfAClosedPeriodOnTheDayOfTheCancellation(): void
    {
        $cycle    = $this->perItemCycle();
        $saleId   = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS);
        $original = $this->standing($saleId, 'revenue')[0];

        $this->accountingDb()->table('accounting_journals')->whereIn('journal_id', array_column($this->journals($saleId), 'id'))->update(['journal_date' => '2026-03-10']);
        $this->closeThrough('2026-06-30');
        $this->nameItemAccount($cycle['cream'], RoutingKey::SalesRevenue, $this->customAccount('41', '4102', 'Moved revenue'));

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $original['id'])->get()->getRow();
        $mirror   = static fn (string $line): string => implode(':', [explode(':', $line)[0], explode(':', $line)[2], explode(':', $line)[1]]);

        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        $this->assertSame(array_map($mirror, $original['lines']), $this->reversalLinesOf($original['id']));
        $this->assertSame('0.00', $this->balance($cycle['creamAccount']));
    }

    public function testSaveValue_PostsAPerItemSaleOfASecondBranchInThatBranchWithTheAccountOfTheItem(): void
    {
        $branchId = $this->createBranch('B2', 'Second branch');
        $location = $this->branchLocation($branchId);
        $itemId   = $this->item('0', false);
        $account  = $this->customAccount('41', '4101', 'Item revenue');

        $this->nameItemAccount($itemId, RoutingKey::SalesRevenue, $account);
        $this->routeRow(RoutingKey::SalesRevenue, RoutingType::PerItem);

        $saleId  = $this->save([$this->line($itemId, 0, '1', '100', '0', '0', PERCENT, $location)]);
        $journal = $this->standing($saleId, 'revenue')[0];
        $branch  = $this->accountingDb()->table('accounting_journals')->select('branch_id')->where('journal_id', $journal['id'])->get()->getRow()->branch_id;

        $this->assertSame($branchId, (int) $branch);
        $this->assertContains($account . ':0.00:100.00', $journal['lines']);
    }

    public function testDelete_ReversesAPerItemSaleToTheZeroBalanceOfEveryAccountItTouched(): void
    {
        $cycle  = $this->perItemCycle();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '300.00')], SALE_TYPE_POS);

        $this->sales->delete($saleId, false, true, $this->employeeId);

        $this->assertSame([], $this->standing($saleId));

        foreach ([$this->customerAccount(), $cycle['creamAccount'], $this->accountId('sales_revenue'), $this->accountId('tax_output'), $this->accountId('cogs'), $this->stockAccount(), $this->accountId('cash')] as $accountId) {
            $this->assertSame('0.00', $this->balance($accountId), (string) $accountId);
        }
    }

    // A sale in a foreign currency
    // ---------------------------------------------------------------------------------------

    private function dollars(): Currencies
    {
        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => $this->daysAgo(30)]);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => $this->daysAgo(5)]);

        return $currencies;
    }

    /**
     * An invoice of a service for a price in dollars, dated ten days ago, when the dollar was at 3.75.
     *
     * @param list<array<string, mixed>> $payments
     */
    private function dollarInvoice(string $price = '100', array $payments = [], string $code = 'USD', ?string $typedRate = null): int
    {
        $this->grantInvoiceDate();

        $service = $this->item('0', false);

        $this->sales->useCurrency($code, $typedRate);

        return $this->save([$this->line($service, 0, '1', $price)], [], $payments, SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));
    }

    /**
     * @return list<string> "source:account:debit:credit" of every line of the standing journals of the sale, oldest first.
     */
    private function linesOfStanding(int $saleId): array
    {
        $lines = [];

        foreach ($this->standing($saleId) as $journal) {
            foreach ($journal['lines'] as $line) {
                $lines[] = $journal['source'] . ':' . $line;
            }
        }

        return $lines;
    }

    public function testSaveValue_PostsAnInvoiceInDollarsAtTheRateOfItsDateWithTheForeignAmountOnItsLines(): void
    {
        $this->dollars();

        $saleId = $this->dollarInvoice();

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(['USD', '3.75000000'], [$this->saleRow($saleId)->currency_code, $this->saleRow($saleId)->currency_rate]);
        $this->assertSame(['sale:' . $this->customerAccount() . ':375.00:0.00', 'sale:' . $this->accountId('sales_revenue') . ':0.00:375.00'], $this->linesOfStanding($saleId));

        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $saleId])->get()->getRow();
        $this->assertSame(['USD', '3.75000000'], [$journal->currency_code, $journal->exchange_rate]);

        $lines = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult();
        $this->assertSame(['100.0000', 'USD', '100.0000', 'USD'], [$lines[0]->foreign_amount, $lines[0]->currency_code, $lines[1]->foreign_amount, $lines[1]->currency_code]);
        $this->assertSame('375.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_KeepsTheCostOfTheGoodsOfAForeignSaleInTheBase(): void
    {
        $this->dollars();
        $this->grantInvoiceDate();

        $item = $this->item('40.00');

        $this->sales->useCurrency('USD');
        $saleId = $this->save([$this->line($item, 0, '2', '100', '40.00')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));

        $cost = $this->standing($saleId, 'cogs');
        $row  = $this->accountingDb()->table('accounting_journals')->where('journal_id', $cost[0]['id'])->get()->getRow();

        $this->assertSame([$this->accountId('cogs') . ':80.00:0.00', $this->stockAccount() . ':0.00:80.00'], $cost[0]['lines']);
        $this->assertSame(['SAR', '1.00000000'], [$row->currency_code, $row->exchange_rate]);
        $this->assertSame(0, $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $cost[0]['id'])->where('foreign_amount IS NOT NULL', null, false)->countAllResults());
    }

    public function testUpdate_PostsAPaymentAtTheRateOfItsDayAndTheLossAsAJournalOfItsOwn(): void
    {
        $this->dollars();
        $saleId = $this->dollarInvoice();

        $paymentId = $this->addPayment($saleId, '100.00');

        $this->assertSame(['3.60000000', 'USD'], [$this->paymentRow($saleId)->currency_rate, $this->paymentRow($saleId)->currency_code]);
        $this->assertSame([
            'sale:' . $this->customerAccount() . ':375.00:0.00',
            'sale:' . $this->accountId('sales_revenue') . ':0.00:375.00',
            'sale_payment:' . $this->accountId('cash') . ':360.00:0.00',
            'sale_payment:' . $this->customerAccount() . ':0.00:360.00',
            'sale_payment_fx:' . $this->accountId('fx_loss') . ':15.00:0.00',
            'sale_payment_fx:' . $this->customerAccount() . ':0.00:15.00',
        ], $this->linesOfStanding($saleId));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('15.00', $this->balance($this->accountId('fx_loss')));
        $this->assertSame($paymentId, (int) $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale_payment_fx')->get()->getRow()->source_id, 'The difference is tied to the payment by its source');
        $this->assertTies();
    }

    public function testUpdate_PostsTheGainWhenThePaymentIsAtAHigherRateThanTheInvoice(): void
    {
        $currencies = $this->dollars();
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.90', 'effective_date' => date('Y-m-d')]);
        $saleId = $this->dollarInvoice();

        $this->addPayment($saleId, '100.00');

        $this->assertSame(['sale_payment_fx:' . $this->customerAccount() . ':15.00:0.00', 'sale_payment_fx:' . $this->accountId('fx_gain') . ':0.00:15.00'], array_slice($this->linesOfStanding($saleId), -2));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('-15.00', $this->balance($this->accountId('fx_gain')));
        $this->assertTies();
    }

    public function testUpdate_TakesEachPaymentAtTheRateOfItsOwnDayAndNeverAgain(): void
    {
        $currencies = $this->dollars();
        $saleId     = $this->dollarInvoice('268.33');

        $first = $this->addPayment($saleId, '100.00');

        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.90', 'effective_date' => date('Y-m-d')]);
        $second = $this->addPayment($saleId, '168.33');

        $rates = array_column($this->accountingDb()->table('sales_payments')->select('payment_id, currency_rate')->where('sale_id', $saleId)->orderBy('payment_id')->get()->getResultArray(), 'currency_rate', 'payment_id');

        $this->assertSame(['3.60000000', '3.90000000'], [$rates[$first], $rates[$second]]);
        $this->assertSame('0.00', $this->balance($this->customerAccount()), 'The invoice is settled to the cent although the rounding of the parts differs');
        $this->assertTies();
        $this->assertSame('0.0000', $this->position()->open());
    }

    public function testUpdate_TakesBackTheExchangeDifferenceWithThePaymentThatCausedIt(): void
    {
        $this->dollars();
        $saleId    = $this->dollarInvoice();
        $paymentId = $this->addPayment($saleId, '100.00');

        $this->removePayment($saleId, $paymentId);

        $this->assertSame(['sale:' . $this->customerAccount() . ':375.00:0.00', 'sale:' . $this->accountId('sales_revenue') . ':0.00:375.00'], $this->linesOfStanding($saleId));
        $this->assertSame('375.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('fx_loss')));
        $this->assertSame(['reversed'], array_column($this->accountingDb()->table('accounting_journals')->select('status')->where('source_type', 'sale_payment_fx')->get()->getResultArray(), 'status'), 'The difference is kept, taken back');
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults(), 'The payment and its difference were both reversed');
        $this->assertTies();
    }

    public function testDelete_CancelsAForeignSaleWithItsPaymentAndItsExchangeDifferenceAndMirrorsTheForeignAmounts(): void
    {
        $currencies = $this->dollars();
        $item       = $this->item('0', false);

        $this->sales->useCurrency('USD');
        $saleId = $this->save([$this->line($item, 0, '1', '100')], [], [], SALE_TYPE_POS);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.90', 'effective_date' => date('Y-m-d')]);
        $this->addPayment($saleId, '100.00');

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));

        $this->assertSame([], $this->standing($saleId));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('fx_gain')));

        $reversal = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->orderBy('journal_id')->get()->getRow();
        $mirrored = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $reversal->journal_id)->get()->getResult();

        $this->assertSame(['USD', '3.60000000'], [$reversal->currency_code, $reversal->exchange_rate]);
        $this->assertSame('100.0000', $mirrored[0]->foreign_amount);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentChange(int $paymentId, string $amount): array
    {
        return ['comment' => 'change', 'payments' => [['payment_id' => $paymentId, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null]]];
    }

    public function testUpdate_RejectsAForeignPaymentInAClosedPeriodAndPostsNeitherThePaymentNorItsDifference(): void
    {
        $this->dollars();
        $saleId = $this->dollarInvoice();
        $this->closeThrough(date('Y-m-d'));
        $payments = $this->countRows('sales_payments');
        $journals = $this->countJournals();

        $this->assertFalse($this->sales->update($saleId, $this->paymentChange(NEW_ENTRY, '100.00')));

        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame([$payments, $journals], [$this->countRows('sales_payments'), $this->countJournals()]);
        $this->assertSame('375.00', $this->balance($this->customerAccount()));
        $this->assertSame('0.00', $this->balance($this->accountId('fx_loss')));
    }

    public function testUpdate_RejectsTakingBackAForeignPaymentInAClosedPeriodAndKeepsItsDifference(): void
    {
        $this->dollars();
        $saleId    = $this->dollarInvoice();
        $paymentId = $this->addPayment($saleId, '100.00');
        $this->closeThrough(date('Y-m-d'));
        $journals = $this->countJournals();

        $this->assertFalse($this->sales->update($saleId, $this->paymentChange($paymentId, '0')));

        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame('15.00', $this->balance($this->accountId('fx_loss')), 'the difference of the payment stands with the payment');
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
        $this->assertSame(1, $this->countRows('sales_payments'));
    }

    public function testDelete_KeepsAForeignSaleWithItsPaymentAndItsExchangeDifferenceWhenThePeriodIsClosed(): void
    {
        $currencies = $this->dollars();

        $this->sales->useCurrency('USD');

        $saleId = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_POS);

        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.90', 'effective_date' => date('Y-m-d')]);
        $this->addPayment($saleId, '100.00');
        $this->closeThrough(date('Y-m-d'));
        $journals = $this->countJournals();

        $this->assertFalse($this->sales->delete($saleId, false, true, $this->employeeId));

        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($saleId)->sale_status);
        $this->assertSame('-30.00', $this->balance($this->accountId('fx_gain')), 'a hundred dollars paid at 3.90 for a sale at 3.60');
    }

    public function testUpdate_ClosesTheForeignPaymentOnTheDayOfItsOwnPeriodAndNotOnTheDayOfTheInvoice(): void
    {
        $this->dollars();
        $saleId = $this->dollarInvoice();
        $this->closeThrough($this->daysAgo(3));

        $this->addPayment($saleId, '100.00');

        $this->assertSame('0.00', $this->balance($this->customerAccount()), 'the invoice is in a closed period, the payment of today is not');
        $this->assertSame('15.00', $this->balance($this->accountId('fx_loss')));
    }

    public function testSaveValue_RejectsAForeignSaleWithNoRateOnItsDay(): void
    {
        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => date('Y-m-d', strtotime('+3 days'))]);
        $before = $this->countSales();

        $saleId = $this->dollarInvoice();

        $this->assertSame(ACCOUNTING_FAILED, $saleId);
        $this->assertSame(AccountingError::RateMissing, $this->failure()->error);
        $this->assertSame($before, $this->countSales());
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_RefusesASwitchedOffCurrencyAndTakesTheBaseCurrencyAsNone(): void
    {
        $this->dollars();
        (new Currencies())->setActive('USD', false);

        $this->assertSame(ACCOUNTING_FAILED, $this->dollarInvoice());
        $this->assertSame(AccountingError::CurrencyInactive, $this->failure()->error);

        $base = $this->dollarInvoice('100', [], 'SAR');

        $this->assertGreaterThan(0, $base);
        $this->assertNull($this->saleRow($base)->currency_code);
        $this->assertNull($this->saleRow($base)->currency_rate);
    }

    public function testSaveValue_RefusesAForeignCurrencyWhileTheBooksAreOff(): void
    {
        $this->dollars();
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertSame(ACCOUNTING_FAILED, $this->dollarInvoice());
        $this->assertSame(AccountingError::ForeignCurrencyUnsupported, $this->failure()->error);
        $this->assertSame(0, $this->countSales());
    }

    public function testSaveValue_TakesATypedRateOnlyFromAnEmployeeWhoManagesCurrencies(): void
    {
        $this->dollars();

        $this->assertSame(ACCOUNTING_FAILED, $this->dollarInvoice('100', [], 'USD', '3.5'));
        $this->assertSame(AccountingError::PermissionDenied, $this->failure()->error);

        $this->accountingDb()->table('grants')->insert(['permission_id' => AccountingGuard::CURRENCIES_MANAGE, 'person_id' => $this->employeeId, 'menu_group' => 'home']);

        $saleId = $this->dollarInvoice('100', [], 'USD', '3.5');

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame('3.50000000', $this->saleRow($saleId)->currency_rate);
        $this->assertSame(['sale:' . $this->customerAccount() . ':350.00:0.00', 'sale:' . $this->accountId('sales_revenue') . ':0.00:350.00'], $this->linesOfStanding($saleId));
    }

    public function testSaveValue_MirrorsTheRateOfTheSaleInACreditNoteWhateverTheTableSaysNow(): void
    {
        $currencies = $this->dollars();
        $saleId     = $this->dollarInvoice();
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.00', 'effective_date' => date('Y-m-d')]);

        $service = $this->item('0', false);
        $return  = $this->save([$this->line($service, 0, '-1', '100')], [], [], SALE_TYPE_RETURN, -1, true, $saleId);

        $this->assertGreaterThan(0, $return);
        $this->assertSame(['USD', '3.75000000'], [$this->saleRow($return)->currency_code, $this->saleRow($return)->currency_rate]);
        $this->assertSame(['sale:' . $this->accountId('sales_returns') . ':375.00:0.00', 'sale:' . $this->customerAccount() . ':0.00:375.00'], $this->linesOfStanding($return));
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_RefusesAReturnInAnotherCurrencyThanTheSaleItTakesBack(): void
    {
        $this->dollars();
        (new Currencies())->setActive('EUR', true);
        $saleId  = $this->dollarInvoice();
        $service = $this->item('0', false);

        $this->sales->useCurrency('EUR');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($service, 0, '-1', '100')], [], [], SALE_TYPE_RETURN, -1, true, $saleId));
        $this->assertSame(AccountingError::DocumentCurrencyLocked, $this->failure()->error);

        $baseSale = $this->invoiceOf('100.00');
        $this->sales->useCurrency('USD');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$this->line($service, 0, '-1', '100')], [], [], SALE_TYPE_RETURN, -1, true, $baseSale));
        $this->assertSame(AccountingError::DocumentCurrencyLocked, $this->failure()->error);
    }

    public function testUpdate_RefusesToChangeTheCurrencyOrTheRateOfAPostedSale(): void
    {
        $this->dollars();
        (new Currencies())->setActive('EUR', true);
        $saleId = $this->dollarInvoice();

        $this->assertFalse($this->sales->update($saleId, ['currency_code' => 'EUR']));
        $this->assertSame(AccountingError::DocumentCurrencyLocked, $this->failure()->error);
        $this->assertFalse($this->sales->update($saleId, ['currency_rate' => '9.99']));
        $this->assertSame(AccountingError::DocumentCurrencyLocked, $this->failure()->error);
        $this->assertFalse($this->sales->update($saleId, ['currency_code' => null]));
        $this->assertSame(['USD', '3.75000000'], [$this->saleRow($saleId)->currency_code, $this->saleRow($saleId)->currency_rate]);

        $this->assertTrue($this->sales->update($saleId, ['currency_code' => 'USD', 'currency_rate' => '3.75', 'comment' => 'same']));
    }

    public function testSaveValue_PostsAZeroDecimalCurrencyInWholeUnits(): void
    {
        $currencies = new Currencies();
        $currencies->setActive('JPY', true);
        $currencies->rates()->add(['currency_code' => 'JPY', 'rate' => '0.02376000', 'effective_date' => $this->daysAgo(30)]);
        $this->grantInvoiceDate();

        $service = $this->item('0', false);
        $this->sales->useCurrency('JPY');
        $saleId = $this->save([$this->line($service, 0, '3', '1234.5')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(['sale:' . $this->customerAccount() . ':88.01:0.00', 'sale:' . $this->accountId('sales_revenue') . ':0.00:88.01'], $this->linesOfStanding($saleId));
        $this->assertSame('3704.0000', $this->accountingDb()->table('accounting_journal_lines')->where('account_id', $this->customerAccount())->get()->getRow()->foreign_amount);
    }

    public function testSaveValue_RefusesADocumentInAThreeDecimalCurrencyBecauseItsPricesAndPaymentsKeepTwoDecimals(): void
    {
        $currencies = new Currencies();
        $currencies->setActive('KWD', true);
        $currencies->rates()->add(['currency_code' => 'KWD', 'rate' => '12.04917000', 'effective_date' => $this->daysAgo(30)]);
        $before = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->dollarInvoice('10.125', [], 'KWD'));
        $this->assertSame(AccountingError::ForeignDecimalsUnsupported, $this->failure()->error);
        $this->assertSame($before, $this->countSales());
    }

    public function testSaveValue_RefusesTheRoundingOfCashOnAForeignSaleAndSavesNothing(): void
    {
        $this->dollars();
        $before = $this->countSales();

        $saleId = $this->dollarInvoice('100', [$this->payment(lang('Sales.cash'), '0.02', '0', 1)]);

        $this->assertSame(ACCOUNTING_FAILED, $saleId);
        $this->assertSame(PostingError::FxNotSupported, $this->failure()->error);
        $this->assertSame($before, $this->countSales());
    }

    public function testSaveValue_RefusesAForeignPaymentIntoATreasuryOfAnotherBranchAndSavesNothing(): void
    {
        $this->dollars();
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();
        $before = $this->countSales();

        $saleId = $this->dollarInvoice('100', [$this->payment(lang('Sales.cash'), '100.00') + ['treasury_id' => $treasury]]);

        $this->assertSame(ACCOUNTING_FAILED, $saleId);
        $this->assertSame(PostingError::FxNotSupported, $this->failure()->error);
        $this->assertSame($before, $this->countSales());
    }

    public function testSaveValue_RefusesAForeignSaleInAClosedPeriodAndSavesNothing(): void
    {
        $this->dollars();
        $this->closeThrough($this->daysAgo(3));
        $before = $this->countSales();

        $this->assertSame(ACCOUNTING_FAILED, $this->dollarInvoice());
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame($before, $this->countSales());
    }

    public function testSaveValue_PostsAForeignSaleInTheBranchOfItsStockAndItsPaymentsToo(): void
    {
        $this->dollars();
        $branch = $this->createBranch('B2');
        $this->grantInvoiceDate();
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $service = $this->item('0', false);
        $this->sales->useCurrency('USD');
        $saleId = $this->save([$this->line($service, 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($branch))], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));
        $this->addPayment($saleId, '100.00');

        $branches = array_unique(array_column($this->accountingDb()->table('accounting_journals')->select('branch_id')->whereIn('source_type', ['sale', 'sale_payment', 'sale_payment_fx'])->where('source_id', $saleId)->get()->getResultArray(), 'branch_id'));

        $this->assertSame([(string) $branch], array_values(array_map('strval', $branches)));
    }

    public function testSaveValue_LeavesTheJournalsOfABaseSaleWithoutAForeignAmountOrACurrency(): void
    {
        $saleId = $this->invoiceOf('230.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->assertSame(0, $this->accountingDb()->table('accounting_journal_lines l')->join('accounting_journals j', 'j.journal_id = l.journal_id')->where('j.source_id', $saleId)->groupStart()->where('l.foreign_amount IS NOT NULL', null, false)->orWhere('l.currency_code IS NOT NULL', null, false)->groupEnd()->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale_payment_fx')->countAllResults());
        $this->assertNull($this->saleRow($saleId)->currency_code);
    }

    public function testSaveValue_TiesTheCustomerTheAccountAndTheAgingInTheBaseForAForeignInvoiceAndABaseOne(): void
    {
        $this->dollars();
        $foreign = $this->dollarInvoice('268.33');
        $this->invoiceOf('50.00');

        $this->addPayment($foreign, '100.00');

        $this->assertTies();
        $this->assertSame(bcadd('1006.24', '50.00', 4) === '1056.2400' ? '1056.24' : '', bcadd($this->position()->ledgerBalance(), '375.00', 2), 'The ledger holds the open part of the foreign invoice in the base');
    }

    public function testUpdate_PostsAgainTheExchangeDifferenceOfALaterPaymentWhenAnEarlierOneIsRemoved(): void
    {
        $currencies = $this->dollars();
        $saleId     = $this->dollarInvoice('100.04');
        $first      = $this->addPayment($saleId, '50.02');
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.90', 'effective_date' => date('Y-m-d')]);
        $this->addPayment($saleId, '50.02');

        $this->assertSame(['sale_payment_fx:' . $this->customerAccount() . ':7.51:0.00', 'sale_payment_fx:' . $this->accountId('fx_gain') . ':0.00:7.51'], array_slice($this->linesOfStanding($saleId), -2), 'The second payment settles what is left of the invoice after the first');

        $this->removePayment($saleId, $first);

        $standing = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment_fx', 'status' => 'posted'])->get()->getResult();

        $this->assertCount(1, $standing, 'Only the difference of the payment that is left stands');
        $this->assertSame('2', (string) $standing[0]->revision, 'It is written again as the next revision');
        $this->assertSame(['sale_payment_fx:' . $this->customerAccount() . ':7.50:0.00', 'sale_payment_fx:' . $this->accountId('fx_gain') . ':0.00:7.50'], array_slice($this->linesOfStanding($saleId), -2), 'Alone it settles what the rate of the invoice says of its half');
    }

    public function testUpdate_RefusesAPaymentOfAForeignSaleWhenTheTableHasNoRateForItsDay(): void
    {
        $this->dollars();
        $saleId = $this->dollarInvoice();
        $this->accountingDb()->table('accounting_currency_rates')->truncate();

        $this->assertFalse($this->sales->update($saleId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));
        $this->assertSame(AccountingError::RateMissing, $this->failure()->error);
        $this->assertSame(0, $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->countAllResults());
    }

    public function testSaveValue_ForgetsTheCurrencyAfterOneSaveSoTheNextSaleIsInTheBase(): void
    {
        $this->dollars();

        $first = $this->dollarInvoice();
        $next  = $this->invoiceOf('50.00');

        $this->assertSame('USD', $this->saleRow($first)->currency_code);
        $this->assertNull($this->saleRow($next)->currency_code);
    }

    public function testIssueDraft_KeepsTheCurrencyOfTheDraftAndPostsItInThatCurrency(): void
    {
        $this->dollars();
        $service = $this->item('0', false);

        $this->sales->useCurrency('USD');
        $draft = $this->draftInvoice([$this->line($service, 0, '1', '100')]);

        $this->assertSame(['USD'], [$this->saleRow($draft)->currency_code]);

        $issued = $this->sales->issueDraft($draft, $this->employeeId);

        $this->assertSame($draft, $issued);
        $this->assertSame(['USD', '3.60000000'], [$this->saleRow($draft)->currency_code, $this->saleRow($draft)->currency_rate]);
        $this->assertSame(['sale:' . $this->customerAccount() . ':360.00:0.00', 'sale:' . $this->accountId('sales_revenue') . ':0.00:360.00'], $this->linesOfStanding($draft));
    }

    public function testSaveValue_KeepsTheRateOfAPostedForeignJournalWhenTheRateRowIsDeletedAndAnotherIsEntered(): void
    {
        $case       = $this->referenceCase('T-64', 'reference_quirk', 'a-posted-foreign-journal-keeps-its-rate', 'deliberate_difference');
        $currencies = $this->dollars();
        $saleId     = $this->dollarInvoice();
        $journalOf  = fn (): object => $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $saleId])->get()->getRow();
        $before     = $journalOf()->exchange_rate;

        $rate = $this->accountingDb()->table('accounting_currency_rates')->where(['currency_code' => 'USD', 'rate' => '3.75000000'])->get()->getRow();
        $currencies->rates()->delete((int) $rate->rate_id);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.74474', 'effective_date' => $this->daysAgo(30)]);

        $this->assertSameAsFixture($case['expected'], [
            'posted_journal_rate_after_the_rate_row_is_deleted' => $journalOf()->exchange_rate,
            'posted_journal_rate_before'                        => $before,
        ], 'the rate of the posted journal');
        $this->assertSame('3.75000000', $this->saleRow($saleId)->currency_rate);
        $this->assertSame('375.00', $this->balance($this->customerAccount()));
    }

    public function testSaveValue_RefusesAForeignLineWhoseValueInTheBaseIsUnderTheMinimumPriceAndSavesNothing(): void
    {
        $this->dollars();
        $this->grantInvoiceDate();
        $this->setConfigValue(SalesControlSettings::MINIMUM_PRICE, '1');

        $service = $this->item('0', false);
        $this->accountingDb()->table('items')->where('item_id', $service)->update(['minimum_price' => '50']);
        $this->sales->useCurrency('USD');
        $before = $this->countSales();

        try {
            $refused = $this->save([$this->line($service, 0, '1', '13')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));

            $this->assertSame(ACCOUNTING_FAILED, $refused, '13 dollars at 3.75 are 48.75 of the base');
            $this->assertSame(AccountingError::PriceBelowMinimum, $this->failure()->error);
            $this->assertSame($before, $this->countSales());

            $this->sales->useCurrency('USD');

            $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '1', '14')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10)), '14 dollars at 3.75 are 52.50 of the base');
        } finally {
            $this->setConfigValue(SalesControlSettings::MINIMUM_PRICE, '0');
            $this->accountingDb()->table('items')->where('item_id', $service)->update(['minimum_price' => '0']);
        }
    }

    public function testSaveValue_RefusesAForeignSaleThatIsPaidWithAGiftCardOrWithRewardPointsAndSavesNothing(): void
    {
        $this->dollars();
        $this->grantInvoiceDate();

        $service = $this->item('0', false);
        $before  = $this->countSales();

        foreach ([lang('Sales.giftcard') . ':GC-1', lang('Sales.rewards')] as $type) {
            $this->sales->useCurrency('USD');

            $saleId = $this->save([$this->line($service, 0, '1', '100')], [], [$this->payment($type, '100.00')], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));

            $this->assertSame(ACCOUNTING_FAILED, $saleId, $type);
            $this->assertSame(AccountingError::StoredValueInForeignCurrency, $this->failure()->error);
        }

        $this->assertSame($before, $this->countSales());
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_EarnsRewardPointsOnWhatAForeignSaleIsWorthInTheBase(): void
    {
        $this->dollars();

        $db     = $this->accountingDb();
        $before = config(OSPOS::class)->settings['customer_reward_enable'] ?? null;

        $db->table('customers_packages')->insert(['package_name' => 'Dollar gold', 'points_percent' => '20', 'deleted' => 0]);

        $packageId = (int) $db->insertID();

        $db->table('customers')->where('person_id', $this->customerId)->update(['package_id' => $packageId, 'points' => 0]);
        config(OSPOS::class)->settings['customer_reward_enable'] = '1';

        try {
            $saleId = $this->dollarInvoice('100', [$this->payment(lang('Sales.cash'), '100.00')]);

            $this->assertGreaterThan(0, $saleId);
            $this->assertSame('75', (string) $db->table('customers')->where('person_id', $this->customerId)->get()->getRow()->points, '20% of 100 dollars at 3.75 is 75 points and not 20');
            $this->assertSame('75.00', bcadd((string) $db->table('sales_reward_points')->where('sale_id', $saleId)->get()->getRow()->earned, '0', 2));
        } finally {
            config(OSPOS::class)->settings['customer_reward_enable'] = $before;
            $db->table('customers')->where('person_id', $this->customerId)->update(['package_id' => null, 'points' => null]);
            $db->table('customers_packages')->where('package_id', $packageId)->delete();
        }
    }

    // ---------------------------------------------------------------------------------------
    // The shipping charge that names a shipping company
    // ---------------------------------------------------------------------------------------

    /**
     * A shipping company the sales may name, with an account of what is owed to it when one is asked for.
     *
     * @return array{company: int, account: ?int}
     */
    private function shippingCompany(bool $withAccount = true, string $name = 'Fast Ship'): array
    {
        $this->turnOnHeaderCharges();

        $companies = new ShippingCompanies();
        $account   = $withAccount ? $this->customAccount('2212', '22121', 'Owed to ' . $name) : null;

        $companies->setEnabled(true);

        return ['company' => $companies->create(['name' => $name, 'account_id' => $account]), 'account' => $account];
    }

    public function testSaveValue_PostsTheShippingToTheAccountOfTheShippingCompanyItNamesInsteadOfTheRoutedRevenue(): void
    {
        $named = $this->shippingCompany();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $named['company']));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(
            [
                $this->customerAccount() . ':836.50:0.00',
                $this->accountId('sales_revenue') . ':0.00:710.00',
                $this->accountId('tax_output') . ':0.00:106.50',
                $named['account'] . ':0.00:20.00',
            ],
            $this->standing($saleId, 'revenue')[0]['lines'],
        );
        $this->assertSame((string) $named['company'], (string) $this->saleRow($saleId)->shipping_company_id);
        $this->assertSame('0.00', $this->balance($this->accountId('sales_shipping')));
        $this->assertSame('-20.00', $this->balance((int) $named['account']));
        $this->assertTies();
    }

    public function testSaveValue_PostsTheShippingToTheRoutedRevenueWhenTheCompanyItNamesHasNoAccount(): void
    {
        $named = $this->shippingCompany(false);

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $named['company']));

        $this->assertGreaterThan(0, $saleId);
        $this->assertContains($this->accountId('sales_shipping') . ':0.00:20.00', $this->standing($saleId, 'revenue')[0]['lines']);
        $this->assertSame((string) $named['company'], (string) $this->saleRow($saleId)->shipping_company_id);
        $this->assertTies();
    }

    public function testSaveValue_PostsToTheRoutedRevenueWhenNoCompanyIsNamed(): void
    {
        $named = $this->shippingCompany();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20'));

        $this->assertContains($this->accountId('sales_shipping') . ':0.00:20.00', $this->standing($saleId, 'revenue')[0]['lines']);
        $this->assertNull($this->saleRow($saleId)->shipping_company_id);
        $this->assertSame('0.00', $this->balance((int) $named['account']));
    }

    public function testSaveValue_PostsTheShippingOfACreditNoteToTheAccountOfTheCompanyItNames(): void
    {
        $named = $this->shippingCompany();

        $cycle    = $this->referenceInvoice();
        $original = $this->save($cycle['items'], $cycle['taxes']);
        $return   = $this->save([$this->line($cycle['cream'], 0, '-1', '0')], [], [], SALE_TYPE_RETURN, origin: $original, headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $named['company']));

        $this->assertGreaterThan(0, $return);
        $this->assertSame([$named['account'] . ':20.00:0.00', $this->customerAccount() . ':0.00:20.00'], $this->standing($return, 'revenue')[0]['lines']);
        $this->assertTies();
    }

    public function testSaveValue_PostsAnInvoiceOfFreeGoodsWithOnlyShippingToTheAccountOfTheCompanyAndConvertsItInADocumentCurrency(): void
    {
        $named = $this->shippingCompany();
        $this->dollars();
        $this->grantInvoiceDate();
        $this->sales->useCurrency('USD', null);

        $free   = $this->line($this->item('0', false), 0, '1', '0');
        $saleId = $this->save([$free], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10), headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $named['company']));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(['sale:' . $this->customerAccount() . ':75.00:0.00', 'sale:' . $named['account'] . ':0.00:75.00'], $this->linesOfStanding($saleId));
        $this->assertSame('-75.00', $this->balance((int) $named['account']));
    }

    public function testSaveValue_RefusesACompanyWhileTheSettingIsOffAndOneThatIsSwitchedOffOrMissing(): void
    {
        $named = $this->shippingCompany();
        $cycle = $this->referenceInvoice();
        $sales = $this->countSales();

        (new ShippingCompanies())->setEnabled(false);

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $named['company'])));
        $this->assertSame(AccountingError::ShippingCompaniesOff, $this->failure()->error);

        (new ShippingCompanies())->setEnabled(true);
        (new ShippingCompanies())->setActive($named['company'], false);

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $named['company'])));
        $this->assertSame(AccountingError::ShippingCompanyInvalid, $this->failure()->error);

        $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: 999999)));
        $this->assertSame(AccountingError::ShippingCompanyInvalid, $this->failure()->error);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
    }

    public function testSaveValue_StoresNoCompanyOnADocumentWithoutShipping(): void
    {
        $named = $this->shippingCompany();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges('Fee', '5', '0', shippingCompanyId: $named['company']));

        $this->assertGreaterThan(0, $saleId);
        $this->assertNull($this->saleRow($saleId)->shipping_company_id);
    }

    public function testGetHeaderCharges_GivesBackTheCompanyASavedSaleNamed(): void
    {
        $named = $this->shippingCompany();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $named['company']));

        $this->assertSame($named['company'], $this->sales->getHeaderCharges($saleId)->shippingCompanyId);
    }

    // Lines entered in a larger unit
    // ---------------------------------------------------------------------------------------

    public function testSaveValue_StoresALineEnteredInCartonsInBaseUnitsAndPostsRevenueAndCostFromThem(): void
    {
        $setup  = $this->cartonItem('5.00');
        $itemId = $setup['item'];

        $this->putOnShelf($itemId, '100');

        $line   = $this->inUnit($this->line($itemId, 0, '1', '1', '5.00'), $setup['carton'], '2', '120');
        $saleId = $this->save([$line]);

        $this->assertGreaterThan(0, $saleId);

        $stored = $this->accountingDb()->table('sales_items')->where(['sale_id' => $saleId, 'item_id' => $itemId])->get()->getRow();

        $this->assertSame(['24.000', '10.00', $setup['carton'], '12.0000', '2.000', '120.000000'], [$stored->quantity_purchased, $stored->item_unit_price, (int) $stored->unit_id, $stored->unit_factor, $stored->unit_quantity, $stored->unit_price_entered]);
        $this->assertSame([$this->customerAccount() . ':240.00:0.00', $this->accountId('sales_revenue') . ':0.00:240.00'], $this->standing($saleId, 'revenue')[0]['lines']);
        $this->assertSame('76.000', bcadd((string) $this->getItemQuantity($itemId, self::LOCATION_ID), '0', 3));

        $cost = $this->standing($saleId, 'cogs')[0]['lines'];

        $this->assertContains($this->accountId('cogs') . ':120.00:0.00', $cost);
        $this->assertContains($this->stockAccount() . ':0.00:120.00', $cost);
    }

    public function testIssueDraft_KeepsTheUnitOfALineEnteredInCartonsAndTheTemplateStaysUsed(): void
    {
        $setup  = $this->cartonItem('5.00');
        $itemId = $setup['item'];

        $this->putOnShelf($itemId, '100');

        $draft = $this->draftInvoice([$this->inUnit($this->line($itemId, 0, '1', '1', '5.00'), $setup['carton'], '2', '120')]);

        $this->assertGreaterThan(0, $draft);
        $this->assertSame($draft, $this->sales->issueDraft($draft, $this->employeeId));

        $stored = $this->accountingDb()->table('sales_items')->where(['sale_id' => $draft, 'item_id' => $itemId])->get()->getRow();

        $this->assertSame(['24.000', '10.00', $setup['carton'], '12.0000', '2.000', '120.000000'], [$stored->quantity_purchased, $stored->item_unit_price, (int) $stored->unit_id, $stored->unit_factor, $stored->unit_quantity, $stored->unit_price_entered]);
        $this->assertTrue((new UnitTemplates())->isUsed($setup['template']));
        $this->assertSame('76.000', bcadd((string) $this->getItemQuantity($itemId, self::LOCATION_ID), '0', 3));
        $this->assertSame([$this->customerAccount() . ':240.00:0.00', $this->accountId('sales_revenue') . ':0.00:240.00'], $this->standing($draft, 'revenue')[0]['lines']);
    }

    public function testSaveValue_StoresALineEnteredInCartonsInBaseUnitsWithTheBooksSwitchedOffAndPostsNothing(): void
    {
        $setup  = $this->cartonItem('5.00');
        $itemId = $setup['item'];

        $this->putOnShelf($itemId, '100');
        $this->setConfigValue('accounting_enabled', '0');

        $saleId = $this->save([$this->inUnit($this->line($itemId, 0, '1', '1', '5.00'), $setup['carton'], '2', '120')]);
        $stored = $this->accountingDb()->table('sales_items')->where(['sale_id' => $saleId, 'item_id' => $itemId])->get()->getRow();

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame(['24.000', '10.00', '2.000'], [$stored->quantity_purchased, $stored->item_unit_price, $stored->unit_quantity]);
        $this->assertSame('76.000', bcadd((string) $this->getItemQuantity($itemId, self::LOCATION_ID), '0', 3));
        $this->assertSame([], $this->journals($saleId));
    }

    public function testSaveValue_KeepsStockMovementsAndTheStockAccountExactInBaseUnitsWhenCartonsAreSold(): void
    {
        $setup  = $this->cartonItem('5.00');
        $itemId = $setup['item'];

        $this->putOnShelf($itemId, '50');
        $this->save([$this->inUnit($this->line($itemId, 0, '1', '1', '5.00'), $setup['carton'], '1.5', '90')]);

        $movements = $this->accountingDb()->table('accounting_stock_entries')->where('item_id', $itemId)->get()->getResult();

        $this->assertSame(['-18.000'], array_map(static fn (object $movement): string => bcadd((string) $movement->quantity, '0', 3), $movements));

        foreach ((new StockLedger())->reconcile() as $location) {
            $this->assertSame(bcadd($location['movements_value'], '0', 2), bcadd($location['account_balance'], '0', 2));
        }
    }

    public function testSaveValue_StoresNoUnitOnALegacyLine(): void
    {
        $itemId = $this->item('5.00');

        $this->putOnShelf($itemId, '10');

        $saleId = $this->save([$this->line($itemId, 0, '2', '10.00', '5.00')]);
        $stored = $this->accountingDb()->table('sales_items')->where(['sale_id' => $saleId, 'item_id' => $itemId])->get()->getRow();

        $this->assertSame([null, null, null, null], [$stored->unit_id, $stored->unit_factor, $stored->unit_quantity, $stored->unit_price_entered]);
    }

    public function testSaveValue_RejectsALineWhoseUnitIsNotOfTheTemplateOfItsItem(): void
    {
        $setup   = $this->cartonItem('5.00');
        $other   = $this->item('5.00');
        $line    = $this->inUnit($this->line($setup['item'], 0, '1', '1', '5.00'), $setup['carton'], '1', '120');
        $foreign = [...$line, 'item_id' => $other];

        $this->putOnShelf($other, '50');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$foreign]));
        $this->assertSame(AccountingError::UnitNotOfItem, $this->sales->accountingFailure()?->error);
        $this->assertSame(0, $this->accountingDb()->table('sales_items')->where('item_id', $other)->countAllResults());
    }

    public function testSaveValue_RejectsALineWhoseBaseQuantityIsNotWhatItsUnitConvertsTo(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->putOnShelf($setup['item'], '50');

        $line = [...$this->inUnit($this->line($setup['item'], 0, '1', '1', '5.00'), $setup['carton'], '2', '120'), 'quantity' => '23'];

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$line]));
        $this->assertSame(AccountingError::UnitQuantityInexact, $this->sales->accountingFailure()?->error);
        $this->assertSame('50.000', bcadd((string) $this->getItemQuantity($setup['item'], self::LOCATION_ID), '0', 3), 'the shelf is untouched');
    }

    public function testSaveValue_RejectsALineWhoseBasePriceIsNotWhatItsUnitConvertsTo(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->putOnShelf($setup['item'], '50');

        $line = [...$this->inUnit($this->line($setup['item'], 0, '1', '1', '5.00'), $setup['carton'], '2', '120'), 'price' => '9.99'];

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$line]));
        $this->assertSame(AccountingError::UnitQuantityInexact, $this->sales->accountingFailure()?->error);
    }

    public function testSaveValue_RejectsALineWhoseFactorIsNotTheOneOfItsUnit(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->putOnShelf($setup['item'], '50');

        $line = [...$this->inUnit($this->line($setup['item'], 0, '1', '1', '5.00'), $setup['carton'], '2', '120'), 'unit_factor' => '6.0000'];

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$line]));
        $this->assertSame(AccountingError::UnitQuantityInexact, $this->sales->accountingFailure()?->error);
    }

    public function testSaveValue_RejectsALineInAUnitWhileTheUnitsSettingIsOff(): void
    {
        $setup = $this->cartonItem('5.00');
        $line  = $this->inUnit($this->line($setup['item'], 0, '1', '1', '5.00'), $setup['carton'], '1', '120');

        $this->putOnShelf($setup['item'], '50');
        $this->setConfigValue(StockOptions::UNITS, '0');

        $this->assertSame(ACCOUNTING_FAILED, $this->save([$line]));
        $this->assertSame(AccountingError::UnitsOff, $this->sales->accountingFailure()?->error);
    }

    public function testSaveValue_PostsACreditNoteInBaseUnitsForAReturnOfASaleEnteredInCartons(): void
    {
        $setup  = $this->cartonItem('5.00');
        $itemId = $setup['item'];

        $this->putOnShelf($itemId, '100');

        $saleId   = $this->save([$this->inUnit($this->line($itemId, 0, '1', '1', '5.00'), $setup['carton'], '2', '120')]);
        $returnId = $this->save([$this->line($itemId, 0, '-12', '10.00', '5.00')], type: SALE_TYPE_RETURN, origin: $saleId);

        $this->assertGreaterThan(0, $returnId);
        $this->assertSame('88.000', bcadd((string) $this->getItemQuantity($itemId, self::LOCATION_ID), '0', 3));

        foreach ((new StockLedger())->reconcile() as $location) {
            $this->assertSame(bcadd($location['movements_value'], '0', 2), bcadd($location['account_balance'], '0', 2));
        }
    }

    private function register(): Sale_lib
    {
        bcscale(4);

        $register = new Sale_lib();
        $register->clear_all();
        $register->set_mode('sale');
        $register->set_customer($this->customerId);

        return $register;
    }

    /**
     * @return array{register: Sale_lib, line: string, setup: array{item: int, template: int, carton: int}}
     */
    private function registerWithPieces(string $pieces = '24'): array
    {
        $setup    = $this->cartonItem('5.00');
        $register = $this->register();
        $itemId   = (string) $setup['item'];
        $discount = '0';

        $this->putOnShelf($setup['item'], '100');
        $this->assertTrue($register->add_item($itemId, self::LOCATION_ID, $pieces, $discount, 0, PRICE_MODE_STANDARD, null, null, '10.00'));

        return ['register' => $register, 'line' => (string) array_key_last($register->get_cart()), 'setup' => $setup];
    }

    public function testChangeUnit_ShowsALineOfPiecesInCartonsWithoutChangingItsBaseQuantityOrItsTotal(): void
    {
        ['register' => $register, 'line' => $line, 'setup' => $setup] = $this->registerWithPieces();

        $this->assertTrue($register->changeUnit($line, $setup['carton']));

        $cart = $register->get_cart()[$line];

        $this->assertSame([$setup['carton'], '2.000', '120.000000', '12.0000'], [(int) $cart['unit_id'], $cart['unit_quantity'], $cart['unit_price'], $cart['unit_factor']]);
        $this->assertSame(['24', '10.00', '240.00'], [(string) $cart['quantity'], (string) $cart['price'], bcadd((string) $cart['total'], '0', 2)]);
    }

    public function testChangeUnit_RefusesAUnitTheQuantityDoesNotDivideInto(): void
    {
        ['register' => $register, 'line' => $line, 'setup' => $setup] = $this->registerWithPieces('25');

        $this->assertFalse($register->changeUnit($line, $setup['carton']));
        $this->assertSame(AccountingError::UnitQuantityInexact, $register->unitFailure()?->error);
        $this->assertArrayNotHasKey('unit_id', $register->get_cart()[$line]);
    }

    public function testChangeUnit_RefusesAUnitOfAnotherItem(): void
    {
        ['register' => $register, 'line' => $line] = $this->registerWithPieces();
        $other = $this->cartonItemOfAnotherTemplate();

        $this->assertFalse($register->changeUnit($line, $other));
        $this->assertSame(AccountingError::UnitNotOfItem, $register->unitFailure()?->error);
    }

    public function testChangeUnit_TakesALineBackToBaseUnitsWithANullUnit(): void
    {
        ['register' => $register, 'line' => $line, 'setup' => $setup] = $this->registerWithPieces();

        $register->changeUnit($line, $setup['carton']);

        $this->assertTrue($register->changeUnit($line, null));
        $this->assertArrayNotHasKey('unit_id', $register->get_cart()[$line]);
        $this->assertSame('24', (string) $register->get_cart()[$line]['quantity']);
    }

    public function testEditItem_ConvertsWhatIsTypedInCartonsToBaseUnitsAndKeepsWhatWasEntered(): void
    {
        ['register' => $register, 'line' => $line, 'setup' => $setup] = $this->registerWithPieces();

        $register->changeUnit($line, $setup['carton']);
        $register->edit_item($line, '', '', '3', '0', null, '150');

        $cart = $register->get_cart()[$line];

        $this->assertSame(['36.000', '12.50', '3.000', '150.000000', '450.00'], [(string) $cart['quantity'], (string) $cart['price'], $cart['unit_quantity'], $cart['unit_price'], bcadd((string) $cart['total'], '0', 2)]);
    }

    public function testEditItem_RefusesACartonPriceThatDoesNotDivideAndLeavesTheLineAsItWas(): void
    {
        ['register' => $register, 'line' => $line, 'setup' => $setup] = $this->registerWithPieces();

        $register->changeUnit($line, $setup['carton']);
        $register->edit_item($line, '', '', '2', '0', null, '100');

        $this->assertSame(AccountingError::UnitPriceInexact, $register->unitFailure()?->error);
        $this->assertSame(['24', '10.00'], [(string) $register->get_cart()[$line]['quantity'], (string) $register->get_cart()[$line]['price']]);
    }

    public function testAddItem_KeepsALineInCartonsWhileTheQuantityStillDividesAndGoesBackToPiecesWhenItDoesNot(): void
    {
        ['register' => $register, 'line' => $line, 'setup' => $setup] = $this->registerWithPieces();
        $itemId   = (string) $setup['item'];
        $discount = '0';

        $register->changeUnit($line, $setup['carton']);
        $register->add_item($itemId, self::LOCATION_ID, '12', $discount, 0, PRICE_MODE_STANDARD, null, null, '10.00');

        $this->assertSame(['36.000', '3.000'], [bcadd((string) $register->get_cart()[$line]['quantity'], '0', 3), $register->get_cart()[$line]['unit_quantity']]);

        $register->add_item($itemId, self::LOCATION_ID, '1', $discount, 0, PRICE_MODE_STANDARD, null, null, '10.00');

        $this->assertSame('37.000', bcadd((string) $register->get_cart()[$line]['quantity'], '0', 3));
        $this->assertArrayNotHasKey('unit_id', $register->get_cart()[$line]);
    }

    public function testSave_StoresTheLineOfTheRegisterInBaseUnitsAndCopyEntireSaleShowsItInCartonsAgain(): void
    {
        ['register' => $register, 'line' => $line, 'setup' => $setup] = $this->registerWithPieces();

        $register->changeUnit($line, $setup['carton']);
        $register->edit_item($line, '', '', '3', '0', null, '150');

        $saleId = $this->save(array_values($register->get_cart()));

        $this->assertGreaterThan(0, $saleId);

        $stored = $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->get()->getRow();

        $this->assertSame(['36.000', '12.50', '3.000'], [$stored->quantity_purchased, $stored->item_unit_price, $stored->unit_quantity]);

        $again = $this->register();
        $again->copy_entire_sale($saleId);
        $cart = array_values($again->get_cart())[0];

        $this->assertSame([$setup['carton'], '3.000', '150.000000'], [(int) $cart['unit_id'], $cart['unit_quantity'], $cart['unit_price']]);
    }

    public function testUnitChoices_ListsTheBaseUnitAndTheLargerUnitsOfTheTemplateOfEachItemAndIsEmptyWhileTheSettingIsOff(): void
    {
        ['register' => $register, 'setup' => $setup] = $this->registerWithPieces();

        $choices = $register->unitChoices($register->get_cart());

        $this->assertSame(['' => 'Piece', (string) $setup['carton'] => 'Carton (x12)'], $choices[$setup['item']]);

        $this->setConfigValue(StockOptions::UNITS, '0');

        $this->assertSame([], $register->unitChoices($register->get_cart()));
    }

    public function testEnterInUnit_ShowsTheLineOfAScannedUnitBarcodeInThatUnit(): void
    {
        ['register' => $register, 'setup' => $setup] = $this->registerWithPieces('12');

        $register->enterInUnit($setup['item'], self::LOCATION_ID, $setup['carton']);

        $this->assertSame('1.000', array_values($register->get_cart())[0]['unit_quantity']);
    }

    public function testUnitOfBarcode_ResolvesTheBarcodeOfAUnitToItsItemAndUnit(): void
    {
        ['register' => $register, 'setup' => $setup] = $this->registerWithPieces();

        (new UnitBarcodes())->assign($setup['item'], $setup['carton'], 'CTN-SCAN-1', $this->unitManagerId);

        $this->assertSame(['itemId' => $setup['item'], 'unitId' => $setup['carton']], $register->unitOfBarcode('CTN-SCAN-1'));
        $this->assertNull($register->unitOfBarcode('NOT-A-BARCODE'));
    }

    private function cartonItemOfAnotherTemplate(): int
    {
        $templateId = $this->template('Pallets', [['name' => 'Pallet', 'factor' => '100', 'small_name' => 'p']]);

        return $this->unitIdOf($templateId, 'Pallet');
    }

    // ---------------------------------------------------------------------------------------
    // Bundles
    // ---------------------------------------------------------------------------------------

    /**
     * A bundle of two components at 40 and 60 whose product, which keeps no stock, is priced at 150.
     *
     * @return array{kit: int, product: int, first: int, second: int}
     */
    private function bundleOfTwo(): array
    {
        $first   = $this->item('40.00');
        $second  = $this->item('60.00');
        $product = $this->item('0.00', false);

        $this->accountingDb()->table('items')->where('item_id', $product)->update(['item_type' => ITEM_KIT, 'unit_price' => '150.00']);

        $data = ['name' => 'Gift box', 'item_id' => $product, 'kit_discount' => 0, 'kit_discount_type' => 0, 'price_option' => PRICE_OPTION_KIT, 'print_option' => 0, 'description' => ''];

        $this->assertTrue(model(Item_kit::class)->save_value($data));

        $rows = [['item_id' => $first, 'quantity' => 1, 'kit_sequence' => 0], ['item_id' => $second, 'quantity' => 2, 'kit_sequence' => 1]];

        $this->assertTrue(model(Item_kit_items::class)->save_value($rows, (int) $data['item_kit_id']));

        return ['kit' => (int) $data['item_kit_id'], 'product' => $product, 'first' => $first, 'second' => $second];
    }

    public function testSaveValue_SellsABundleAtItsPriceAndTakesItsComponentsOutAtTheirCosts(): void
    {
        $bundle   = $this->bundleOfTwo();
        $register = $this->register();
        $discount = '0';
        $warning  = null;
        $product  = (string) $bundle['product'];

        $this->putOnShelf($bundle['first'], '10');
        $this->putOnShelf($bundle['second'], '10');
        $this->assertTrue($register->add_item($product, self::LOCATION_ID, '1', $discount, 0, PRICE_MODE_KIT, PRICE_OPTION_KIT, PRINT_ALL));
        $this->assertTrue($register->add_item_kit((string) $bundle['kit'], self::LOCATION_ID, 0.0, '0', true, true, $warning));

        $saleId = $this->save(array_values($register->get_cart()));

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([$this->customerAccount() . ':150.00:0.00', $this->accountId('sales_revenue') . ':0.00:150.00'], $this->standing($saleId, 'revenue')[0]['lines']);

        $cost = $this->standing($saleId, 'cogs')[0]['lines'];

        $this->assertContains($this->accountId('cogs') . ':160.00:0.00', $cost, 'a first component at 40 and two of a second at 60');
        $this->assertContains($this->stockAccount() . ':0.00:160.00', $cost);
        $this->assertSame(['9.000', '8.000'], [bcadd((string) $this->getItemQuantity($bundle['first'], self::LOCATION_ID), '0', 3), bcadd((string) $this->getItemQuantity($bundle['second'], self::LOCATION_ID), '0', 3)]);
        $this->assertSame('0.000', bcadd((string) $this->getItemQuantity($bundle['product'], self::LOCATION_ID), '0', 3), 'a bundle has no stock of its own');
    }

    public function testDelete_PutsTheComponentsOfACancelledBundleBack(): void
    {
        $bundle   = $this->bundleOfTwo();
        $register = $this->register();
        $discount = '0';
        $warning  = null;

        $this->putOnShelf($bundle['first'], '10');
        $this->putOnShelf($bundle['second'], '10');
        $product  = (string) $bundle['product'];

        $register->add_item($product, self::LOCATION_ID, '1', $discount, 0, PRICE_MODE_KIT, PRICE_OPTION_KIT, PRINT_ALL);
        $register->add_item_kit((string) $bundle['kit'], self::LOCATION_ID, 0.0, '0', true, true, $warning);

        $saleId = $this->save(array_values($register->get_cart()), [], [$this->payment(lang('Sales.cash'), '150.00')], SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($saleId, false, true, $this->employeeId));
        $this->assertSame(['10.000', '10.000'], [bcadd((string) $this->getItemQuantity($bundle['first'], self::LOCATION_ID), '0', 3), bcadd((string) $this->getItemQuantity($bundle['second'], self::LOCATION_ID), '0', 3)]);

        foreach ((new StockLedger())->reconcile() as $location) {
            $this->assertSame(bcadd($location['movements_value'], '0', 2), bcadd($location['account_balance'], '0', 2));
        }
    }

    // ---------------------------------------------------------------------------------------
    // Tracked items on the register
    // ---------------------------------------------------------------------------------------

    public function testAddItem_GivesATrackedSerialItemALineForEachScanSoEachCarriesItsOwnSerialNumber(): void
    {
        $itemId   = $this->trackedItem(TrackingType::Serial);
        $register = $this->register();
        $discount = '0';
        $code     = (string) $itemId;

        $register->add_item($code, self::LOCATION_ID, '1', $discount, 0, PRICE_MODE_STANDARD, null, null, '10.00');
        $register->add_item($code, self::LOCATION_ID, '1', $discount, 0, PRICE_MODE_STANDARD, null, null, '10.00');

        $cart = array_values($register->get_cart());

        $this->assertCount(2, $cart);
        $this->assertSame([true, true, 'serial'], [(bool) $cart[0]['is_serialized'], (bool) $cart[1]['is_serialized'], $cart[0]['tracking_type']]);
    }

    public function testPickBatch_RecordsTheBatchOfALineFollowsItsQuantityAndTakesItBackWithNothingPicked(): void
    {
        $itemId   = $this->trackedItem(TrackingType::Batch);
        $register = $this->register();
        $discount = '0';
        $code     = (string) $itemId;

        $register->add_item($code, self::LOCATION_ID, '2', $discount, 0, PRICE_MODE_STANDARD, null, null, '10.00');

        $line = (string) array_key_last($register->get_cart());

        $register->pickBatch($line, ' B-9 ');

        $this->assertSame([['number' => 'B-9', 'quantity' => '2']], $register->get_cart()[$line]['tracking']['batches']);

        $register->edit_item($line, '', '', '5', '0', null, '10.00');

        $this->assertSame('5', $register->get_cart()[$line]['tracking']['batches'][0]['quantity']);

        $register->pickBatch($line, '');

        $this->assertArrayNotHasKey('tracking', $register->get_cart()[$line]);
    }

    public function testSaveValue_RefusesANewSaleWhileOperationsAreFrozenAndLeavesNoSale(): void
    {
        $cycle = $this->referenceInvoice();
        $this->setConfigValue(OperationsHold::KEY, json_encode(['since' => '2026-11-16 10:00:00', 'by' => 1, 'reason' => 'Fixing the routing']));

        try {
            $sales = $this->countSales();

            $this->assertSame(ACCOUNTING_FAILED, $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '816.50')]));
            $this->assertSame($sales, $this->countSales());
            $this->assertSame(PostingError::OperationsHeld, $this->failure()->error);
            $this->assertSame(0, $this->countJournals());
        } finally {
            $this->setConfigValue(OperationsHold::KEY, '');
        }
    }

    public function testCancel_StaysOpenWhileOperationsAreFrozen(): void
    {
        $item   = $this->item('10.00');
        $saleId = $this->save([$this->line($item, 0, '1', '100', '10.00')], [], [$this->payment(lang('Sales.cash'), '100.00')], SALE_TYPE_POS);

        $this->setConfigValue(OperationsHold::KEY, json_encode(['since' => '2026-11-16 10:00:00', 'by' => 1, 'reason' => 'Fixing the routing']));

        try {
            (new SalePosting())->cancel($saleId);

            $this->assertSame(COMPLETED, (int) $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow()->sale_status);
            $this->assertFalse((new SalePosting())->isPosted($saleId));
        } finally {
            $this->setConfigValue(OperationsHold::KEY, '');
        }
    }
}
