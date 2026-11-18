<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_items;
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
 * The items sold are those of the sales of the branches the employee works in.
 */
class Summary_itemsTest extends CIUnitTestCase
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

        $rows = (new Summary_items())->getData($this->saleInputs());

        return array_values(array_unique(array_map('strval', array_column($rows, 'name'))));
    }

    /**
     * @return array<string, array{quantity: int, total: string}>
     */
    private function soldItemsSeenBy(?int $reader, ?int $viewer): array
    {
        $this->readingAs($reader);

        $rows = (new Summary_items())->getData($this->saleInputs() + ($viewer === null ? [] : ['viewer_id' => $viewer]));
        $sold = [];

        foreach ($rows as $row) {
            $sold[(string) $row['name']] = ['quantity' => (int) $row['quantity_purchased'], 'total' => $this->amount($row['total'])];
        }

        ksort($sold);

        return $sold;
    }

    public function testGetData_ShowsOnlyWhatTheBranchesTheEmployeeWorksInSold(): void
    {
        $this->assertSame([$this->reportNames['a']], $this->seenBy('a'));
        $this->assertSame([$this->reportNames['b']], $this->seenBy('b'));
    }

    public function testGetData_ShowsEveryBranchToAnEmployeeOfTheTwoAndOfEveryBranchAndToTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $seen = $this->seenBy($scope);

            sort($seen);

            $this->assertSame($this->sorted([$this->reportNames['a'], $this->reportNames['b']]), $seen, $scope);
        }
    }

    public function testGetData_ShowsNothingToAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->assertSame([], $this->seenBy('none'));
    }

    public function testGetData_ShowsOnlyTheUnitsAnOwnRecordsEmployeeSold(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame([$this->reportNames['a'] => ['quantity' => 1, 'total' => '30.00']], $this->soldItemsSeenBy($own, $own));

        $this->readingAs($own);

        $this->assertSame('30.00', $this->amount((new Summary_items())->getSummaryData($this->saleInputs() + ['viewer_id' => $own])['total']));
    }

    public function testGetData_ShowsEverySaleToAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();
        $everything     = [
            $this->reportNames['a'] => ['quantity' => 4, 'total' => '247.00'],
            $this->reportNames['b'] => ['quantity' => 3, 'total' => '126.00'],
        ];
        $everyRecord = $this->employeeWithGrants([]);

        $this->assertSame($everything, $this->soldItemsSeenBy($everyRecord, $everyRecord));
        $this->assertSame($everything, $this->soldItemsSeenBy(null, null));
        $this->assertSame($everything, $this->soldItemsSeenBy($own, null), 'a read that names no viewer is not cut, whoever is logged in');
    }

    /**
     * @param list<string> $values
     *
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
