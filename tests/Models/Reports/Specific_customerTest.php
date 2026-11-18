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
        return $this->salesReadBy($scope === 'system' ? null : $this->reportEmployee($scope), $customer);
    }

    /**
     * @param array<string, int> $viewer
     *
     * @return list<int>
     */
    private function salesReadBy(?int $reader, int $customer, array $viewer = []): array
    {
        $this->readingAs($reader);

        $report = new Specific_customer();
        $inputs = $this->saleInputs() + ['customer_id' => $customer, 'payment_type' => 'all'] + $viewer;

        $report->create($inputs);

        $rows = $report->getData($inputs);
        $rows = $rows['summary'] ?? $rows;
        $ids  = array_values(array_unique(array_map('intval', array_column($rows, 'sale_id'))));

        sort($ids);

        return $ids;
    }

    /**
     * @param array<string, int> $viewer
     */
    private function totalReadBy(?int $reader, int $customer, array $viewer = []): string
    {
        $this->readingAs($reader);

        $report = new Specific_customer();
        $inputs = $this->saleInputs() + ['customer_id' => $customer, 'payment_type' => 'all'] + $viewer;

        $report->create($inputs);

        return $this->amount($report->getSummaryData($inputs)['total']);
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

    public function testGetData_ListsOnlyTheSalesAnOwnRecordsEmployeeMade(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame([$mine], $this->salesReadBy($own, $this->customerId, ['viewer_id' => $own]));
    }

    public function testGetData_ListsEverySaleOfTheCustomerForAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoSold();
        $everySale                       = $this->sortedIds($this->reportSales['a'], $this->reportSales['old'], $mine);
        $everyRecordReader               = $this->employeeWithGrants([]);

        $this->assertSame($everySale, $this->salesReadBy($everyRecordReader, $this->customerId, ['viewer_id' => $everyRecordReader]));
        $this->assertSame($everySale, $this->salesReadBy(null, $this->customerId));
        $this->assertSame($everySale, $this->salesReadBy($own, $this->customerId), 'a read that names no viewer is not cut, whoever is logged in');
    }

    public function testGetSummaryData_AddsUpOnlyTheSalesAnOwnRecordsEmployeeMade(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame('30.00', $this->totalReadBy($own, $this->customerId, ['viewer_id' => $own]));
    }

    public function testGetSummaryData_AddsUpEverySaleOfTheCustomerForAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own]    = $this->ownRecordsEmployeeWhoSold();
        $everyRecordReader = $this->employeeWithGrants([]);

        $this->assertSame('247.00', $this->totalReadBy($everyRecordReader, $this->customerId, ['viewer_id' => $everyRecordReader]));
        $this->assertSame('247.00', $this->totalReadBy(null, $this->customerId));
        $this->assertSame('247.00', $this->totalReadBy($own, $this->customerId), 'a read that names no viewer is not cut, whoever is logged in');
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
