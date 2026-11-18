<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Specific_employee;
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
 * The sales of one employee listed are those of the branches the reader works in, whatever branch the seller sold in.
 */
class Specific_employeeTest extends CIUnitTestCase
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
    private function salesSeenBy(string $scope, int $employee): array
    {
        return $this->salesReadBy($scope === 'system' ? null : $this->reportEmployee($scope), $employee);
    }

    /**
     * @param array<string, int> $viewer
     *
     * @return list<int>
     */
    private function salesReadBy(?int $reader, int $employee, array $viewer = []): array
    {
        $this->readingAs($reader);

        $report = new Specific_employee();
        $inputs = $this->saleInputs() + ['employee_id' => $employee] + $viewer;

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
    private function totalReadBy(?int $reader, int $employee, array $viewer = []): string
    {
        $this->readingAs($reader);

        $report = new Specific_employee();
        $inputs = $this->saleInputs() + ['employee_id' => $employee] + $viewer;

        $report->create($inputs);

        return $this->amount($report->getSummaryData($inputs)['total']);
    }

    public function testGetData_ListsOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame($this->sortedIds($this->reportSales['a'], $this->reportSales['old']), $this->salesSeenBy('a', $this->employeeId));
        $this->assertSame([$this->reportSales['b']], $this->salesSeenBy('b', $this->employeeId));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame($this->sortedIds($this->reportSales['a'], $this->reportSales['old'], $this->reportSales['b']), $this->salesSeenBy($scope, $this->employeeId), $scope);
        }
    }

    public function testGetData_ListsNothingForAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->assertSame([], $this->salesSeenBy('none', $this->employeeId));
    }

    public function testGetData_ListsTheSalesAnOwnRecordsEmployeeMadeWhenTheyAskForTheirOwnReport(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame([$mine], $this->salesReadBy($own, $own, ['viewer_id' => $own]));
    }

    public function testGetData_ListsNothingOfAnotherEmployeeForAnOwnRecordsEmployee(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame([], $this->salesReadBy($own, $this->employeeId, ['viewer_id' => $own]));
    }

    public function testGetData_ListsEverySaleOfTheEmployeeForAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoSold();
        $everySaleOfTheAuthor            = $this->sortedIds($this->reportSales['a'], $this->reportSales['old'], $this->reportSales['b']);
        $everyRecordReader               = $this->employeeWithGrants([]);

        $this->assertSame($everySaleOfTheAuthor, $this->salesReadBy($everyRecordReader, $this->employeeId, ['viewer_id' => $everyRecordReader]));
        $this->assertSame([$mine], $this->salesReadBy($everyRecordReader, $own, ['viewer_id' => $everyRecordReader]));
        $this->assertSame($everySaleOfTheAuthor, $this->salesReadBy(null, $this->employeeId));
        $this->assertSame($everySaleOfTheAuthor, $this->salesReadBy($own, $this->employeeId), 'a read that names no viewer is not cut, whoever is logged in');
    }

    public function testGetSummaryData_AddsUpOnlyTheSalesAnOwnRecordsEmployeeMade(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame('30.00', $this->totalReadBy($own, $own, ['viewer_id' => $own]));
        $this->assertSame('0.00', $this->totalReadBy($own, $this->employeeId, ['viewer_id' => $own]));
    }

    public function testGetSummaryData_AddsUpEverySaleOfTheEmployeeForAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own]    = $this->ownRecordsEmployeeWhoSold();
        $everyRecordReader = $this->employeeWithGrants([]);

        $this->assertSame('343.00', $this->totalReadBy($everyRecordReader, $this->employeeId, ['viewer_id' => $everyRecordReader]));
        $this->assertSame('343.00', $this->totalReadBy(null, $this->employeeId));
        $this->assertSame('343.00', $this->totalReadBy($own, $this->employeeId), 'a read that names no viewer is not cut, whoever is logged in');
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
