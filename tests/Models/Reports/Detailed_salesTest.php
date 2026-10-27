<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Detailed_sales;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ReportSaleFixtureTrait;

class Detailed_salesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ReportSaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const MONTH = '2003-04-';

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->dropReportTables();
        $this->removeReportSales(self::MONTH);
    }

    protected function tearDown(): void
    {
        $this->dropReportTables();
        $this->removeReportSales(self::MONTH);

        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    private function inputs(): array
    {
        return ['start_date' => self::MONTH . '05', 'end_date' => self::MONTH . '05', 'sale_type' => 'complete', 'location_id' => 'all', 'definition_ids' => []];
    }

    public function testGetDataColumns_NamesTheDiscountOnTheWholeOfTheSaleBesideTheSubtotal(): void
    {
        $columns = array_map(static fn (array $column): string => (string) array_key_first($column), (new Detailed_sales())->getDataColumns()['summary']);

        $this->assertSame($columns[array_search('subtotal', $columns, true) - 1], 'invoice_discount');
    }

    public function testGetData_ListsTheDiscountOnTheWholeOfEachSaleAndNothingForASaleWithoutOne(): void
    {
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $discounted = $this->reportSale($itemId, '2', '50', ['type' => FIXED, 'value' => '12.5'], self::MONTH . '05');
        $plain      = $this->reportSale($itemId, '1', '30', null, self::MONTH . '05');
        $report     = new Detailed_sales();

        $report->create($this->inputs());

        $rows = array_column($report->getData($this->inputs())['summary'], null, 'sale_id');

        $this->assertSame('12.50', bcadd((string) $rows[$discounted]['invoice_discount'], '0', 2));
        $this->assertSame('87.50', bcadd((string) $rows[$discounted]['subtotal'], '0', 2));
        $this->assertSame('87.50', bcadd((string) $rows[$discounted]['total'], '0', 2));
        $this->assertSame('0.00', bcadd((string) $rows[$plain]['invoice_discount'], '0', 2));
        $this->assertSame('30.00', bcadd((string) $rows[$plain]['total'], '0', 2));
    }

    public function testGetDataBySaleId_GivesTheDiscountOnTheWholeOfTheSale(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $saleId = $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '05');
        $report = new Detailed_sales();

        $report->create(['sale_id' => $saleId]);

        $row = $report->getDataBySaleId($saleId);

        $this->assertSame('10.00', bcadd((string) $row['invoice_discount'], '0', 2));
        $this->assertSame('90.00', bcadd((string) $row['total'], '0', 2));
    }

    public function testGetSummaryData_AddsUpTheSalesOfTheDayAfterTheirDiscounts(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '05');
        $this->reportSale($itemId, '1', '30', null, self::MONTH . '05');

        $report = new Detailed_sales();

        $report->create($this->inputs());

        $this->assertSame('120.00', bcadd((string) $report->getSummaryData($this->inputs())['total'], '0', 2));
    }
}
