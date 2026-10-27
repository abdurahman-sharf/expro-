<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Specific_customer;
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
 * The sales of one customer listed are those of the branches the employee works in, whatever branch the customer bought in.
 */
class Specific_customerTest extends CIUnitTestCase
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
    private function salesSeenBy(string $scope, int $customer): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $report = new Specific_customer();
        $inputs = $this->saleInputs() + ['customer_id' => $customer, 'payment_type' => 'all'];

        $report->create($inputs);

        $rows = $report->getData($inputs);
        $rows = $rows['summary'] ?? $rows;
        $ids  = array_values(array_unique(array_map('intval', array_column($rows, 'sale_id'))));

        sort($ids);

        return $ids;
    }

    public function testGetData_ListsOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame($this->sortedIds($this->reportSales['a'], $this->reportSales['old']), $this->salesSeenBy('a', $this->customerId));
        $this->assertSame([], $this->salesSeenBy('a', $this->customerB));
        $this->assertSame([$this->reportSales['b']], $this->salesSeenBy('b', $this->customerB));
        $this->assertSame([], $this->salesSeenBy('b', $this->customerId));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame([$this->reportSales['b']], $this->salesSeenBy($scope, $this->customerB), $scope);
            $this->assertSame($this->sortedIds($this->reportSales['a'], $this->reportSales['old']), $this->salesSeenBy($scope, $this->customerId), $scope);
        }
    }

    public function testGetData_ListsNothingForAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->assertSame([], $this->salesSeenBy('none', $this->customerId));
        $this->assertSame([], $this->salesSeenBy('none', $this->customerB));
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
