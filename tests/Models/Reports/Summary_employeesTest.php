<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_employees;
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
 * What each employee sold counts only the sales of the branches the reader works in.
 */
class Summary_employeesTest extends CIUnitTestCase
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

    private function soldTo(string $scope): string
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        return $this->amount(array_sum(array_column((new Summary_employees())->getData($this->saleInputs()), 'total')));
    }

    /**
     * @return list<array{sales: int, total: string}>
     */
    private function soldByEmployeesSeenBy(?int $reader, ?int $viewer): array
    {
        $this->readingAs($reader);

        $rows = (new Summary_employees())->getData($this->saleInputs() + ($viewer === null ? [] : ['viewer_id' => $viewer]));
        $sold = array_map(fn (array $row): array => ['sales' => (int) $row['sales'], 'total' => $this->amount($row['total'])], $rows);

        usort($sold, static fn (array $first, array $second): int => (float) $first['total'] <=> (float) $second['total']);

        return $sold;
    }

    public function testGetData_AddsUpOnlyTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame('217.00', $this->soldTo('a'));
        $this->assertSame('126.00', $this->soldTo('b'));
    }

    public function testGetData_AddsUpEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame('343.00', $this->soldTo($scope), $scope);
        }
    }

    public function testGetData_ShowsNothingToAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->assertSame('0.00', $this->soldTo('none'));
    }

    public function testGetData_ListsOnlyTheOwnRecordsEmployeeWhoSoldAndNobodyElse(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame([['sales' => 1, 'total' => '30.00']], $this->soldByEmployeesSeenBy($own, $own));

        $this->readingAs($own);

        $this->assertSame('30.00', $this->amount((new Summary_employees())->getSummaryData($this->saleInputs() + ['viewer_id' => $own])['total']));
    }

    public function testGetData_ListsEveryEmployeeToAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();
        $everything     = [['sales' => 1, 'total' => '30.00'], ['sales' => 3, 'total' => '343.00']];
        $everyRecord    = $this->employeeWithGrants([]);

        $this->assertSame($everything, $this->soldByEmployeesSeenBy($everyRecord, $everyRecord));
        $this->assertSame($everything, $this->soldByEmployeesSeenBy(null, null));
        $this->assertSame($everything, $this->soldByEmployeesSeenBy($own, null), 'a read that names no viewer is not cut, whoever is logged in');
    }
}
