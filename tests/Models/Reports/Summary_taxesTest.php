<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_taxes;
use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ReportSaleFixtureTrait;

class Summary_taxesTest extends CIUnitTestCase
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

    private const MONTH = '2005-06-';

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        $this->removeReportSales(self::MONTH);
    }

    protected function tearDown(): void
    {
        $this->removeReportSales(self::MONTH);

        parent::tearDown();
    }

    public function testGetData_GivesTheSubtotalTaxAndTotalOfASaleAfterTheDiscountOnTheWholeOfIt(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '07', [$this->reportTax($itemId, '13.50')]);

        $rows = (new Summary_taxes())->getData(['start_date' => self::MONTH . '07', 'end_date' => self::MONTH . '07']);

        $this->assertCount(1, $rows);
        $this->assertSame('90.00', bcadd((string) $rows[0]['subtotal'], '0', 2));
        $this->assertSame('13.50', bcadd((string) $rows[0]['tax'], '0', 2));
        $this->assertSame('103.50', bcadd((string) $rows[0]['total'], '0', 2));
    }

    public function testGetData_GivesASaleWithoutADiscountWhatItAlwaysGave(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', null, self::MONTH . '08', [$this->reportTax($itemId, '15.00')]);

        $rows = (new Summary_taxes())->getData(['start_date' => self::MONTH . '08', 'end_date' => self::MONTH . '08']);

        $this->assertSame('100.00', bcadd((string) $rows[0]['subtotal'], '0', 2));
        $this->assertSame('115.00', bcadd((string) $rows[0]['total'], '0', 2));
    }

    public function testGetSummaryData_AddsUpTheTaxesOfTheDay(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '09', [$this->reportTax($itemId, '13.50')]);

        $summary = (new Summary_taxes())->getSummaryData(['start_date' => self::MONTH . '09', 'end_date' => self::MONTH . '09']);

        $this->assertSame('90.00', bcadd((string) $summary['subtotal'], '0', 2));
        $this->assertSame('103.50', bcadd((string) $summary['total'], '0', 2));
    }
}
