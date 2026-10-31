<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CustomerDocuments;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The lists of the payments of the customers and of the returns issued to them: what each row says,
 * that the amounts tie to the books, what every filter keeps, and which branches a list may show.
 */
class CustomerDocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private CustomerDocuments $documents;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->documents = new CustomerDocuments();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('giftcards')->where('giftcard_number', 7774321)->delete();

        $this->stopSelling();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<int>|null       $branchIds
     *
     * @return array{total: int, amount: string, posted: string, rows: list<object>}
     */
    private function payments(array $filters = [], ?array $branchIds = null, string $sort = 'payment_time', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        return $this->documents->payments($filters, $sort, $order, $limit, $offset, $branchIds);
    }

    /**
     * @param array<string, mixed> $filters
     *
     * @return array{total: int, rows: list<array<string, mixed>>}
     */
    private function returns(array $filters = [], string $sort = 'sale_time', string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        return $this->documents->returns($filters, $sort, $order, $limit, $offset);
    }

    /**
     * @return list<int>
     */
    private function paymentIds(array $result): array
    {
        return array_map(static fn (object $row): int => (int) $row->payment_id, $result['rows']);
    }

    private function switchOn(DocumentType $type, string $prefix, int $padding = 3): void
    {
        (new DocumentNumbering())->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => 1]);
    }

    private function paymentsOf(int $saleId): array
    {
        return array_map('intval', array_column($this->accountingDb()->table('sales_payments')->select('payment_id')->where('sale_id', $saleId)->orderBy('payment_id')->get()->getResultArray(), 'payment_id'));
    }

    private function addPaymentBy(int $saleId, int $employeeId, string $amount, ?string $type = null, ?string $status = null, ?string $reference = null): int
    {
        $payment = ['payment_id' => NEW_ENTRY, 'payment_type' => $type ?? lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $employeeId, 'reference_code' => $reference];

        if ($status !== null) {
            $payment['payment_status'] = $status;
        }

        $this->assertTrue($this->sales->update($saleId, ['comment' => 'paid', 'payments' => [$payment]]));

        return (int) $this->accountingDb()->table('sales_payments')->selectMax('payment_id', 'last')->where('sale_id', $saleId)->get()->getRow()->last;
    }

    private function backdate(int $paymentId, string $date): void
    {
        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['payment_time' => $date . ' 10:00:00']);
    }

    // ---------------------------------------------------------------------------------------
    // Payments
    // ---------------------------------------------------------------------------------------

    public function testPayments_ListsAPaymentWithTheSaleTheCustomerTheWayTheEmployeeAndWhatItsJournalPosted(): void
    {
        $saleId = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);

        $result = $this->payments();

        $this->assertSame(1, $result['total']);
        $this->assertCount(1, $result['rows']);

        $row = $result['rows'][0];

        $this->assertSame($this->paymentsOf($saleId)[0], (int) $row->payment_id);
        $this->assertSame($saleId, (int) $row->sale_id);
        $this->assertSame($this->customerId, (int) $row->customer_id);
        $this->assertSame('Omar Khaled', $row->customer_name);
        $this->assertSame(lang('Sales.cash'), $row->payment_type);
        $this->assertSame($this->employeeId, (int) $row->employee_id);
        $this->assertSame('Temp Employee', $row->employee_name);
        $this->assertSame('completed', $row->payment_status);
        $this->assertSame('60.00', bcadd((string) $row->amount, '0', 2));
        $this->assertSame('60.00', bcadd((string) $row->posted, '0', 2));
        $this->assertNull($row->document_number);
        $this->assertSame('HQ', $row->branch_code);
        $this->assertSame('60.00', bcadd($result['amount'], '0', 2));
        $this->assertSame('60.00', bcadd($result['posted'], '0', 2));
    }

    public function testPayments_NamesTheTreasuryTheMoneyWentInto(): void
    {
        $this->accountingDb()->table('grants')->insert(['person_id' => $this->employeeId, 'permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => 'home']);

        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Front drawer', 'kind' => 'cash']);

        $treasuries->setDefaultFor($this->employeeId, $drawer);
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->assertSame('Front drawer', $this->payments()['rows'][0]->treasury_name);
        $this->assertSame($drawer, (int) $this->payments()['rows'][0]->treasury_id);
    }

    public function testPayments_TiesTheAmountOfEveryPaymentToTheJournalsOfTheBooks(): void
    {
        $first  = $this->invoiceOf('816.50', null, [
            $this->payment(lang('Sales.cash'), '300.00'),
            $this->payment(lang('Sales.check'), '163.50'),
            $this->payment(lang('Sales.bank_transfer'), '100.00'),
        ]);
        $other  = $this->anotherCustomer();
        $second = $this->invoiceOf('50.00', $other, [$this->payment(lang('Sales.cash'), '50.00')]);
        $this->addPayment($first, '25.00');

        $result = $this->payments();
        $posted = $this->accountingDb()->query(
            'SELECT COALESCE(SUM(j.total_debit), 0) AS total FROM ' . $this->accountingDb()->prefixTable('accounting_journals') . " j WHERE j.source_type = 'sale_payment' AND j.status = 'posted'",
        )->getRow()->total;

        $this->assertSame(5, $result['total']);
        $this->assertSame('638.50', bcadd($result['amount'], '0', 2));
        $this->assertSame(bcadd((string) $posted, '0', 2), bcadd($result['posted'], '0', 2));
        $this->assertSame($result['amount'], $result['posted']);

        foreach ($result['rows'] as $row) {
            $this->assertSame(bcadd((string) $row->amount, '0', 2), bcadd((string) $row->posted, '0', 2), 'payment ' . $row->payment_id);
        }

        $this->assertContains($second, array_map(static fn (object $row): int => (int) $row->sale_id, $result['rows']));
    }

    public function testPayments_ShowsARefundOfAReturnAsANegativeAmountThatTiesToItsJournal(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $service = $this->item('0', false);
        $return  = $this->save([$this->line($service, 0, '-1', '40')], [], [$this->payment(lang('Sales.cash'), '-40.00')], SALE_TYPE_RETURN, -1, true, $invoice);

        $result = $this->payments();
        $refund = array_values(array_filter($result['rows'], static fn (object $row): bool => (int) $row->sale_id !== $invoice))[0];

        $this->assertGreaterThan(0, $return);
        $this->assertSame(2, $result['total']);
        $this->assertSame('-40.00', bcadd((string) $refund->amount, '0', 2));
        $this->assertSame('-40.00', bcadd((string) $refund->posted, '0', 2));
        $this->assertSame('60.00', bcadd($result['amount'], '0', 2));
        $this->assertSame('60.00', bcadd($result['posted'], '0', 2));
    }

    public function testPayments_LeavesOutACreditSaleAndTheRoundingOfCashAndTheSalesThatAreNotCompleted(): void
    {
        $paid = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '50.00'), $this->payment(lang('Sales.due'), '50.00')]);
        $this->save([$this->line($this->item('0', false), 0, '1', '30')], [], [$this->payment(lang('Sales.cash'), '30.00')], SALE_TYPE_POS, -1, true, null, SUSPENDED);

        $rows = $this->payments()['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame($paid, (int) $rows[0]->sale_id);
        $this->assertSame(lang('Sales.cash'), $rows[0]->payment_type);
        $this->accountingDb()->table('sales_payments')->insert(['sale_id' => $paid, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0.50', 'cash_refund' => 0, 'cash_adjustment' => 1, 'employee_id' => $this->employeeId, 'reference_code' => '']);
        $this->assertCount(1, $this->payments()['rows']);
    }

    public function testPayments_ShowsAPaymentThatIsNotCompletedWithNothingInTheBooks(): void
    {
        $saleId = $this->invoiceOf('100.00');
        $this->addPaymentBy($saleId, $this->employeeId, '40.00', null, 'incomplete');

        $row = $this->payments()['rows'][0];

        $this->assertSame('incomplete', $row->payment_status);
        $this->assertSame('40.00', bcadd((string) $row->amount, '0', 2));
        $this->assertSame('0.00', bcadd((string) $row->posted, '0', 2));
        $this->assertSame('0.00', bcadd($this->payments()['posted'], '0', 2));
        $this->assertSame('40.00', bcadd($this->payments()['amount'], '0', 2));
    }

    public function testPayments_GivesAPaymentTheNumberOfItsSequenceAndLeavesThoseBeforeItWithoutOne(): void
    {
        $before = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '10.00')]);

        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4);

        $after = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '20.00')]);

        $numbers = [];

        foreach ($this->payments()['rows'] as $row) {
            $numbers[(int) $row->sale_id] = $row->document_number;
        }

        $this->assertSame([$after => 'PAY-0001', $before => null], $numbers);
    }

    public function testPayments_FiltersByTheDatesOfThePayments(): void
    {
        $old    = $this->invoiceOf('100.00');
        $recent = $this->invoiceOf('100.00');
        $oldId  = $this->addPayment($old, '10.00');
        $newId  = $this->addPayment($recent, '20.00');
        $this->backdate($oldId, date('Y-m-d', strtotime('-40 days')));
        $this->backdate($newId, date('Y-m-d', strtotime('-2 days')));

        $this->assertSame([$newId], $this->paymentIds($this->payments(['date_from' => date('Y-m-d', strtotime('-10 days'))])));
        $this->assertSame([$oldId], $this->paymentIds($this->payments(['date_to' => date('Y-m-d', strtotime('-10 days'))])));
        $this->assertSame([$oldId], $this->paymentIds($this->payments(['date_from' => date('Y-m-d', strtotime('-40 days')), 'date_to' => date('Y-m-d', strtotime('-40 days'))])));
        $this->assertSame([$newId, $oldId], $this->paymentIds($this->payments(['date_from' => 'not a date', 'date_to' => '2020-13-45'])));
    }

    public function testPayments_FiltersByCustomerByIdAndByName(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $mine  = $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $hers  = $this->addPayment($this->invoiceOf('100.00', $other), '20.00');

        $this->assertSame([$hers], $this->paymentIds($this->payments(['customer_id' => $other])));
        $this->assertSame([$mine], $this->paymentIds($this->payments(['customer_id' => $this->customerId])));
        $this->assertSame([$hers], $this->paymentIds($this->payments(['customer' => 'hind'])));
        $this->assertSame([$hers], $this->paymentIds($this->payments(['customer' => 'Hind Saad'])));
        $this->assertSame([], $this->paymentIds($this->payments(['customer' => 'nobody'])));
        $this->assertSame([], $this->paymentIds($this->payments(['customer' => '%'])));
    }

    public function testPayments_FiltersByTheWayOfPaymentIncludingTheGiftCardWhoseNameCarriesItsNumber(): void
    {
        $this->accountingDb()->table('giftcards')->insert(['giftcard_number' => 7774321, 'value' => 500, 'deleted' => 0, 'person_id' => null]);

        $sale  = $this->invoiceOf('300.00');
        $cash  = $this->addPayment($sale, '10.00');
        $check = $this->addPayment($sale, '20.00', lang('Sales.check'));
        $card  = $this->addPayment($sale, '30.00', lang('Sales.giftcard') . ':7774321');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $card)->update(['payment_type' => lang('Sales.giftcard') . ':7774321']);

        $this->assertSame([$cash], $this->paymentIds($this->payments(['way' => 'cash'])));
        $this->assertSame([$check], $this->paymentIds($this->payments(['way' => 'check'])));
        $this->assertSame([$card], $this->paymentIds($this->payments(['way' => 'giftcard'])));
        $this->assertSame([], $this->paymentIds($this->payments(['way' => 'bank_transfer'])));
        $this->assertSame([$card, $check, $cash], $this->paymentIds($this->payments(['way' => 'not a way'])));
    }

    public function testPayments_FiltersByTheEmployeeWhoCollected(): void
    {
        $other = $this->createEmployee('Layla', 'Hamad');
        $sale  = $this->invoiceOf('100.00');
        $mine  = $this->addPayment($sale, '10.00');
        $hers  = $this->addPaymentBy($sale, $other, '20.00');

        $this->assertSame([$hers], $this->paymentIds($this->payments(['employee_id' => $other])));
        $this->assertSame([$mine], $this->paymentIds($this->payments(['employee_id' => $this->employeeId])));

        $row = $this->payments(['employee_id' => $other])['rows'][0];

        $this->assertSame('Layla Hamad', $row->employee_name);
        $this->assertEqualsCanonicalizing([$other, $this->employeeId], array_map(static fn (object $collector): int => (int) $collector->employee_id, $this->documents->collectors(null)));
    }

    public function testPayments_FiltersByTheStatusOfThePayment(): void
    {
        $sale = $this->invoiceOf('100.00');
        $done = $this->addPayment($sale, '10.00');
        $held = $this->addPaymentBy($sale, $this->employeeId, '20.00', null, 'review');

        $this->assertSame([$held], $this->paymentIds($this->payments(['status' => 'review'])));
        $this->assertSame([$done], $this->paymentIds($this->payments(['status' => 'completed'])));
        $this->assertSame([$held, $done], $this->paymentIds($this->payments(['status' => 'unknown'])));
    }

    public function testPayments_FiltersBySearchingTheNumberTheCustomerTheReferenceAndTheInvoice(): void
    {
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4);

        $sale  = $this->invoiceOf('100.00');
        $first = $this->addPaymentBy($sale, $this->employeeId, '10.00', lang('Sales.check'), null, 'CHQ-77');
        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['invoice_number' => 'INV-SEARCH-9']);
        $other  = $this->anotherCustomer('Hind', 'Saad');
        $second = $this->addPayment($this->invoiceOf('100.00', $other), '20.00');

        $this->assertSame([$first], $this->paymentIds($this->payments(['search' => 'PAY-0001'])));
        $this->assertSame([$second], $this->paymentIds($this->payments(['search' => 'PAY-0002'])));
        $this->assertSame([$second], $this->paymentIds($this->payments(['search' => 'saad'])));
        $this->assertSame([$first], $this->paymentIds($this->payments(['search' => 'CHQ-77'])));
        $this->assertSame([$first], $this->paymentIds($this->payments(['search' => 'INV-SEARCH'])));
        $this->assertSame([$first], $this->paymentIds($this->payments(['search' => '#' . $sale])));
        $this->assertSame([], $this->paymentIds($this->payments(['search' => '100%'])));
    }

    public function testPayments_FindsAPaymentThatCarriesNoNumberByItsId(): void
    {
        $paymentId = $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $this->addPayment($this->invoiceOf('100.00'), '20.00');

        $this->assertContains($paymentId, $this->paymentIds($this->payments(['search' => (string) $paymentId])));
    }

    public function testPayments_SortsByAmountByNumberAndByCustomerAndFallsBackToTheDateForAnythingElse(): void
    {
        $other = $this->anotherCustomer('Aya', 'Zaki');
        $big   = $this->addPayment($this->invoiceOf('100.00'), '90.00');
        $small = $this->addPayment($this->invoiceOf('100.00', $other), '10.00');
        $this->backdate($small, date('Y-m-d', strtotime('-5 days')));

        $this->assertSame([$big, $small], $this->paymentIds($this->payments([], null, 'amount', 'desc')));
        $this->assertSame([$small, $big], $this->paymentIds($this->payments([], null, 'amount', 'asc')));
        $this->assertSame([$small, $big], $this->paymentIds($this->payments([], null, 'number', 'desc')));
        $this->assertSame([$big, $small], $this->paymentIds($this->payments([], null, 'number', 'asc')));
        $this->assertSame([$small, $big], $this->paymentIds($this->payments([], null, 'customer_name', 'asc')));
        $this->assertSame([$big, $small], $this->paymentIds($this->payments([], null, 'nothing; DROP TABLE x', 'sideways')));
    }

    public function testPayments_PagesTheResultAndCountsAllOfIt(): void
    {
        $sale = $this->invoiceOf('100.00');
        $ids  = [$this->addPayment($sale, '10.00'), $this->addPayment($sale, '20.00'), $this->addPayment($sale, '30.00')];

        $page = $this->payments([], null, 'number', 'asc', 2, 1);

        $this->assertSame(3, $page['total']);
        $this->assertSame([$ids[1], $ids[2]], $this->paymentIds($page));
        $this->assertSame('60.00', bcadd($page['amount'], '0', 2));
        $this->assertSame([$ids[0]], $this->paymentIds($this->payments([], null, 'number', 'asc', 1, 0)));
    }

    public function testPayments_KeepsToTheBranchesOfTheListAndFiltersByBranch(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $here   = $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $there  = $this->addPayment($this->invoiceInBranch($branch, '100.00'), '20.00');

        $this->assertEqualsCanonicalizing([$here, $there], $this->paymentIds($this->payments([], null)));
        $this->assertSame([$here], $this->paymentIds($this->payments([], [1])));
        $this->assertSame([$there], $this->paymentIds($this->payments([], [$branch])));
        $this->assertEqualsCanonicalizing([$here, $there], $this->paymentIds($this->payments([], [1, $branch])));
        $this->assertSame([], $this->paymentIds($this->payments([], [])));
        $this->assertSame([$there], $this->paymentIds($this->payments(['branch_id' => $branch], null)));
        $this->assertSame([], $this->paymentIds($this->payments(['branch_id' => $branch], [1])));
        $this->assertSame('20.00', bcadd($this->payments([], [$branch])['amount'], '0', 2));
        $this->assertSame('B2', $this->payments([], [$branch])['rows'][0]->branch_code);
    }

    public function testPayments_ClampsThePageToAtLeastOneRowAndAnOffsetOfAtLeastZero(): void
    {
        $sale = $this->invoiceOf('100.00');
        $a    = $this->addPayment($sale, '10.00');
        $this->addPayment($sale, '20.00');

        $this->assertSame([$a], $this->paymentIds($this->payments([], null, 'number', 'asc', 0, -3)));
        $this->assertSame([$a], $this->paymentIds($this->payments([], null, 'number', 'asc', -7, 0)));
    }

    public function testReturns_ClampsThePageToAtLeastOneRowAndAnOffsetOfAtLeastZero(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $first   = $this->creditNoteOf($invoice, '10.00');
        $this->creditNoteOf($invoice, '20.00');

        $this->assertSame([$first], array_column($this->returns([], 'number', 'asc', 0, -3)['rows'], 'sale_id'));
    }

    public function testCollectors_ListsOnlyThePeopleWhoCollectedInTheBranchesOfTheList(): void
    {
        $branch = $this->createBranch('B2', 'Second branch');
        $other  = $this->createEmployee('Layla', 'Hamad');

        $this->addPayment($this->invoiceOf('100.00'), '10.00');
        $this->addPaymentBy($this->invoiceInBranch($branch, '100.00'), $other, '20.00');

        $this->assertSame([$this->employeeId], array_map(static fn (object $row): int => (int) $row->employee_id, $this->documents->collectors([1])));
        $this->assertSame([$other], array_map(static fn (object $row): int => (int) $row->employee_id, $this->documents->collectors([$branch])));
        $this->assertSame([], $this->documents->collectors([]));
    }

    // ---------------------------------------------------------------------------------------
    // Returns: credit notes and refund invoices
    // ---------------------------------------------------------------------------------------

    public function testReturns_ListsACreditNoteWithItsInvoiceItsCustomerWhatItCameToAndWhatIsStillOpen(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $note    = $this->creditNoteOf($invoice, '40.00');

        $result = $this->returns();

        $this->assertSame(1, $result['total']);

        $row = $result['rows'][0];

        $this->assertSame($note, $row['sale_id']);
        $this->assertSame(ReturnDocument::CreditNote, $row['document']);
        $this->assertSame($invoice, $row['origin_id']);
        $this->assertSame('Omar Khaled', $row['customer_name']);
        $this->assertTrue($row['in_books']);
        $this->assertSame('40.00', bcadd($row['amount'], '0', 2));
        $this->assertSame('0.00', bcadd($row['settled'], '0', 2));
        $this->assertSame('40.00', bcadd($row['open'], '0', 2));
        $this->assertNull($row['document_number']);
    }

    public function testReturns_SaysWhatWasSettledIntoTheInvoiceAndWhatIsLeft(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '70.00')]);
        $note    = $this->creditNoteOf($invoice, '50.00');

        $row = $this->returns()['rows'][0];

        $this->assertSame($note, $row['sale_id']);
        $this->assertSame('50.00', bcadd($row['amount'], '0', 2));
        $this->assertSame('30.00', bcadd($row['settled'], '0', 2));
        $this->assertSame('20.00', bcadd($row['open'], '0', 2));
        $this->assertSame(bcadd($row['settled'], $row['open'], 2), bcadd($row['amount'], '0', 2));
        $this->assertTies();
    }

    public function testReturns_GivesEachReturnTheSettlementOfItsOwnWhenSeveralShareAnInvoice(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '70.00')]);
        $first   = $this->creditNoteOf($invoice, '50.00');
        $second  = $this->refundInvoiceOf($invoice, '10.00');

        $rows = [];

        foreach ($this->returns([], 'number', 'asc')['rows'] as $row) {
            $rows[$row['sale_id']] = $row;
        }

        $this->assertSame(['30.00', '20.00'], [bcadd($rows[$first]['settled'], '0', 2), bcadd($rows[$first]['open'], '0', 2)]);
        $this->assertSame(['0.00', '10.00'], [bcadd($rows[$second]['settled'], '0', 2), bcadd($rows[$second]['open'], '0', 2)]);
    }

    public function testReturns_CountsWhatWasPaidBackInCashAsSettled(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);
        $service = $this->item('0', false);
        $this->save([$this->line($service, 0, '-1', '100')], [], [$this->payment(lang('Sales.cash'), '-100.00')], SALE_TYPE_RETURN, -1, true, $invoice);

        $row = $this->returns()['rows'][0];

        $this->assertSame('100.00', bcadd($row['amount'], '0', 2));
        $this->assertSame('100.00', bcadd($row['settled'], '0', 2));
        $this->assertSame('0.00', bcadd($row['open'], '0', 2));
    }

    public function testReturns_ListsARefundInvoiceLikeACreditNoteWithItsOwnNumber(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 4);
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 4);

        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '30.00');
        $refund  = $this->refundInvoiceOf($invoice, '30.00');

        $rows = [];

        foreach ($this->returns(['search' => ''], 'number', 'asc')['rows'] as $row) {
            $rows[$row['sale_id']] = $row;
        }

        $this->assertSame(ReturnDocument::CreditNote, $rows[$note]['document']);
        $this->assertSame('CN-0001', $rows[$note]['document_number']);
        $this->assertSame(ReturnDocument::RefundInvoice, $rows[$refund]['document']);
        $this->assertSame('RI-0001', $rows[$refund]['document_number']);
        $this->assertSame($rows[$note]['amount'], $rows[$refund]['amount']);
        $this->assertSame('30.00', bcadd($rows[$refund]['settled'], '0', 2));
        $this->assertSame('0.00', bcadd($rows[$refund]['open'], '0', 2));
        $this->assertSame([$note, $refund], array_keys($rows));
    }

    public function testReturns_ListsAReturnTheBooksDoNotHoldWithItsAmountWorkedOutFromItsLines(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $service = $this->item('0', false);
        $invoice = $this->save([$this->line($service, 0, '4', '25')]);
        $return  = $this->save([$this->line($service, 0, '-2', '25', '0', '10')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->setConfigValue('accounting_enabled', '1');

        $row = $this->returns()['rows'][0];

        $this->assertSame($return, $row['sale_id']);
        $this->assertFalse($row['in_books']);
        $this->assertSame('45.00', bcadd($row['amount'], '0', 2));
        $this->assertNull($row['settled']);
        $this->assertNull($row['open']);
    }

    public function testReturns_LeavesOutWhatIsNotACompletedReturn(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $service = $this->item('0', false);

        $this->save([$this->line($service, 0, '-1', '10')], [], [], SALE_TYPE_RETURN, -1, true, $invoice, SUSPENDED);

        $this->assertSame(0, $this->returns()['total']);
    }

    public function testReturns_ShowsAReturnWithoutACustomerAndNoOriginalInvoiceForServices(): void
    {
        $service = $this->item('0', false);
        $return  = $this->save([$this->line($service, 0, '-1', '25')], [], [$this->payment(lang('Sales.cash'), '-25.00')], SALE_TYPE_RETURN, null);

        $row = $this->returns()['rows'][0];

        $this->assertSame($return, $row['sale_id']);
        $this->assertNull($row['customer_id']);
        $this->assertSame('', $row['customer_name']);
        $this->assertNull($row['origin_id']);
        $this->assertNull($row['settled']);
        $this->assertSame('25.00', bcadd($row['amount'], '0', 2));
    }

    public function testReturns_FiltersByDateCustomerDocumentAndSearch(): void
    {
        $other   = $this->anotherCustomer('Hind', 'Saad');
        $mine    = $this->invoiceOf('100.00');
        $hers    = $this->invoiceOf('100.00', $other);
        $note    = $this->creditNoteOf($mine, '10.00');
        $refund  = $this->refundInvoiceOf($hers, '20.00', $other);
        $this->accountingDb()->table('sales')->where('sale_id', $note)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime('-30 days'))]);
        $this->accountingDb()->table('sales')->where('sale_id', $mine)->update(['invoice_number' => 'INV-ORIGIN-5']);

        $ids = static fn (array $result): array => array_column($result['rows'], 'sale_id');

        $this->assertSame([$refund], $ids($this->returns(['customer_id' => $other])));
        $this->assertSame([$refund], $ids($this->returns(['customer' => 'saad'])));
        $this->assertSame([$note], $ids($this->returns(['customer_id' => $this->customerId])));
        $this->assertSame([$refund], $ids($this->returns(['document' => 'refund_invoice'])));
        $this->assertSame([$note], $ids($this->returns(['document' => 'credit_note'])));
        $this->assertSame([$refund, $note], $ids($this->returns(['document' => 'whatever'])));
        $this->assertSame([$refund], $ids($this->returns(['date_from' => date('Y-m-d', strtotime('-5 days'))])));
        $this->assertSame([$note], $ids($this->returns(['date_to' => date('Y-m-d', strtotime('-5 days'))])));
        $this->assertSame([$note], $ids($this->returns(['search' => 'INV-ORIGIN'])));
        $this->assertSame([$refund], $ids($this->returns(['search' => (string) $refund])));
        $this->assertSame([$note], $ids($this->returns(['search' => '#' . $mine])));
        $this->assertSame([], $ids($this->returns(['search' => '%'])));
    }

    public function testReturns_FiltersBySearchingTheNumberOfTheDocumentAndTheNameOfTheCustomer(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 4);
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 4);

        $other  = $this->anotherCustomer('Hind', 'Saad');
        $mine   = $this->invoiceOf('100.00');
        $hers   = $this->invoiceOf('100.00', $other);
        $note   = $this->creditNoteOf($mine, '10.00');
        $refund = $this->refundInvoiceOf($hers, '20.00', $other);

        $ids = static fn (array $result): array => array_column($result['rows'], 'sale_id');

        $this->assertSame([$note], $ids($this->returns(['search' => 'CN-0001'])));
        $this->assertSame([$refund], $ids($this->returns(['search' => 'RI-0001'])));
        $this->assertSame([$refund], $ids($this->returns(['search' => 'RI-'])));
        $this->assertSame([$refund], $ids($this->returns(['search' => 'saad'])));
        $this->assertSame([$refund], $ids($this->returns(['search' => 'hind'])));
        $this->assertSame([$refund], $ids($this->returns(['search' => 'Hind Saad'])));
        $this->assertSame([$note], $ids($this->returns(['search' => 'khaled'])));
        $this->assertSame([], $ids($this->returns(['search' => 'CN-0002'])));
    }

    public function testReturns_FiltersByBranchAndSortsAndPages(): void
    {
        $branch  = $this->createBranch('B2', 'Second branch');
        $service = $this->item('0', false);
        $here    = $this->invoiceOf('100.00');
        $there   = $this->invoiceInBranch($branch, '100.00');
        $first   = $this->creditNoteOf($here, '10.00');
        $second  = $this->save([$this->line($service, 0, '-1', '20', '0', '0', PERCENT, $this->branchLocation($branch))], [], [], SALE_TYPE_RETURN, -1, true, $there);

        $ids = static fn (array $result): array => array_column($result['rows'], 'sale_id');

        $this->assertSame([$second], $ids($this->returns(['branch_id' => $branch])));
        $this->assertSame([$first], $ids($this->returns(['branch_id' => 1])));
        $this->assertSame([$first, $second], $ids($this->returns([], 'number', 'asc')));
        $this->assertSame([$second, $first], $ids($this->returns([], 'number', 'desc')));
        $this->assertSame([$second], $ids($this->returns([], 'number', 'asc', 1, 1)));
        $this->assertSame(2, $this->returns([], 'number', 'asc', 1, 1)['total']);
        $this->assertSame('B2', $this->returns(['branch_id' => $branch])['rows'][0]['branch_code']);
    }

    // ---------------------------------------------------------------------------------------
    // What each customer was returned
    // ---------------------------------------------------------------------------------------

    public function testReturnTotals_CountsRefundInvoicesAsReturnsAndCreditNotesAsAdjustments(): void
    {
        $other   = $this->anotherCustomer('Hind', 'Saad');
        $mine    = $this->invoiceOf('300.00');
        $hers    = $this->invoiceOf('100.00', $other);
        $this->creditNoteOf($mine, '40.00');
        $this->creditNoteOf($mine, '10.00');
        $this->refundInvoiceOf($mine, '25.00');
        $this->refundInvoiceOf($hers, '5.00', $other);

        $totals = $this->documents->returnTotals([$this->customerId, $other]);

        $this->assertSame('25.00', bcadd($totals[$this->customerId]['returns'], '0', 2));
        $this->assertSame('50.00', bcadd($totals[$this->customerId]['adjustments'], '0', 2));
        $this->assertSame('5.00', bcadd($totals[$other]['returns'], '0', 2));
        $this->assertSame('0.00', bcadd($totals[$other]['adjustments'], '0', 2));
    }

    public function testRefundInvoiceIds_ListsTheReturnsOfACustomerIssuedAsRefundInvoicesOnly(): void
    {
        $other   = $this->anotherCustomer('Hind', 'Saad');
        $mine    = $this->invoiceOf('300.00');
        $hers    = $this->invoiceOf('100.00', $other);
        $note    = $this->creditNoteOf($mine, '40.00');
        $first   = $this->refundInvoiceOf($mine, '25.00');
        $second  = $this->refundInvoiceOf($mine, '5.00');
        $someone = $this->refundInvoiceOf($hers, '5.00', $other);

        $this->assertSame([$first, $second], $this->documents->refundInvoiceIds($this->customerId));
        $this->assertSame([$someone], $this->documents->refundInvoiceIds($other));
        $this->assertNotContains($note, $this->documents->refundInvoiceIds($this->customerId));
        $this->assertSame([], $this->documents->refundInvoiceIds(999999));
    }

    public function testReturnTotals_GivesZerosToACustomerWithNoReturnAndAnswersOnlyForTheCustomersAsked(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $mine  = $this->invoiceOf('100.00');
        $this->creditNoteOf($mine, '40.00');

        $totals = $this->documents->returnTotals([$other, 0, -3, $other]);

        $this->assertSame([$other], array_keys($totals));
        $this->assertSame(['returns' => '0.0000', 'adjustments' => '0.0000'], $totals[$other]);
        $this->assertSame([], $this->documents->returnTotals([]));
    }
}
