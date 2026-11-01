<?php

namespace Tests\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ItemSalesReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
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
 * What was sold, grouped by item, category, customer, employee or period: returns as negative lines, the
 * weighted price of a group, the filters, and the check against the journals within a cent a document.
 */
class ItemSalesReportTest extends CIUnitTestCase
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

    private int $cream;
    private int $oil;
    private int $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();

        $this->cream   = $this->item('40');
        $this->oil     = $this->item('20');
        $this->service = $this->item('0', false);
        $this->accountingDb()->table('items')->where('item_id', $this->cream)->update(['name' => 'Cream', 'category' => 'Creams']);
        $this->accountingDb()->table('items')->where('item_id', $this->oil)->update(['name' => 'Oil', 'category' => 'Oils']);
        $this->accountingDb()->table('items')->where('item_id', $this->service)->update(['name' => 'Delivery', 'category' => '']);
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function table(ItemGroup $group = ItemGroup::Item, bool $details = false, array $arguments = []): ReportTable
    {
        return (new ItemSalesReport())->build(
            ReportScope::resolve(null, null),
            $group,
            $details,
            $arguments['from'] ?? '2020-01-01',
            $arguments['to'] ?? date('Y-m-d'),
            date('Y-m-d'),
            $arguments['kind'] ?? null,
            [],
            $arguments['customer'] ?? null,
            $arguments['user'] ?? null,
            $arguments['item'] ?? null,
            $arguments['category'] ?? '',
            $arguments['location'] ?? null,
        );
    }

    /**
     * @return array<string, string>
     */
    private function rowNamed(ReportTable $table, string $name): array
    {
        foreach ($table->blocks[0]->rows as $row) {
            if (in_array($name, $row, true)) {
                return $row;
            }
        }

        $this->fail("No row for {$name}.");
    }

    public function testBuild_ShowsAReturnAsANegativeLineThatTakesFromTheItem(): void
    {
        $sale = $this->save([$this->line($this->cream, 0, '4', '75', '40', '10')], [$this->tax($this->cream, 0, '40.50')], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->cream, 0, '-1', '75', '40', '10')], [$this->tax($this->cream, 0, '-10.125')], [], SALE_TYPE_RETURN, $this->customerId, true, $sale);

        $details = $this->table(ItemGroup::Item, true);
        $rows    = $details->blocks[0]->rows;

        $this->assertCount(2, $rows);
        $this->assertSame(['4.0000', '-1.0000'], array_column($rows, 'quantity'));
        $this->assertSame(['30.0000', '-7.5000'], array_column($rows, 'discount'));
        $this->assertSame('3.0000', $this->rowNamed($this->table(), 'Cream')['quantity']);
    }

    public function testBuild_AveragesThePriceByTheNetUnits(): void
    {
        $sale = $this->save([$this->line($this->cream, 0, '4', '75', '40'), $this->line($this->cream, 1, '2', '80', '40')], [], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->cream, 0, '-1', '75', '40')], [], [], SALE_TYPE_RETURN, $this->customerId, true, $sale);

        $row = $this->rowNamed($this->table(), 'Cream');

        $this->assertSame('77.0000', $row['unit_price']);
        $this->assertSame('5.0000', $row['quantity']);
    }

    public function testBuild_FiltersByTheKindOfDocument(): void
    {
        $sale = $this->save([$this->line($this->cream, 0, '2', '50', '40')], [], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->cream, 0, '-2', '50', '40')], [], [], SALE_TYPE_RETURN, $this->customerId, true, $sale);

        $this->assertSame('100.0000', $this->table(ItemGroup::Item, false, ['kind' => DocumentKind::Invoice])->total['total']);
        $this->assertSame('-100.0000', $this->table(ItemGroup::Item, false, ['kind' => DocumentKind::CreditNote])->total['total']);
        $this->assertSame('0.0000', $this->table(ItemGroup::Item, false, ['kind' => DocumentKind::RefundInvoice])->total['total']);
        $this->assertSame('0.0000', $this->table()->total['total']);
    }

    public function testBuild_LocationFilterExcludesServiceLines(): void
    {
        $this->save([$this->line($this->cream, 0, '1', '50', '40'), $this->line($this->service, 1, '1', '30', '0')], [], [], SALE_TYPE_INVOICE, $this->customerId);

        $everything = $this->table();
        $located    = $this->table(ItemGroup::Item, false, ['location' => self::LOCATION_ID]);

        $this->assertSame('80.0000', $everything->total['total']);
        $this->assertSame('50.0000', $located->total['total']);
        $this->assertSame([], $located->ties);
    }

    public function testBuild_NamesAnItemWithoutACategory(): void
    {
        $this->save([$this->line($this->service, 0, '1', '30', '0')], [], [], SALE_TYPE_INVOICE, $this->customerId);

        $rows = $this->table(ItemGroup::Category)->blocks[0]->rows;

        $this->assertSame([lang('Accounting_operational_reports.undefined')], array_column($rows, 'category'));
    }

    public function testBuild_GroupsByCustomerAndByUserAndLimitsToOneItem(): void
    {
        $other = $this->anotherCustomer();
        $this->save([$this->line($this->cream, 0, '1', '50', '40')], [], [], SALE_TYPE_INVOICE, $this->customerId);
        $this->save([$this->line($this->oil, 0, '2', '25', '20')], [], [], SALE_TYPE_INVOICE, $other);

        $this->assertCount(2, $this->table(ItemGroup::Customer)->blocks[0]->rows);
        $this->assertSame('50.0000', $this->table(ItemGroup::Item, false, ['customer' => $this->customerId])->total['total']);
        $this->assertSame('100.0000', $this->table(ItemGroup::User)->total['total']);
        $this->assertSame('50.0000', $this->table(ItemGroup::Item, false, ['item' => $that = $this->oil])->total['total']);
        $this->assertSame('50.0000', $this->table(ItemGroup::Category, false, ['category' => 'Oils'])->total['total']);
        $this->assertGreaterThan(0, $that);
    }

    public function testBuild_TiesTheLinesToTheJournalsWithoutTheHeaderChargesWithinACent(): void
    {
        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId);

        $table = $this->table();

        $this->assertSame('816.5000', $table->total['total']);
        $this->assertTrue($table->ties());
        $this->assertSame('816.5000', $table->ties[0]->ledger);
        $this->assertSame('0.0100', $table->ties[0]->tolerance);
    }

    public function testBuild_DropsTheDateColumnWhenGroupedByPeriod(): void
    {
        $this->save([$this->line($this->cream, 0, '1', '50', '40')], [], [], SALE_TYPE_INVOICE, $this->customerId);

        $byItem = array_column($this->table(ItemGroup::Item, true)->columns, 'key');
        $byDay  = array_column($this->table(ItemGroup::Day, true)->columns, 'key');
        $sum    = array_column($this->table(ItemGroup::Item, false)->columns, 'key');

        $this->assertContains('date', $byItem);
        $this->assertNotContains('date', $byDay);
        $this->assertSame(['item', 'unit_price', 'quantity', 'discount', 'tax', 'total'], $sum);
    }
    public function testBuild_SplitsTheTaxThatIsInsideThePriceFromTheValue(): void
    {
        $this->save([$this->line($this->cream, 0, '1', '115', '40')], [$this->taxRow($this->cream, 0, 'VAT', '15', 0, '15')], [], SALE_TYPE_INVOICE, $this->customerId);

        $row = $this->rowNamed($this->table(), 'Cream');

        $this->assertSame(['15.0000', '115.0000'], [$row['tax'], $row['total']]);
        $this->assertTrue($this->table()->ties());
    }

    public function testBuild_CountsTheShareOfTheDiscountOnTheWholeInvoiceInTheDiscountOfTheLine(): void
    {
        $cycle = $this->discountedReferenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $table = $this->table();

        $this->assertSame('101.0000', $table->total['discount']);
        $this->assertSame('734.8500', $table->total['total']);
        $this->assertTrue($table->ties());
    }

    public function testBuild_TiesTheLinesToTheJournalsWithoutTheHeaderCharges(): void
    {
        $this->setConfigValue('accounting_sales_adjustment', '1');
        $this->setConfigValue('accounting_sales_shipping', '1');
        $this->setConfigValue('accounting_shipping_tax_percent', '15');

        $cycle = $this->referenceInvoice();
        $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, $this->customerId, true, null, COMPLETED, NEW_ENTRY, null, headerCharges: new HeaderCharges('Handling', '-10', '20', ShippingTaxOption::Added, '15'));

        $table = $this->table();

        $this->assertSame('816.5000', $table->total['total']);
        $this->assertTrue($table->ties());
        $this->assertSame('839.5000', bcadd($table->ties[0]->ledger, '23', 4));

        $this->accountingDb()->table('app_config')->whereIn('key', ['accounting_sales_adjustment', 'accounting_sales_shipping', 'accounting_shipping_tax_percent'])->delete();
    }
}
