<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\CrossReferenceReport;
use App\Libraries\Accounting\OperationalReports\ReportDocuments;
use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\NetPositionReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The invoices that have a return with the returns under each: an invoice with none is not there, the
 * amount taken back is what the net position report shows as returns, and it ties to the ledger.
 */
class CrossReferenceReportTest extends CIUnitTestCase
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

    private int $withTwo;
    private int $withOne;
    private int $without;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $other         = $this->anotherCustomer();
        $this->withTwo = $this->invoiceOf('575', $this->customerId);
        $this->withOne = $this->invoiceOf('172.50', $other, [$this->payment(lang('Sales.cash'), '172.50')]);
        $this->without = $this->invoiceOf('50', $other);

        $this->creditNoteOf($this->withTwo, '230', $this->customerId);
        $this->creditNoteOf($this->withTwo, '115', $this->customerId);
        $this->refundInvoiceOf($this->withOne, '86.25', $other);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function table(bool $details = true, bool $byCustomer = false, ?DocumentKind $kind = null, array $states = [], string $order = 'date_desc'): ReportTable
    {
        return (new CrossReferenceReport())->build(ReportScope::resolve(null, null), $details, $byCustomer, '2020-01-01', date('Y-m-d'), null, $kind, $states, $order);
    }

    public function testBuild_ListsOnlyTheInvoicesThatHaveAReturnWithTheReturnsUnderEach(): void
    {
        $table = $this->table();

        $this->assertSame(2, $table->documents);

        $counts = array_map(static fn ($block): int => count($block->rows), $table->blocks);
        sort($counts);

        $this->assertSame([2, 3], $counts);
        $this->assertNotContains('POS ' . $this->without, array_merge(...array_map(static fn ($block): array => array_column($block->rows, 'number'), $table->blocks)));
    }

    public function testBuild_ShowsTheAmountTakenBackAndWhatIsLeft(): void
    {
        $table = $this->table(false);

        $this->assertSame(['747.5000', '431.2500', '316.2500'], [$table->total['total'], $table->total['returns'], $table->total['net']]);
    }

    public function testBuild_AgreesWithTheReturnsOfTheNetPositionForTheSameInvoices(): void
    {
        $cross = $this->table(false);
        $net   = (new NetPositionReport())->build(ReportScope::resolve(null, null), false, '2020-01-01', date('Y-m-d'), false, ['partial_return']);

        $this->assertSame(bcsub('0', $cross->total['returns'], 4), $net->total['returns']);
    }

    public function testBuild_FiltersByTheKindOfReturnAndTheStateOfPayment(): void
    {
        $this->assertSame(1, $this->table(false, false, DocumentKind::RefundInvoice)->documents);
        $this->assertSame(1, $this->table(false, false, DocumentKind::CreditNote)->documents);
        $this->assertSame(1, $this->table(false, false, null, ['paid'])->documents);
        $this->assertSame(1, $this->table(false, false, null, ['partial'])->documents);
        $this->assertSame(0, $this->table(false, false, null, ['unpaid'])->documents);
    }

    public function testBuild_OrdersByTheTotalOfTheInvoiceAndGroupsByCustomer(): void
    {
        $descending = array_column($this->table(false, false, null, [], 'total_desc')->blocks[0]->rows, 'total');
        $ascending  = array_column($this->table(false, false, null, [], 'total_asc')->blocks[0]->rows, 'total');

        $this->assertSame(['575.0000', '172.5000'], $descending);
        $this->assertSame(['172.5000', '575.0000'], $ascending);
        $this->assertCount(2, $this->table(false, true)->blocks[0]->rows);
    }

    public function testBuild_OrdersByDateCustomerAndNetAmount(): void
    {
        $third = $this->anotherCustomer('Nadia', 'Zaki');

        $this->creditNoteOf($this->invoiceOf('50', $third), '10', $third);

        $totals = fn (string $order): array => array_column(array_map(static fn ($block): array => $block->rows[0], $this->table(true, false, null, [], $order)->blocks), 'total');

        $this->assertSame(['50.0000', '172.5000', '575.0000'], $totals('date_desc'));
        $this->assertSame(['575.0000', '172.5000', '50.0000'], $totals('date_asc'));
        $this->assertSame(['172.5000', '50.0000', '575.0000'], $totals('customer'));
        $this->assertSame(['575.0000', '172.5000', '50.0000'], $totals('net_desc'));
        $this->assertSame(['50.0000', '172.5000', '575.0000'], $totals('net_asc'));
    }

    public function testBuild_TiesWhatWasTakenBackToTheLedger(): void
    {
        $table = $this->table();

        $this->assertTrue($table->ties());
        $this->assertSame('431.2500', $table->ties[0]->ledger);
    }

    public function testBuild_SaysTheyDifferWhenTheReturnsShownAreNotWhatTheLedgerHolds(): void
    {
        $refund = (int) $this->accountingDb()->table('sales')->select('sale_id')->where('return_of_sale_id', $this->withOne)->get()->getRow()->sale_id;

        $this->accountingDb()->table('sales_items')->where('sale_id', $refund)->update(['item_unit_price' => '10']);

        $table = $this->table();

        $this->assertFalse($table->ties());
        $this->assertSame($table->total['returns'], $table->ties[0]->reported);
        $this->assertSame('431.2500', $table->ties[0]->ledger);
    }

    public function testBuild_ReadsNothingForAScopeOfNoBranch(): void
    {
        $table = (new CrossReferenceReport())->build(ReportScope::resolve(null, []), true, false, '2020-01-01', date('Y-m-d'));

        $this->assertTrue($table->isEmpty());
    }

    private function untilTomorrow(): ReportTable
    {
        return (new CrossReferenceReport())->build(ReportScope::resolve(null, null), true, false, '2020-01-01', date('Y-m-d', strtotime('+1 day')));
    }

    public function testBuild_SaysTheCheckWasNotRunAndNamesTheInvoiceAndItsReturnWhenTheInvoiceIsInDollars(): void
    {
        $this->enableDollars();
        $invoice = $this->foreignInvoice('100', [$this->payment(lang('Sales.cash'), '100')]);
        $note    = $this->creditNoteOf($invoice, '40');

        $this->assertGreaterThan(0, $note);

        $table = $this->untilTomorrow();

        $this->assertCount(1, $table->ties);
        $this->assertTrue($table->ties[0]->isRefused());
        $this->assertFalse($table->ties[0]->ties());
        $this->assertSame('Accounting_operational_reports.tie_foreign_currency', $table->ties[0]->refusal);
        $this->assertSame(['POS ' . $invoice . ' (USD), POS ' . $note . ' (USD)'], $table->ties[0]->refusalArguments);
    }

    public function testBuild_AgreesWithTheLedgerForTheInvoicesInTheBaseWhileAnotherIsInDollars(): void
    {
        $this->enableDollars();
        $this->assertGreaterThan(0, $this->foreignInvoice('100'));

        $table = $this->untilTomorrow();

        $this->assertTrue($table->ties[0]->ties());
        $this->assertFalse($table->ties[0]->isRefused());
        $this->assertSame(2, $table->documents);
    }

    public function testBuild_RefusesAPeriodOfMoreInvoicesThanTheServerCanHoldByNameWithTheCount(): void
    {
        $this->invoiceOf('100', $this->customerId);
        $this->invoiceOf('50', $this->customerId);

        $table = (new CrossReferenceReport(null, new ReportDocuments(null, 400, 1)))->build(ReportScope::resolve(null, null), false, true, '2020-01-01', date('Y-m-d'));

        $this->assertTrue($table->isRefused());
        $this->assertSame('1', $table->refusalArguments[1]);
        $this->assertGreaterThanOrEqual(2, (int) $table->refusalArguments[0]);
    }
}
