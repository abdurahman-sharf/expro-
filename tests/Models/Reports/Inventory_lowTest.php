<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Inventory_low;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FailedQueryConnectionTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportBranchFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Regression test for Issue #3634: Inventory_low::getData() must degrade to an
 * empty result (not throw "Call to member function ... on false") when the
 * query fails.
 */
class Inventory_lowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FailedQueryConnectionTrait;
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
     * @return list<string>
     */
    private function runningLowFor(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $rows = array_filter((new Inventory_low())->getData([]), fn (array $row): bool => in_array($row['name'], $this->reportNames, true));
        $seen = array_values(array_column($rows, 'name'));

        sort($seen);

        return $seen;
    }

    /**
     * Inventory_low::getData() must return an empty array, not throw, when the query fails.
     */
    public function testInventoryLowGetData_ReturnsEmptyArrayWhenQueryFails(): void
    {
        $report = $this->modelWithFailingQuery(Inventory_low::class);

        $this->assertSame([], $report->getData([]));
    }

    public function testGetData_ListsOnlyTheItemsRunningLowInTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame([$this->reportNames['a']], $this->runningLowFor('a'));
        $this->assertSame([$this->reportNames['b']], $this->runningLowFor('b'));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame([$this->reportNames['a'], $this->reportNames['b']], $this->runningLowFor($scope), $scope);
        }

        $this->assertSame([], $this->runningLowFor('none'));
    }
}
