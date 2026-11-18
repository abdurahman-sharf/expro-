<?php

namespace Tests\Models\Reports;

use App\Libraries\Roles\RoleService;
use App\Models\Reports\Detailed_receivings;
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
 * The receivings listed are those of the branches the employee works in, and a transfer between branches is seen from both.
 */
class Detailed_receivingsTest extends CIUnitTestCase
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
    private function receivingsSeenBy(string $scope): array
    {
        $this->readingAs($scope === 'system' ? null : $this->reportEmployee($scope));

        $inputs = $this->receivingInputs();
        $report = new Detailed_receivings();

        $report->create($inputs);

        $ids = array_map('intval', array_column($report->getData($inputs)['summary'], 'receiving_id'));
        sort($ids);

        return $ids;
    }

    /**
     * An employee who sees their own records only, in the branches given or in every one, who bought
     * a unit for 25.00 on the day of the report.
     *
     * @param list<int>|null $branchIds
     *
     * @return array{own: int, mine: int}
     */
    private function ownRecordsEmployeeWhoBought(?array $branchIds = null): array
    {
        $own = $this->employeeWithGrants([], $branchIds);
        (new RoleService())->setRecordScope($own, 'own', null);

        $author           = $this->employeeId;
        $this->employeeId = $own;
        $mine             = $this->buy([$this->purchaseLine($this->reportItems['a'], 0, '1', '25.00')]);
        $this->employeeId = $author;

        $this->assertGreaterThan(0, $mine);
        $this->accountingDb()->table('receivings')->where('receiving_id', $mine)->update(['receiving_time' => $this->reportDay . ' 11:00:00']);

        return ['own' => $own, 'mine' => $mine];
    }

    /**
     * @return list<int>
     */
    private function receivingsSeenById(?int $employeeId): array
    {
        $this->readingAs($employeeId);

        $inputs = $this->receivingInputs();
        $report = new Detailed_receivings();

        $report->create($inputs);

        $ids = array_map('intval', array_column($report->getData($inputs)['summary'], 'receiving_id'));
        sort($ids);

        return $ids;
    }

    private function boughtSeenBy(string $scope, ?int $employeeId = null): string
    {
        $this->readingAs($employeeId ?? ($scope === 'system' ? null : $this->reportEmployee($scope)));

        $report = new Detailed_receivings();

        $report->create($this->receivingInputs());

        return $this->amount($report->getSummaryData($this->receivingInputs())['total']);
    }

    /**
     * @return array<string, mixed>
     */
    private function receivingInputs(): array
    {
        return ['start_date' => $this->reportDay, 'end_date' => $this->reportDay, 'receiving_type' => 'all', 'location_id' => 'all', 'definition_ids' => []];
    }

    /**
     * @return list<int>
     */
    private function sorted(int ...$ids): array
    {
        sort($ids);

        return $ids;
    }

    private function transferOfTwoUnitsFromTheFirstBranchToTheSecond(): int
    {
        $this->workInEveryBranch();

        $transfer = $this->buy([
            $this->purchaseLine($this->reportItems['a'], 0, '2', '0', '0', '0', PERCENT, $this->storeB),
            $this->purchaseLine($this->reportItems['a'], 1, '-2', '0'),
        ], null, null);

        $this->assertGreaterThan(0, $transfer);
        $this->accountingDb()->table('receivings')->where('receiving_id', $transfer)->update(['receiving_time' => $this->reportDay . ' 09:30:00']);

        return $transfer;
    }

    public function testGetData_ListsOnlyTheReceivingsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame([$this->reportReceivings['a']], $this->receivingsSeenBy('a'));
        $this->assertSame([$this->reportReceivings['b']], $this->receivingsSeenBy('b'));
    }

    public function testGetData_ListsEveryBranchForAnEmployeeOfTheTwoAndOfEveryBranchAndForTheSystem(): void
    {
        foreach (['both', 'all', 'system'] as $scope) {
            $this->assertSame($this->sorted($this->reportReceivings['a'], $this->reportReceivings['b']), $this->receivingsSeenBy($scope), $scope);
        }

        $this->assertSame([], $this->receivingsSeenBy('none'));
    }

    public function testGetSummaryData_AddsUpOnlyTheReceivingsOfTheBranchesTheEmployeeWorksIn(): void
    {
        $this->assertSame('100.00', $this->boughtSeenBy('a'));
        $this->assertSame('210.00', $this->boughtSeenBy('b'));
        $this->assertSame('310.00', $this->boughtSeenBy('all'));
        $this->assertSame('0.00', $this->boughtSeenBy('none'));
    }

    public function testGetData_ListsOnlyTheReceivingsAnOwnRecordsEmployeeMade(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoBought();

        $this->assertSame([$mine], $this->receivingsSeenById($own));
    }

    public function testGetData_ListsEveryReceivingToAnEmployeeWhoSeesEveryRecordAndToTheSystem(): void
    {
        ['mine' => $mine] = $this->ownRecordsEmployeeWhoBought();
        $everything       = $this->sorted($this->reportReceivings['a'], $this->reportReceivings['b'], $mine);

        $this->assertSame($everything, $this->receivingsSeenById($this->employeeWithGrants([])));
        $this->assertSame($everything, $this->receivingsSeenById(null));
    }

    public function testGetData_HidesAReceivingThatNamesNoEmployeeFromAnOwnRecordsEmployee(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoBought();
        $db                              = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('receivings')->where('receiving_id', $this->reportReceivings['a'])->update(['employee_id' => 0]);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->assertSame([$mine], $this->receivingsSeenById($own));
        $this->assertSame('25.00', $this->boughtSeenBy('own', $own));
        $this->assertSame('335.00', $this->boughtSeenBy('all'));
    }

    public function testGetData_KeepsTheLimitToTheBranchesBesideTheLimitToOwnRecords(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoBought([1]);

        $this->assertSame([$mine], $this->receivingsSeenById($own));

        $this->accountingDb()->table('receivings')->where('receiving_id', $mine)->update(['branch_id' => $this->branchB]);

        $this->assertSame([], $this->receivingsSeenById($own));
    }

    public function testGetSummaryData_AddsUpOnlyTheReceivingsAnOwnRecordsEmployeeMade(): void
    {
        ['own' => $own] = $this->ownRecordsEmployeeWhoBought();

        $this->assertSame('25.00', $this->boughtSeenBy('own', $own));
        $this->assertSame('335.00', $this->boughtSeenBy('all'));
    }

    public function testGetDataByReceivingId_FindsNothingOfAReceivingAnotherEmployeeMadeAndNothingOfOneThatDoesNotExist(): void
    {
        ['own' => $own, 'mine' => $mine] = $this->ownRecordsEmployeeWhoBought();

        $this->readingAs($own);

        $report = new Detailed_receivings();

        foreach ([$this->reportReceivings['a'], 987654] as $theirs) {
            $report->create(['receiving_id' => $theirs]);

            $this->assertNull($report->getDataByReceivingId((string) $theirs));

            $this->dropEveryReportTable();
        }

        $report->create(['receiving_id' => $mine]);

        $this->assertSame((string) $mine, (string) $report->getDataByReceivingId((string) $mine)['receiving_id']);
    }

    public function testGetData_ListsATransferBetweenBranchesForTheEmployeesOfEitherBranchAndNoOneElse(): void
    {
        $transfer = $this->transferOfTwoUnitsFromTheFirstBranchToTheSecond();
        $third    = $this->createBranch('R' . substr(md5(uniqid('', true)), 0, 8), 'Report branch C');

        $this->assertContains($transfer, $this->receivingsSeenBy('a'));
        $this->assertContains($transfer, $this->receivingsSeenBy('b'));

        $this->readingAs($this->employeeWithGrants([], [$third]));

        $report = new Detailed_receivings();

        $report->create($this->receivingInputs());

        $this->assertSame([], $report->getData($this->receivingInputs())['summary']);
    }

    public function testGetDataByReceivingId_FindsNothingOfAReceivingOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->readingAs($this->reportEmployee('a'));

        $report = new Detailed_receivings();

        $report->create(['receiving_id' => $this->reportReceivings['b']]);

        $this->assertNull($report->getDataByReceivingId((string) $this->reportReceivings['b']));

        $this->dropEveryReportTable();
        $report->create(['receiving_id' => $this->reportReceivings['a']]);

        $this->assertSame((string) $this->reportReceivings['a'], (string) $report->getDataByReceivingId((string) $this->reportReceivings['a'])['receiving_id']);
    }
}
