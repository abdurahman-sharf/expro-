<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_payments;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ReportSaleFixtureTrait;

class Summary_paymentsTest extends CIUnitTestCase
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

    private const MONTH = '2004-05-';

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

    public function testGetData_CountsWhatASaleCameToAfterTheDiscountOnTheWholeOfItAsTheAmountOfTheTransaction(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '06');
        $this->reportSale($itemId, '1', '30', null, self::MONTH . '06');

        $rows  = (new Summary_payments())->getData(['start_date' => self::MONTH . '06', 'end_date' => self::MONTH . '06', 'location_id' => 'all', 'sale_type' => 'complete']);
        $sales = array_values(array_filter($rows, static fn (array $row): bool => $row['trans_group'] === lang('Reports.trans_sales')));

        $this->assertCount(1, $sales);
        $this->assertSame('2', (string) $sales[0]['trans_sales']);
        $this->assertSame('120.00', bcadd((string) $sales[0]['trans_amount'], '0', 2));
    }
}
