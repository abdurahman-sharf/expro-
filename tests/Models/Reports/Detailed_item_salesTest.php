<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Detailed_item_sales;
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
 * The lines of the sales listed are those of the branches the employee works in.
 */
class Detailed_item_salesTest extends CIUnitTestCase
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
     * @return list<string>
     */
    private function itemsSeenBy(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $report = new Detailed_item_sales();

        $report->create($this->saleInputs());

        $names = array_values(array_unique(array_column($report->getData($this->saleInputs()), 'item_name')));
        sort($names);

        return $names;
    }

    private function totalSeenBy(string $scope): string
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $report = new Detailed_item_sales();

        $report->create($this->saleInputs());

        return $this->amount($report->getSummaryData($this->saleInputs())['total']);
    }

    public function testGetData_ListsOnlyTheLinesOfTheSalesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame([$this->reportNames['a']], $this->itemsSeenBy('a'));
        $this->assertSame([$this->reportNames['b']], $this->itemsSeenBy('b'));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame([$this->reportNames['a'], $this->reportNames['b']], $this->itemsSeenBy($scope), $scope);
        }

        $this->assertSame([], $this->itemsSeenBy('none'));
    }

    public function testGetSummaryData_AddsUpOnlyTheLinesOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame('217.00', $this->totalSeenBy('a'));
        $this->assertSame('126.00', $this->totalSeenBy('b'));
        $this->assertSame('343.00', $this->totalSeenBy('all'));
    }
}
