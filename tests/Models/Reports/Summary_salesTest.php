<?php

namespace Tests\Models\Reports;

use App\Models\Appconfig;
use App\Models\Reports\Summary_sales;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
use Tests\Support\ReportSaleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The sales summary adds up the sales of the branches the employee works in, and every branch for
 * someone who works in all of them, after the discount given on the whole of each sale.
 * Summary_report, which the other summaries of sales build on, is covered by it.
 */
class Summary_salesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportBranchFixtureTrait;
    use ReportSaleFixtureTrait;

    private const MONTH = '2005-06-';

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

        $this->dropReportTables();
        $this->removeReportSales(self::MONTH);
    }

    protected function tearDown(): void
    {
        if ($this->buyingStarted) {
            $this->forgetTheReportBusiness();
            $this->stopBuying();
            $this->removeTheReportItems();
        }

        $this->dropReportTables();
        $this->removeReportSales(self::MONTH);

        parent::tearDown();
    }

    private function openTheBooks(): void
    {
        $this->startBuying();
        $this->businessInTwoBranches();
    }

    private function figures(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $report = new Summary_sales();
        $rows   = $report->getData($this->saleInputs());

        return [
            'sales' => array_sum(array_column($rows, 'sales')),
            'total' => $this->amount($report->getSummaryData($this->saleInputs())['total']),
            'daily' => $this->amount(array_sum(array_column($rows, 'total'))),
        ];
    }

    public function testGetData_AddsUpOnlyTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();

        $this->assertSame(['sales' => 2, 'total' => '217.00', 'daily' => '217.00'], $this->figures('a'));
        $this->assertSame(['sales' => 1, 'total' => '126.00', 'daily' => '126.00'], $this->figures('b'));
        $this->assertSame(['sales' => 3, 'total' => '343.00', 'daily' => '343.00'], $this->figures('both'));
    }

    public function testGetData_KeepsEveryBranchForAnEmployeeOfEveryBranchAndForTheSystem(): void
    {
        $this->openTheBooks();

        $this->assertSame(['sales' => 3, 'total' => '343.00', 'daily' => '343.00'], $this->figures('all'));
        $this->assertSame(['sales' => 3, 'total' => '343.00', 'daily' => '343.00'], $this->figures('system'));
    }

    public function testGetData_ShowsNothingToAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->openTheBooks();

        $this->assertSame(['sales' => 0, 'total' => '0.00', 'daily' => '0.00'], $this->figures('none'));
    }

    public function testGetData_KeepsTheSaleSavedBeforeBranchesExistedInTheDefaultBranch(): void
    {
        $this->openTheBooks();

        $this->assertSame('10.00', bcsub($this->figures('a')['total'], '207.00', 2), 'The first branch holds the old sale');
        $this->assertSame('126.00', $this->figures('b')['total'], 'The second does not');
    }

    public function testGetSummaryData_AddsUpTheSalesOfADayAfterTheirDiscountsOnTheWholeOfTheSale(): void
    {
        $itemId = $this->createTestItem(HAS_NO_STOCK);

        $this->reportSale($itemId, '2', '50', ['type' => PERCENT, 'value' => '10'], self::MONTH . '04');
        $this->reportSale($itemId, '1', '30', null, self::MONTH . '04');

        $summary = (new Summary_sales())->getSummaryData(['start_date' => self::MONTH . '04', 'end_date' => self::MONTH . '04', 'location_id' => 'all', 'sale_type' => 'complete']);

        $this->assertSame('120.00', bcadd((string) $summary['subtotal'], '0', 2));
        $this->assertSame('120.00', bcadd((string) $summary['total'], '0', 2));
    }

    public function testGetSummaryData_TakesTheTaxInsideThePriceOutOfTheSubtotalAndAddsOnlyTheTaxAddedToItToTheTotalWhateverTheDefaultModeIs(): void
    {
        foreach (['0', '1'] as $default) {
            model(Appconfig::class)->save(['tax_included' => $default]);
            $this->removeReportSales(self::MONTH);
            $this->dropReportTables();

            $itemId = $this->createTestItem(HAS_NO_STOCK);

            $this->reportSale($itemId, '1', '100', null, self::MONTH . '05', [$this->reportTax($itemId, '13.0435', 0, 'Inside'), $this->reportTax($itemId, '4.35', 1, 'Added', '5.0000')]);

            $summary = (new Summary_sales())->getSummaryData(['start_date' => self::MONTH . '05', 'end_date' => self::MONTH . '05', 'location_id' => 'all', 'sale_type' => 'complete']);

            $this->assertSame(['86.96', '104.35', '17.39'], [bcadd((string) $summary['subtotal'], '0', 2), bcadd((string) $summary['total'], '0', 2), bcadd((string) $summary['tax'], '0', 2)], 'default ' . $default);
        }

        model(Appconfig::class)->save(['tax_included' => '0']);
    }
}
