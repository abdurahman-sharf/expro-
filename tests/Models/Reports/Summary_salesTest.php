<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_sales;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ReportSaleFixtureTrait;

/**
 * @internal
 */
final class Summary_salesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ReportSaleFixtureTrait;

    private const MONTH = '2005-06-';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace;
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

    public function testGetSummaryDataAddsUpTheSalesOfADayAfterTheirDiscountsOnTheWholeOfTheSale(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '04');
        $this->reportSale($itemId, '1', '30', null, self::MONTH . '04');

        $summary = (new Summary_sales())->getSummaryData(['start_date' => self::MONTH . '04', 'end_date' => self::MONTH . '04', 'location_id' => 'all', 'sale_type' => 'complete']);

        $this->assertSame('120.00', bcadd((string) $summary['subtotal'], '0', 2));
        $this->assertSame('120.00', bcadd((string) $summary['total'], '0', 2));
    }
}
