<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screen of customer balances and aging: who may open it, what it lists, and that what it
 * shows is what the books hold.
 */
class AccountingReceivablesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

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
        $this->accountingDb()->table('document_numbers')->truncate();
        $this->accountingDb()->table('document_sequences')->truncate();

        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function viewer(?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_receivables'], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function rowsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_receivables/search' . $query)->getJSON(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function paymentsOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_receivables/payments/search' . $query)->getJSON(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function creditNotesOf(string $query = ''): array
    {
        return json_decode($this->get('/accounting_receivables/credit_notes/search' . $query)->getJSON(), true);
    }

    private function switchOn(DocumentType $type, string $prefix, int $padding = 3): void
    {
        (new DocumentNumbering())->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => 1]);
    }

    public function testGetIndex_ShowsTheTableToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables');

        $response->assertStatus(200);
        $response->assertSee('accounting_receivables');
        $response->assertSee(lang('Accounting_receivables.note'));
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables')->assertRedirect();
    }

    public function testGetIndex_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables');
    }

    public function testGetIndex_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables');
    }

    public function testGetSearch_ListsTheCustomerWithWhatTheyOweAndHowOldItIs(): void
    {
        $old = $this->invoiceOf('70.00');
        $this->invoiceOf('30.00');
        $this->accountingDb()->table('sales')->where('sale_id', $old)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime('-45 days'))]);
        $this->viewer();

        $result = $this->rowsOf();

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];

        $this->assertSame($this->customerId, $row['customer_id']);
        $this->assertSame('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('0.00', $row['credit']);
        $this->assertStringContainsString('30.00', $row['age_0_30']);
        $this->assertStringContainsString('70.00', $row['age_31_60']);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);
        $this->assertStringContainsString('accounting_receivables/customer/' . $this->customerId, $row['edit']);
    }

    public function testGetSearch_ShowsCreditAsANegativeBalance(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $this->creditNoteOf($invoice, '250.00');
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('-150.00', $row['balance']);
        $this->assertStringContainsString('150.00', $row['credit']);
        $this->assertStringContainsString('0.00', $row['open']);
    }

    public function testGetSearch_LeavesOutACustomerWhoseAccountIsEmpty(): void
    {
        $this->anotherCustomer();
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->viewer();

        $this->assertSame(['total' => 0, 'rows' => []], $this->rowsOf());
    }

    public function testGetSearch_FiltersByNameAndSortsByBalance(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->invoiceOf('100.00');
        $this->invoiceOf('300.00', $other);
        $this->viewer();

        $this->assertSame(['Hind Saad', 'Omar Khaled'], array_column($this->rowsOf('?sort=balance&order=desc')['rows'], 'customer_name'));
        $this->assertSame(['Omar Khaled', 'Hind Saad'], array_column($this->rowsOf('?sort=balance&order=asc')['rows'], 'customer_name'));
        $this->assertSame(['Hind Saad', 'Omar Khaled'], array_column($this->rowsOf('?sort=customer_name&order=asc')['rows'], 'customer_name'));
        $this->assertSame(['Hind Saad'], array_column($this->rowsOf('?search=hind')['rows'], 'customer_name'));
    }

    public function testGetSearch_PagesTheResult(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');

        $this->invoiceOf('100.00');
        $this->invoiceOf('300.00', $other);
        $this->viewer();

        $page = $this->rowsOf('?sort=balance&order=desc&limit=1&offset=1');

        $this->assertSame(2, $page['total']);
        $this->assertSame(['Omar Khaled'], array_column($page['rows'], 'customer_name'));
    }

    public function testGetSearch_WarnsOfWhatAManualJournalPutOnTheAccountOfTheCustomer(): void
    {
        $this->invoiceOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '25.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('125.00', $row['balance']);
        $this->assertStringContainsString('100.00', $row['open']);
        $this->assertStringContainsString('25.00', $row['agrees']);
        $this->assertStringNotContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);
    }

    public function testGetSearch_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/search');
    }

    public function testGetCustomer_ListsTheInvoicesTheCreditAndWhatWasAppliedToWhat(): void
    {
        $first = $this->invoiceOf('100.00');
        $this->creditNoteOf($first, '250.00');
        $this->invoiceOf('40.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertStatus(200);
        $response->assertSee('Omar Khaled');
        $response->assertSee('id="receivable_aging"');
        $response->assertSee('id="receivable_invoices"');
        $response->assertSee('id="receivable_credits"');
        $response->assertSee('id="receivable_history"');
        $response->assertSee('POS ' . $first);
        $response->assertSee(lang('Accounting_receivables.source_credit_note'));
        $response->assertSee(lang('Accounting_receivables.agrees_yes'));
        $response->assertSee(lang('Sales.invoice_paid'));
        $response->assertSee(lang('Sales.invoice_returned_full'));
    }

    public function testGetCustomer_NamesARefundInvoiceByItsDocumentAmongTheCreditAndTheHistory(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $refund  = $this->refundInvoiceOf($invoice, '30.00');
        $note    = $this->creditNoteOf($invoice, '20.00');
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);
        $body     = $response->getBody();

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, substr_count($body, lang('Numbering.type_refund_invoice')));
        $this->assertGreaterThanOrEqual(2, substr_count($body, lang('Accounting_receivables.source_credit_note')));
        $this->assertMatchesRegularExpression('/' . preg_quote(lang('Numbering.type_refund_invoice'), '/') . '\s*<a [^>]*sales\/receipt\/' . $refund . '"/', $body);
        $this->assertMatchesRegularExpression('/' . preg_quote(lang('Accounting_receivables.source_credit_note'), '/') . '\s*<a [^>]*sales\/receipt\/' . $note . '"/', $body);
    }

    public function testGetCustomer_KeepsTheReleasedApplicationsInTheHistory(): void
    {
        $first  = $this->invoiceOf('70.00');
        $second = $this->invoiceOf('230.00');
        $paid   = $this->addPayment($second, '300.00');

        $this->removePayment($second, $paid);
        $this->viewer();

        $response = $this->get('/accounting_receivables/customer/' . $this->customerId);

        $response->assertSee('POS ' . $first);
        $this->assertSame(1, $this->accountingDb()->table('customer_credit_applications')->where('released_at IS NOT NULL')->countAllResults());
        $response->assertDontSee(lang('Accounting_receivables.no_history'));
    }

    public function testGetCustomer_IsNotFoundForACustomerWithoutAnAccount(): void
    {
        $without = $this->insertCustomer('Nora', 'Fahd');
        $this->viewer();

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/customer/' . $without);
    }

    public function testGetCustomer_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/customer/' . $this->customerId);
    }

    public function testGetRow_GivesTheRowOfOneCustomer(): void
    {
        $this->invoiceOf('100.00');
        $this->viewer();

        $row = json_decode($this->get('/accounting_receivables/row/' . $this->customerId)->getJSON(), true);

        $this->assertSame('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('100.00', $row['balance']);
    }

    // ---------------------------------------------------------------------------------------
    // The tabs of the customer documents
    // ---------------------------------------------------------------------------------------

    public function testGetIndex_OffersTheListsOfPaymentsAndOfCreditNotesNextToTheBalances(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables');

        $response->assertSee(lang('Accounting_receivables.payments_tab'));
        $response->assertSee(lang('Accounting_receivables.credit_notes_tab'));
        $response->assertSee('accounting_receivables/payments');
        $response->assertSee('accounting_receivables/credit_notes');
    }

    public function testGetSearch_ShowsWhatEachCustomerWasReturnedAsReturnsAndAdjustments(): void
    {
        $invoice = $this->invoiceOf('300.00');
        $this->refundInvoiceOf($invoice, '25.00');
        $this->creditNoteOf($invoice, '40.00');
        $this->creditNoteOf($invoice, '10.00');
        $this->viewer();

        $row = $this->rowsOf()['rows'][0];

        $this->assertStringContainsString('25.00', $row['returns']);
        $this->assertStringContainsString('50.00', $row['adjustments']);
        $this->assertStringContainsString('225.00', $row['balance']);
        $this->assertStringContainsString(lang('Accounting_receivables.agrees_yes'), $row['agrees']);

        $single = json_decode($this->get('/accounting_receivables/row/' . $this->customerId)->getJSON(), true);

        $this->assertSame($row['returns'], $single['returns']);
        $this->assertSame($row['adjustments'], $single['adjustments']);
    }

    // ---------------------------------------------------------------------------------------
    // The list of the payments of the customers
    // ---------------------------------------------------------------------------------------

    public function testGetPayments_ShowsTheListWithItsFiltersToWhoeverHoldsTheModule(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables/payments');

        $response->assertStatus(200);
        $response->assertSee('id="payment_filters"');
        $response->assertSee('id="f_date_from"');
        $response->assertSee('id="f_customer"');
        $response->assertSee('id="f_way"');
        $response->assertSee('id="f_employee"');
        $response->assertSee('id="f_status"');
        $response->assertSee('id="payments_totals"');
        $response->assertSee(lang('Accounting_receivables.payments_note'));
        $response->assertDontSee('id="payments_partial"');
        $response->assertDontSee('id="f_branch"');
    }

    public function testGetPayments_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/payments')->assertRedirect();
        $this->get('/accounting_receivables/payments/search')->assertRedirect();
    }

    public function testGetPayments_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/payments');
    }

    public function testGetPaymentsSearch_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/payments/search');
    }

    public function testGetPaymentsSearch_ListsAPaymentWithItsNumberItsCustomerItsSaleItsWayItsCollectorAndItsStatus(): void
    {
        $saleId = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $this->viewer();

        $result = $this->paymentsOf();
        $row    = $result['rows'][0];
        $id     = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;

        $this->assertSame(1, $result['total']);
        $this->assertSame((int) $id, $row['payment_id']);
        $this->assertStringContainsString('>' . $id . '</a>', $row['number']);
        $this->assertStringContainsString('sales/receipt/' . $saleId, $row['number']);
        $this->assertStringContainsString('Omar Khaled', $row['customer_name']);
        $this->assertStringContainsString('accounting_receivables/customer/' . $this->customerId, $row['customer_name']);
        $this->assertStringContainsString('POS ' . $saleId, $row['sale']);
        $this->assertStringContainsString('60.00', $row['amount']);
        $this->assertStringContainsString('60.00', $row['posted']);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_booked'), $row['posted']);
        $this->assertStringContainsString(lang('Sales.cash'), $row['way']);
        $this->assertSame('', $row['treasury']);
        $this->assertSame('Temp Employee', $row['collected_by']);
        $this->assertStringContainsString(lang('Sales.payment_status_completed'), $row['status']);
        $this->assertArrayNotHasKey('branch', $row);
        $this->assertStringContainsString('60.00', $result['sum_amount']);
        $this->assertStringContainsString('60.00', $result['sum_posted']);
    }

    public function testGetPaymentsSearch_ShowsTheNumberOfTheSequenceAndTheIdOfAPaymentThatPredatesIt(): void
    {
        $before = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '10.00')]);
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4);
        $after = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '20.00')]);
        $this->viewer();

        $rows = [];

        foreach ($this->paymentsOf()['rows'] as $row) {
            $rows[$row['payment_id']] = $row['number'];
        }

        $beforeId = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $before)->get()->getRow()->payment_id;
        $afterId  = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $after)->get()->getRow()->payment_id;

        $this->assertStringContainsString('>PAY-0001</a>', $rows[$afterId]);
        $this->assertStringContainsString('>' . $beforeId . '</a>', $rows[$beforeId]);
        $this->assertStringNotContainsString('PAY-', $rows[$beforeId]);
    }

    public function testGetPaymentsSearch_NamesTheTreasuryTheMoneyWentInto(): void
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Front drawer', 'kind' => 'cash']);

        $treasuries->setDefaultFor($this->employeeId, $drawer);
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->viewer();

        $this->assertSame('Front drawer', $this->paymentsOf()['rows'][0]['treasury']);
    }

    public function testGetPaymentsSearch_TiesTheTotalsOfTheListToTheJournalsOfThePayments(): void
    {
        $first = $this->invoiceOf('816.50', null, [
            $this->payment(lang('Sales.cash'), '300.00'),
            $this->payment(lang('Sales.check'), '163.50'),
        ]);
        $other = $this->anotherCustomer();
        $this->invoiceOf('50.00', $other, [$this->payment(lang('Sales.cash'), '50.00')]);
        $this->addPayment($first, '25.00');
        $this->viewer();

        $result = $this->paymentsOf();
        $paid   = $this->accountingDb()->query('SELECT COALESCE(SUM(total_debit), 0) AS total FROM ' . $this->accountingDb()->prefixTable('accounting_journals') . " WHERE source_type = 'sale_payment' AND status = 'posted'")->getRow()->total;

        $this->assertSame(4, $result['total']);
        $this->assertSame(strip_tags($result['sum_amount']), strip_tags($result['sum_posted']));
        $this->assertStringContainsString(number_format((float) $paid, 2, '.', ''), $result['sum_amount']);

        foreach ($result['rows'] as $row) {
            $this->assertStringContainsString(lang('Accounting_receivables.tie_booked'), $row['posted']);
        }
    }

    public function testGetPaymentsSearch_SaysWhenAPaymentIsNotInTheBooksOrDiffersFromWhatItsJournalPosted(): void
    {
        $saleId = $this->invoiceOf('100.00');
        $this->addPayment($saleId, '40.00');
        $held = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'held', 'payments' => [['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '15.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null, 'payment_status' => 'incomplete']]]));
        $this->accountingDb()->table('sales_payments')->where('payment_id', $held)->update(['payment_amount' => '45.00']);
        $this->viewer();

        $rows = [];

        foreach ($this->paymentsOf()['rows'] as $row) {
            $rows[$row['payment_id']] = $row;
        }

        $this->assertCount(2, $rows);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_differs'), $rows[$held]['posted']);
        $this->assertStringContainsString(lang('Accounting_receivables.tie_unbooked'), $rows[max(array_keys($rows))]['posted']);
        $this->assertStringContainsString(lang('Sales.payment_status_incomplete'), $rows[max(array_keys($rows))]['status']);
    }

    public function testGetPaymentsSearch_ShowsARefundAsANegativeAmount(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $service = $this->item('0', false);
        $this->save([$this->line($service, 0, '-1', '30')], [], [$this->payment(lang('Sales.cash'), '-30.00')], SALE_TYPE_RETURN, -1, true, $invoice);
        $this->viewer();

        $result = $this->paymentsOf('?sort=amount&order=asc');

        $this->assertStringContainsString('-30.00', $result['rows'][0]['amount']);
        $this->assertStringContainsString('-30.00', $result['rows'][0]['posted']);
        $this->assertStringContainsString('70.00', $result['sum_amount']);
    }

    public function testGetPaymentsSearch_ShowsASaleWithoutACustomerAsASaleWithoutACustomer(): void
    {
        $service = $this->item('0', false);
        $this->save([$this->line($service, 0, '1', '25')], [], [$this->payment(lang('Sales.cash'), '25.00')], SALE_TYPE_POS, null);
        $this->viewer();

        $row = $this->paymentsOf()['rows'][0];

        $this->assertSame(lang('Accounting_receivables.counter_customer'), $row['customer_name']);
        $this->assertStringContainsString('25.00', $row['posted']);
    }

    public function testGetPaymentsSearch_FiltersByDatesCustomerWayEmployeeStatusAndSearch(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $mine  = $this->invoiceOf('100.00');
        $hers  = $this->invoiceOf('100.00', $other);
        $cash  = $this->addPayment($mine, '10.00');
        $check = $this->addPayment($hers, '20.00', lang('Sales.check'));
        $this->accountingDb()->table('sales_payments')->where('payment_id', $cash)->update(['payment_time' => date('Y-m-d 10:00:00', strtotime('-40 days'))]);
        $collector = $this->createEmployee('Layla', 'Hamad');
        $later     = $this->addPaymentFrom($hers, $collector, '5.00');
        $this->viewer();

        $ids = fn (array $result): array => array_column($result['rows'], 'payment_id');

        $this->assertEqualsCanonicalizing([$check, $later], $ids($this->paymentsOf('?date_from=' . date('Y-m-d', strtotime('-10 days')))));
        $this->assertSame([$cash], $ids($this->paymentsOf('?date_to=' . date('Y-m-d', strtotime('-10 days')))));
        $this->assertEqualsCanonicalizing([$check, $later], $ids($this->paymentsOf('?customer_id=' . $other)));
        $this->assertEqualsCanonicalizing([$check, $later], $ids($this->paymentsOf('?customer=hind')));
        $this->assertSame([$check], $ids($this->paymentsOf('?way=check')));
        $this->assertSame([$later], $ids($this->paymentsOf('?employee_id=' . $collector)));
        $this->assertSame([], $ids($this->paymentsOf('?status=failed')));
        $this->assertEqualsCanonicalizing([$cash, $check, $later], $ids($this->paymentsOf('?status=completed')));
        $this->assertSame([$cash], $ids($this->paymentsOf('?search=omar')));
        $this->assertContains($cash, $ids($this->paymentsOf('?search=' . $cash)));
        $this->assertSame(3, $this->paymentsOf('?way=nonsense')['total']);
    }

    public function testGetPaymentsSearch_PagesAndSortsTheResultAndClampsTheSizeOfAPage(): void
    {
        $sale = $this->invoiceOf('100.00');
        $a    = $this->addPayment($sale, '10.00');
        $b    = $this->addPayment($sale, '20.00');
        $c    = $this->addPayment($sale, '30.00');
        $this->viewer();

        $page = $this->paymentsOf('?sort=number&order=asc&limit=2&offset=1');

        $this->assertSame(3, $page['total']);
        $this->assertSame([$b, $c], array_column($page['rows'], 'payment_id'));
        $this->assertSame([$c, $b, $a], array_column($this->paymentsOf('?sort=amount&order=desc')['rows'], 'payment_id'));
        $this->assertSame(3, count($this->paymentsOf('?limit=100000')['rows']));
        $this->assertSame(1, count($this->paymentsOf('?limit=-5&offset=-9')['rows']));
        $this->assertSame(3, count($this->paymentsOf('?limit=0')['rows']));
    }

    public function testGetPaymentsSearch_ShowsEveryBranchToSomeoneWhoWorksInAllOfThemWithTheBranchColumn(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $here   = $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $there  = $this->addPayment($this->invoiceInBranch($branch, '100.00'), '20.00');
        $this->viewer();

        $result = $this->paymentsOf();
        $byId   = [];

        foreach ($result['rows'] as $row) {
            $byId[$row['payment_id']] = $row;
        }

        $this->assertEqualsCanonicalizing([$here, $there], array_keys($byId));
        $this->assertSame('HQ - ' . $this->accountingDb()->table('branches')->where('branch_id', 1)->get()->getRow()->name, $byId[$here]['branch']);
        $this->assertSame('B2 - Second branch', $byId[$there]['branch']);
        $this->assertSame([$there], array_column($this->paymentsOf('?branch_id=' . $branch)['rows'], 'payment_id'));
        $this->get('/accounting_receivables/payments')->assertSee('id="f_branch"');
    }

    public function testGetPaymentsSearch_ShowsSomeoneLimitedToABranchOnlyThePaymentsOfTheirBranches(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $third  = $this->createBranch('B3', 'Third branch');
        $here   = $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $there  = $this->addPayment($this->invoiceInBranch($branch, '100.00'), '20.00');
        $away   = $this->addPayment($this->invoiceInBranch($third, '500.00'), '400.00');
        $this->viewer([1, $branch]);

        $result = $this->paymentsOf();

        $this->assertEqualsCanonicalizing([$here, $there], array_column($result['rows'], 'payment_id'));
        $this->assertSame(2, $result['total']);
        $this->assertStringContainsString('30.00', $result['sum_amount']);
        $this->assertStringNotContainsString('430.00', $result['sum_amount']);
        $this->assertSame([], array_column($this->paymentsOf('?branch_id=' . $third)['rows'], 'payment_id'));
        $this->assertNotContains($away, array_column($this->paymentsOf('?search=' . $away)['rows'], 'payment_id'));
        $this->assertSame([$there], array_column($this->paymentsOf('?branch_id=' . $branch)['rows'], 'payment_id'));
        $this->assertArrayHasKey('branch', $result['rows'][0]);
    }

    public function testGetPayments_TellsSomeoneLimitedToSomeBranchesThatTheListIsPartial(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $this->createBranch('B3', 'Third branch');
        $this->viewer([1, $branch]);

        $response = $this->get('/accounting_receivables/payments');

        $response->assertStatus(200);
        $response->assertSee('id="payments_partial"');
        $response->assertSee(lang('Accounting_receivables.payments_partial'));
        $response->assertDontSee('accounting_receivables/credit_notes');
        $response->assertSee('nav-link py-1 active');
        $this->assertDoesNotMatchRegularExpression('/<a class="nav-link py-1" href="[^"]*">/', $response->getBody());
    }

    public function testGetPaymentsSearch_ListsNothingForSomeoneWhoseBranchesHoldNoPayment(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $this->viewer([$branch]);

        $this->assertSame(0, $this->paymentsOf()['total']);
        $this->assertSame([], $this->paymentsOf()['rows']);
    }

    public function testGetPaymentsSearch_LinksNoCustomerStatementForSomeoneLimitedToABranch(): void
    {
        $this->createBranch('B2', 'Second branch');
        $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $this->viewer([1]);

        $row = $this->paymentsOf()['rows'][0];

        $this->assertStringContainsString('Omar Khaled', $row['customer_name']);
        $this->assertStringNotContainsString('accounting_receivables/customer/', $row['customer_name']);
    }

    // ---------------------------------------------------------------------------------------
    // The list of credit notes and refund invoices
    // ---------------------------------------------------------------------------------------

    public function testGetCreditNotes_ShowsTheListWithItsFiltersToWhoeverHoldsTheModuleForTheCompany(): void
    {
        $this->viewer();

        $response = $this->get('/accounting_receivables/credit_notes');

        $response->assertStatus(200);
        $response->assertSee('id="credit_note_filters"');
        $response->assertSee('id="f_document"');
        $response->assertSee(lang('Accounting_receivables.credit_notes_note'));
        $response->assertSee(lang('Numbering.type_credit_note'));
        $response->assertSee(lang('Numbering.type_refund_invoice'));
    }

    public function testGetCreditNotes_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_chart']), 'menu_group' => 'home']);

        $this->get('/accounting_receivables/credit_notes')->assertRedirect();
        $this->get('/accounting_receivables/credit_notes/search')->assertRedirect();
    }

    public function testGetCreditNotes_IsNotFoundWhileAccountingIsOff(): void
    {
        $this->viewer();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/credit_notes');
    }

    public function testGetCreditNotes_IsNotFoundForSomeoneLimitedToABranchBecauseTheCreditCrossesBranches(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/credit_notes');
    }

    public function testGetCreditNotesSearch_IsNotFoundForSomeoneLimitedToABranch(): void
    {
        $this->viewer([1]);

        $this->expectException(PageNotFoundException::class);

        $this->get('/accounting_receivables/credit_notes/search');
    }

    public function testGetCreditNotesSearch_ListsACreditNoteAndARefundInvoiceWithTheirNumbersInvoicesAndWhatIsOpen(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 4);
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 4);

        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '70.00')]);
        $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['invoice_number' => 'INV-77']);
        $note   = $this->creditNoteOf($invoice, '50.00');
        $refund = $this->refundInvoiceOf($invoice, '10.00');
        $this->viewer();

        $result = $this->creditNotesOf('?sort=number&order=asc');
        $rows   = [];

        foreach ($result['rows'] as $row) {
            $rows[$row['sale_id']] = $row;
        }

        $this->assertSame(2, $result['total']);
        $this->assertStringContainsString('>CN-0001</a>', $rows[$note]['number']);
        $this->assertSame(lang('Numbering.type_credit_note'), $rows[$note]['document']);
        $this->assertStringContainsString('INV-77', $rows[$note]['origin']);
        $this->assertStringContainsString('sales/receipt/' . $invoice, $rows[$note]['origin']);
        $this->assertStringContainsString('Omar Khaled', $rows[$note]['customer_name']);
        $this->assertStringContainsString('50.00', $rows[$note]['amount']);
        $this->assertStringContainsString('30.00', $rows[$note]['settled']);
        $this->assertStringContainsString('20.00', $rows[$note]['open']);
        $this->assertStringContainsString('>RI-0001</a>', $rows[$refund]['number']);
        $this->assertSame(lang('Numbering.type_refund_invoice'), $rows[$refund]['document']);
        $this->assertStringContainsString('10.00', $rows[$refund]['amount']);
        $this->assertArrayNotHasKey('branch', $rows[$note]);
    }

    public function testGetCreditNotesSearch_ShowsTheReceiptNumberOfAReturnThatHasNoNumberAndNoInvoiceNumber(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '20.00');
        $this->viewer();

        $row = $this->creditNotesOf()['rows'][0];

        $this->assertStringContainsString('>POS ' . $note . '</a>', $row['number']);
        $this->assertStringContainsString('>POS ' . $invoice . '</a>', $row['origin']);
    }

    public function testGetCreditNotesSearch_SaysNoOriginalInvoiceAndNoSettlementForAReturnTheBooksDoNotHold(): void
    {
        $service = $this->item('0', false);
        $this->setConfigValue('accounting_enabled', '0');
        $invoice = $this->save([$this->line($service, 0, '1', '100')]);
        $this->save([$this->line($service, 0, '-1', '40')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $this->save([$this->line($service, 0, '-1', '25')], [], [], SALE_TYPE_RETURN, null);
        $this->setConfigValue('accounting_enabled', '1');
        $this->viewer();

        $rows = $this->creditNotesOf('?sort=number&order=asc')['rows'];

        $this->assertCount(2, $rows);
        $this->assertStringContainsString('40.00', $rows[0]['amount']);
        $this->assertStringContainsString(lang('Accounting_receivables.not_in_books'), $rows[0]['settled']);
        $this->assertSame('', $rows[0]['open']);
        $this->assertSame(lang('Accounting_receivables.no_original'), $rows[1]['origin']);
        $this->assertSame(lang('Accounting_receivables.counter_customer'), $rows[1]['customer_name']);
    }

    public function testGetCreditNotesSearch_FiltersByDocumentCustomerDatesAndSearchAndPages(): void
    {
        $other  = $this->anotherCustomer('Hind', 'Saad');
        $mine   = $this->invoiceOf('100.00');
        $hers   = $this->invoiceOf('100.00', $other);
        $note   = $this->creditNoteOf($mine, '10.00');
        $refund = $this->refundInvoiceOf($hers, '20.00', $other);
        $this->accountingDb()->table('sales')->where('sale_id', $note)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime('-30 days'))]);
        $this->viewer();

        $ids = fn (array $result): array => array_column($result['rows'], 'sale_id');

        $this->assertSame([$refund], $ids($this->creditNotesOf('?document=refund_invoice')));
        $this->assertSame([$note], $ids($this->creditNotesOf('?document=credit_note')));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?customer_id=' . $other)));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?customer=saad')));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?date_from=' . date('Y-m-d', strtotime('-5 days')))));
        $this->assertSame([$note], $ids($this->creditNotesOf('?date_to=' . date('Y-m-d', strtotime('-5 days')))));
        $this->assertSame([$refund], $ids($this->creditNotesOf('?search=' . $refund)));
        $this->assertSame([$refund, $note], $ids($this->creditNotesOf('?sort=number&order=desc')));
        $this->assertSame([$note], $ids($this->creditNotesOf('?sort=number&order=asc&limit=1&offset=0')));
        $this->assertSame(2, $this->creditNotesOf('?limit=1')['total']);
    }

    public function testGetCreditNotesSearch_ShowsTheBranchOfEachReturnToSomeoneWhoWorksInAllBranches(): void
    {
        $branch  = $this->createBranch('B2', 'Second branch');
        $service = $this->item('0', false);
        $here    = $this->invoiceOf('100.00');
        $there   = $this->invoiceInBranch($branch, '100.00');
        $first   = $this->creditNoteOf($here, '10.00');
        $second  = $this->save([$this->line($service, 0, '-1', '20', '0', '0', PERCENT, $this->branchLocation($branch))], [], [], SALE_TYPE_RETURN, -1, true, $there);
        $this->viewer();

        $rows = [];

        foreach ($this->creditNotesOf()['rows'] as $row) {
            $rows[$row['sale_id']] = $row;
        }

        $this->assertStringStartsWith('HQ - ', $rows[$first]['branch']);
        $this->assertSame('B2 - Second branch', $rows[$second]['branch']);
        $this->assertSame([$second], array_column($this->creditNotesOf('?branch_id=' . $branch)['rows'], 'sale_id'));
    }

    private function addPaymentFrom(int $saleId, int $employeeId, string $amount): int
    {
        $this->assertTrue($this->sales->update($saleId, ['comment' => 'paid', 'payments' => [
            ['payment_id' => NEW_ENTRY, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employeeId, 'reference_code' => null],
        ]]));

        return (int) $this->accountingDb()->table('sales_payments')->selectMax('payment_id', 'last')->where('sale_id', $saleId)->get()->getRow()->last;
    }
}
