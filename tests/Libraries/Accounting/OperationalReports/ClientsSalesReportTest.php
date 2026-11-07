<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\OperationalReports\ClientsSalesReport;
use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\HeaderCharges;
use App\Libraries\ShippingTaxOption;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The sales of each customer document by document: the columns follow their definition and add up to the
 * total, the type filter, the grouping, the order, the header charges, the debit notes that are read only
 * when asked for, and the check against the journals.
 */
class ClientsSalesReportTest extends CIUnitTestCase
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
        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_debit_note', 'accounting_sales_adjustment', 'accounting_sales_shipping', 'accounting_shipping_tax_percent'])->delete();
        $this->stopSelling();

        parent::tearDown();
    }

    private function table(?DocumentKind $kind = null, bool $byCustomer = false, bool $details = true, string $order = 'date_desc'): ReportTable
    {
        return (new ClientsSalesReport())->build(ReportScope::resolve(null, null), $kind, $byCustomer, $details, '2020-01-01', date('Y-m-d'), null, $order);
    }

    /**
     * @return list<array<string, string>>
     */
    private function rows(ReportTable $table): array
    {
        return array_merge([], ...array_map(static fn ($block): array => $block->rows, $table->blocks));
    }

    public function testBuild_FollowsTheDefinitionOfTheColumnsOfTheReferenceInvoice(): void
    {
        $cycle = $this->discountedReferenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $row = $this->rows($this->table())[0];

        $this->assertSame(['value' => '710.0000', 'discount' => '71.0000', 'tax' => '95.8500', 'total' => '734.8500'], array_intersect_key($row, array_flip(['value', 'discount', 'tax', 'total'])));
        $this->assertSame(bcadd(bcsub($row['value'], $row['discount'], 4), bcadd($row['tax'], bcadd($row['shipping'], $row['adjustment'], 4), 4), 4), $row['total']);
    }

    public function testBuild_AddsEveryColumnUpToTheTotalWithTheHeaderCharges(): void
    {
        $this->setConfigValue('accounting_sales_adjustment', '1');
        $this->setConfigValue('accounting_sales_shipping', '1');
        $this->setConfigValue('accounting_shipping_tax_percent', '15');

        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, null, headerCharges: new HeaderCharges('Handling', '-10', '20', ShippingTaxOption::Added, '15'));

        $table = $this->table();
        $row   = $this->rows($table)[0];

        $this->assertSame('20.0000', $row['shipping']);
        $this->assertSame('-10.0000', $row['adjustment']);
        $this->assertSame(bcadd(bcsub($row['value'], $row['discount'], 4), bcadd($row['tax'], bcadd($row['shipping'], $row['adjustment'], 4), 4), 4), $row['total']);
        $this->assertTrue($table->ties());
    }

    public function testBuild_FiltersByTheTypeOfDocument(): void
    {
        $paid = $this->invoiceOf('115', $this->customerId, [$this->payment(lang('Sales.cash'), '100')]);
        $this->invoiceOf('230', $this->anotherCustomer());
        $this->creditNoteOf($paid, '57.50', $this->customerId);

        $this->assertSame('345.0000', $this->table(DocumentKind::Invoice)->total['total']);
        $this->assertSame('-57.5000', $this->table(DocumentKind::CreditNote)->total['total']);
        $this->assertSame('0.0000', $this->table(DocumentKind::RefundInvoice)->total['total']);
        $this->assertSame('287.5000', $this->table()->total['total']);
    }

    public function testBuild_ReadsADebitNoteOnlyWhenItsTypeIsAskedFor(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');
        $this->invoiceOf('115', $this->customerId);
        (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => date('Y-m-d'), 'amount' => '80', 'tax_percent' => '15'], $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]));

        $everything = $this->table();
        $notes      = $this->table(DocumentKind::DebitNote);

        $this->assertSame('115.0000', $everything->total['total']);
        $this->assertSame(['80.0000', '12.0000', '92.0000'], [$notes->total['value'], $notes->total['tax'], $notes->total['total']]);
        $this->assertTrue($notes->ties());
    }

    public function testBuild_GroupsByCustomerWithASubtotalAndOrdersTheDocuments(): void
    {
        $other = $this->anotherCustomer('Hind', 'Saad');
        $this->invoiceOf('10', $this->customerId);
        $this->invoiceOf('20', $other);
        $this->invoiceOf('30', $this->customerId);

        $grouped = $this->table(null, true);

        $this->assertCount(2, $grouped->blocks);
        $this->assertSame(['20.0000', '40.0000'], array_map(static fn ($block): string => $block->subtotal['total'], $grouped->blocks));
        $this->assertSame(['customer', 'shipping', 'adjustment', 'value', 'discount', 'tax', 'total'], array_column($this->table(null, true, false)->columns, 'key'));
        $this->assertSame(['10.0000', '20.0000', '30.0000'], array_column($this->rows($this->table(null, false, true, 'date_asc')), 'total'));
        $this->assertSame(['30.0000', '20.0000', '10.0000'], array_column($this->rows($this->table(null, false, true, 'date_desc')), 'total'));
    }

    public function testBuild_OrdersByTheNumberOfTheDocumentAsPeopleReadIt(): void
    {
        foreach (['INV-9' => '10', 'INV-10' => '20', 'INV-2' => '30'] as $number => $total) {
            $sale = $this->invoiceOf($total, $this->customerId);

            $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['invoice_number' => $number]);
        }

        $ascending  = $this->rows($this->table(null, false, true, 'number_asc'));
        $descending = $this->rows($this->table(null, false, true, 'number_desc'));

        $this->assertSame(['INV-2', 'INV-9', 'INV-10'], array_column($ascending, 'number'));
        $this->assertSame(['INV-10', 'INV-9', 'INV-2'], array_column($descending, 'number'));
    }

    public function testBuild_TiesTheTotalTheTaxAndTheRevenueToTheJournals(): void
    {
        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId);

        $table = $this->table();

        $this->assertCount(3, $table->ties);
        $this->assertTrue($table->ties());
        $this->assertSame('106.5000', $table->ties[1]->ledger);
        $this->assertSame('710.0000', $table->ties[2]->ledger);
    }

    public function testBuild_ReadsNothingForAScopeOfNoBranch(): void
    {
        $this->invoiceOf('10', $this->customerId);

        $table = (new ClientsSalesReport())->build(ReportScope::resolve(null, []), null, false, true, '2020-01-01', date('Y-m-d'));

        $this->assertTrue($table->isEmpty());
    }
}
