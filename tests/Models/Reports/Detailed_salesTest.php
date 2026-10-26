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
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The sales listed, one by one, are those of the branches the employee works in.
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

        $this->startBuying();
        $this->businessInTwoBranches();
    }

    protected function tearDown(): void
    {
        $this->forgetTheReportBusiness();
        $this->stopBuying();
        $this->removeTheReportItems();

        parent::tearDown();
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

    public function testGetData_ListsOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame($this->sorted($this->reportSales['a'], $this->reportSales['old']), $this->salesSeenBy('a'));
        $this->assertSame([$this->reportSales['b']], $this->salesSeenBy('b'));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        $everySale = $this->sorted($this->reportSales['a'], $this->reportSales['old'], $this->reportSales['b']);

        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame($everySale, $this->salesSeenBy($scope), $scope);
        }

        $this->assertSame([], $this->salesSeenBy('none'));
    }

    public function testGetSummaryData_AddsUpOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame('217.00', $this->totalSeenBy('a'));
        $this->assertSame('126.00', $this->totalSeenBy('b'));
        $this->assertSame('343.00', $this->totalSeenBy('all'));
        $this->assertSame('0.00', $this->totalSeenBy('none'));
    }

    public function testGetDataBySaleId_FindsNothingOfASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->readingAs($this->reportEmployee('a'));

        $report = new Detailed_sales();

        $report->create(['sale_id' => $this->reportSales['b']]);

        $this->assertNull($report->getDataBySaleId($this->reportSales['b'])['sale_id']);

        $this->dropEveryReportTable();
        $report->create(['sale_id' => $this->reportSales['a']]);

        $this->assertSame((string) $this->reportSales['a'], (string) $report->getDataBySaleId($this->reportSales['a'])['sale_id']);
    }
}
