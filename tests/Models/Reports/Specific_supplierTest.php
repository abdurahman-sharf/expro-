<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Specific_supplier;
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
 * The sales of the goods of one supplier listed are those of the branches the employee works in.
 */
class Specific_supplierTest extends CIUnitTestCase
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
    private function salesSeenBy(string $scope, int $supplier): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $report = new Specific_supplier();
        $inputs = $this->saleInputs() + ['supplier_id' => $supplier];

        $report->create($inputs);

        $rows = $report->getData($inputs);
        $rows = $rows['summary'] ?? $rows;
        $ids  = array_values(array_unique(array_map('intval', array_column($rows, 'sale_id'))));

        sort($ids);

        return $ids;
    }

    public function testGetData_ListsOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame($this->sortedIds($this->reportSales['a'], $this->reportSales['old']), $this->salesSeenBy('a', $this->supplierId));
        $this->assertSame([], $this->salesSeenBy('a', $this->supplierB));
        $this->assertSame([$this->reportSales['b']], $this->salesSeenBy('b', $this->supplierB));
        $this->assertSame([], $this->salesSeenBy('b', $this->supplierId));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame([$this->reportSales['b']], $this->salesSeenBy($scope, $this->supplierB), $scope);
        }
    }

    public function testGetData_ListsNothingForAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->assertSame([], $this->salesSeenBy('none', $this->supplierId));
    }

    /**
     * @return list<int>
     */
    private function sortedIds(int ...$ids): array
    {
        sort($ids);

        return $ids;
    }
}
