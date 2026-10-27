<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Detailed_sales;
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
 * The sales listed, one by one, are those of the branches the employee works in, and each shows the
 * discount given on the whole of it.
 */
class Detailed_salesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use ReportBranchFixtureTrait;
    use ReportSaleFixtureTrait;

    private const MONTH = '2003-04-';

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

    /**
     * @return list<int>
     */
    private function salesSeenBy(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $report = new Detailed_sales();
        $inputs = $this->saleInputs() + ['definition_ids' => []];

        $report->create($inputs);

        $ids = array_map('intval', array_column($report->getData($inputs)['summary'], 'sale_id'));
        sort($ids);

        return $ids;
    }

    private function totalSeenBy(string $scope): string
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $report = new Detailed_sales();

        $report->create($this->saleInputs() + ['definition_ids' => []]);

        return $this->amount($report->getSummaryData($this->saleInputs())['total']);
    }

    /**
     * @return list<int>
     */
    private function sorted(int ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, mixed>
     */
    private function inputs(): array
    {
        return ['start_date' => self::MONTH . '05', 'end_date' => self::MONTH . '05', 'sale_type' => 'complete', 'location_id' => 'all', 'definition_ids' => []];
    }

    public function testGetData_ListsOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();

        $this->assertSame($this->sorted($this->reportSales['a'], $this->reportSales['old']), $this->salesSeenBy('a'));
        $this->assertSame([$this->reportSales['b']], $this->salesSeenBy('b'));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        $this->openTheBooks();

        $everySale = $this->sorted($this->reportSales['a'], $this->reportSales['old'], $this->reportSales['b']);

        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame($everySale, $this->salesSeenBy($scope), $scope);
        }

        $this->assertSame([], $this->salesSeenBy('none'));
    }

    public function testGetSummaryData_AddsUpOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->openTheBooks();

        $this->assertSame('217.00', $this->totalSeenBy('a'));
        $this->assertSame('126.00', $this->totalSeenBy('b'));
        $this->assertSame('343.00', $this->totalSeenBy('all'));
        $this->assertSame('0.00', $this->totalSeenBy('none'));
    }

    public function testGetDataBySaleId_FindsNothingOfASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->openTheBooks();
        $this->readingAs($this->reportEmployee('a'));

        $report = new Detailed_sales();

        $report->create(['sale_id' => $this->reportSales['b']]);

        $this->assertNull($report->getDataBySaleId($this->reportSales['b'])['sale_id']);

        $this->dropEveryReportTable();
        $report->create(['sale_id' => $this->reportSales['a']]);

        $this->assertSame((string) $this->reportSales['a'], (string) $report->getDataBySaleId($this->reportSales['a'])['sale_id']);
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
