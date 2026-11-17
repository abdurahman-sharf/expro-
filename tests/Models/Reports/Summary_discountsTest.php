<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_discounts;
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
 * The discounts given are those of the sales of the branches the employee works in.
 */
class Summary_discountsTest extends CIUnitTestCase
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
    private function seenBy(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $rows = (new Summary_discounts())->getData($this->saleInputs() + ['discount_type' => PERCENT]);

        return array_values(array_unique(array_column($rows, 'discount')));
    }

    /**
     * @return list<array{discount: string, count: int, total: string}>
     */
    private function discountsSeenBy(?int $reader, ?int $viewer): array
    {
        $this->readingAs($reader);

        $rows = (new Summary_discounts())->getData($this->saleInputs() + ['discount_type' => PERCENT] + ($viewer === null ? [] : ['viewer_id' => $viewer]));

        return array_map(fn (array $row): array => ['discount' => (string) $row['discount'], 'count' => (int) $row['count'], 'total' => $this->amount($row['total'])], $rows);
    }

    public function testGetData_ShowsOnlyTheDiscountsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame(['10.00%'], $this->seenBy('a'));
        $this->assertSame(['20.00%'], $this->seenBy('b'));
    }

    public function testGetData_ShowsEveryBranchToAnEmployeeOfTheTwoAndOfEveryBranchAndToTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $seen = $this->seenBy($scope);

            sort($seen);

            $this->assertSame(['10.00%', '20.00%'], $seen, $scope);
        }
    }

    public function testGetData_ShowsNothingToAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->assertSame([], $this->seenBy('none'));
    }

    public function testGetData_ShowsOnlyTheDiscountsOfTheSalesAnOwnRecordsEmployeeMade(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame([['discount' => '10.00%', 'count' => 1, 'total' => '3.00']], $this->discountsSeenBy($own, $own));
    }

    public function testGetData_ShowsEveryDiscountToAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();
        $everything     = [['discount' => '10.00%', 'count' => 2, 'total' => '13.00'], ['discount' => '20.00%', 'count' => 1, 'total' => '10.00']];
        $everyRecord    = $this->employeeWithGrants([]);

        $this->assertSame($everything, $this->discountsSeenBy($everyRecord, $everyRecord));
        $this->assertSame($everything, $this->discountsSeenBy(null, null));
        $this->assertSame($everything, $this->discountsSeenBy($own, null), 'a read that names no viewer is not cut, whoever is logged in');
    }
}
