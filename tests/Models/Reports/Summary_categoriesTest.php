<?php

namespace Tests\Models\Reports;

use App\Models\Reports\Summary_categories;
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
 * The categories sold are those of the sales of the branches the employee works in.
 */
class Summary_categoriesTest extends CIUnitTestCase
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

        $rows = (new Summary_categories())->getData($this->saleInputs());

        return array_values(array_unique(array_map('strval', array_column($rows, 'category'))));
    }

    /**
     * @return array<string, string>
     */
    private function totalsSeenBy(?int $reader, ?int $viewer): array
    {
        $this->readingAs($reader);

        $rows   = (new Summary_categories())->getData($this->saleInputs() + ($viewer === null ? [] : ['viewer_id' => $viewer]));
        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row['category']] = $this->amount($row['total']);
        }

        return $totals;
    }

    public function testGetData_ShowsOnlyWhatTheBranchesTheEmployeeWorksInSold(): void
    {
        $this->assertSame(['ReportCatA'], $this->seenBy('a'));
        $this->assertSame(['ReportCatB'], $this->seenBy('b'));
    }

    public function testGetData_ShowsEveryBranchToAnEmployeeOfTheTwoAndOfEveryBranchAndToTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $seen = $this->seenBy($scope);

            sort($seen);

            $this->assertSame($this->sorted(['ReportCatA', 'ReportCatB']), $seen, $scope);
        }
    }

    public function testGetData_ShowsNothingToAnEmployeeWhoWorksInNoBranch(): void
    {
        $this->assertSame([], $this->seenBy('none'));
    }

    public function testGetData_ShowsOnlyTheCategoryOfTheSalesAnOwnRecordsEmployeeMade(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();

        $this->assertSame(['ReportCatA' => '30.00'], $this->totalsSeenBy($own, $own));

        $this->readingAs($own);

        $this->assertSame('30.00', $this->amount((new Summary_categories())->getSummaryData($this->saleInputs() + ['viewer_id' => $own])['total']));
    }

    public function testGetData_ShowsEverySaleToAnEmployeeWhoSeesEveryRecordAndWhenNoViewerIsNamed(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoSold();
        $everything     = ['ReportCatA' => '247.00', 'ReportCatB' => '126.00'];
        $everyRecord    = $this->employeeWithGrants([]);

        $this->assertSame($everything, $this->totalsSeenBy($everyRecord, $everyRecord));
        $this->assertSame($everything, $this->totalsSeenBy(null, null));
        $this->assertSame($everything, $this->totalsSeenBy($own, null), 'a read that names no viewer is not cut, whoever is logged in');
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
