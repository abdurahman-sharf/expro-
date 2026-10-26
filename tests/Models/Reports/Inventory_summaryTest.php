<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Inventory_summary;
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
 * The stock listed is that of the locations of the branches the employee works in.
 */
class Inventory_summaryTest extends CIUnitTestCase
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
     * @return array<string, string> What is on the shelves of each of the two items, by the name of the item.
     */
    private function onTheShelvesFor(string $scope, array $inputs = ['location_id' => 'all', 'item_count' => 'all']): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $rows  = (new Inventory_summary())->getData($inputs);
        $ours  = array_filter($rows, fn (array $row): bool => in_array($row['name'], $this->reportNames, true));
        $named = [];

        foreach ($ours as $row) {
            $named[$row['name']] = bcadd($named[$row['name']] ?? '0', (string) $row['quantity'], 3);
        }

        ksort($named);

        return $named;
    }

    public function testGetData_ListsOnlyTheStockOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame([$this->reportNames['a'] => '3.000'], $this->onTheShelvesFor('a'));
        $this->assertSame([$this->reportNames['b'] => '4.000'], $this->onTheShelvesFor('b'));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame([$this->reportNames['a'] => '3.000', $this->reportNames['b'] => '4.000'], $this->onTheShelvesFor($scope), $scope);
        }

        $this->assertSame([], $this->onTheShelvesFor('none'));
    }

    public function testGetData_ListsNothingOfALocationOfAnotherBranchWhenItIsAskedFor(): void
    {
        $this->assertSame([], $this->onTheShelvesFor('a', ['location_id' => (string) $this->storeB, 'item_count' => 'all']));
        $this->assertSame([$this->reportNames['b'] => '4.000'], $this->onTheShelvesFor('b', ['location_id' => (string) $this->storeB, 'item_count' => 'all']));
    }

    public function testGetSummaryData_AddsUpOnlyTheStockTheEmployeeMayRead(): void
    {
        $this->readingAs($this->reportEmployee('a'));

        $report  = new Inventory_summary();
        $rows    = array_filter($report->getData(['location_id' => 'all', 'item_count' => 'all']), fn (array $row): bool => in_array($row['name'], $this->reportNames, true));
        $summary = $report->getSummaryData(array_values($rows));

        $this->assertSame('3.000', bcadd((string) $summary['total_quantity'], '0', 3));
    }
}
