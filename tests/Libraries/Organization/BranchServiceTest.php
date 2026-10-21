<?php

namespace Tests\Libraries\Organization;

use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\BranchService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class BranchServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BranchService $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->service = new BranchService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    private function assertFailsWith(BranchError $expected, callable $action): void
    {
        try {
            $action();
        } catch (BranchException $exception) {
            $this->assertSame($expected, $exception->error);

            return;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([BranchGuard::BRANCHES_MANAGE], $branchIds);
    }

    private function postedJournalIn(int $branchId): int
    {
        return (new JournalService())->postNew($this->journalInput() + ['branch_id' => $branchId]);
    }

    public function testCreate_AddsTheBranchAndAuditsIt(): void
    {
        $employee = $this->manager();

        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah', 'address' => 'Corniche Road', 'opened_on' => '2026-01-15'], $employee);

        $branch = $this->service->find($branchId);

        $this->assertSame('JED', $branch->code);
        $this->assertSame('Jeddah', $branch->name);
        $this->assertSame('Corniche Road', $branch->address);
        $this->assertSame('2026-01-15', $branch->opened_on);
        $this->assertSame('1', (string) $branch->active);
        $this->assertSame('0', (string) $branch->is_default);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'branch')->where('entity_id', $branchId)->get()->getRow();

        $this->assertSame('created', $audit->action);
        $this->assertSame((string) $employee, (string) $audit->employee_id);
    }

    public function testCreate_UppercasesTheCode(): void
    {
        $branchId = $this->service->create(['code' => ' jed-2 ', 'name' => 'Jeddah'], $this->manager());

        $this->assertSame('JED-2', $this->service->find($branchId)->code);
    }

    public function testCreate_RejectsAnInvalidCode(): void
    {
        $employee = $this->manager();

        foreach (['', '-JED', 'JED BRANCH', 'THIS-CODE-IS-FAR-TOO-LONG', 'جدة'] as $code) {
            $this->assertFailsWith(BranchError::InvalidCode, fn () => $this->service->create(['code' => $code, 'name' => 'Jeddah'], $employee));
        }
    }

    public function testCreate_RejectsACodeAlreadyInUse(): void
    {
        $employee = $this->manager();

        $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);

        $this->assertFailsWith(BranchError::CodeTaken, fn () => $this->service->create(['code' => 'jed', 'name' => 'Another'], $employee));
    }

    public function testCreate_RejectsABlankName(): void
    {
        $this->assertFailsWith(BranchError::InvalidName, fn () => $this->service->create(['code' => 'JED', 'name' => '  '], $this->manager()));
    }

    public function testCreate_RejectsAnImpossibleOpeningDate(): void
    {
        $this->assertFailsWith(BranchError::InvalidDate, fn () => $this->service->create(['code' => 'JED', 'name' => 'Jeddah', 'opened_on' => '2026-02-30'], $this->manager()));
    }

    public function testCreate_RequiresTheManagePermission(): void
    {
        $employee = $this->employeeWithGrants([]);

        $this->assertFailsWith(BranchError::PermissionDenied, fn () => $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee));
    }

    public function testCreate_RequiresEveryBranch(): void
    {
        $employee = $this->manager([1]);

        $this->assertFailsWith(BranchError::CompanyScopeNeeded, fn () => $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee));
    }

    public function testCreate_AcceptsTheSystemAsTheActor(): void
    {
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah']);

        $this->assertNotNull($this->service->find($branchId));
    }

    public function testUpdate_ChangesTheFieldsOfABranchWithoutRecords(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);

        $this->service->update($branchId, ['code' => 'JDH', 'name' => 'Jeddah North', 'phone' => '0500000000'], $employee);

        $branch = $this->service->find($branchId);

        $this->assertSame('JDH', $branch->code);
        $this->assertSame('Jeddah North', $branch->name);
        $this->assertSame('0500000000', $branch->phone);
    }

    public function testUpdate_RejectsANewCodeForABranchWithRecords(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);
        $this->postedJournalIn($branchId);

        $this->assertFailsWith(BranchError::CodeLocked, fn () => $this->service->update($branchId, ['code' => 'JDH', 'name' => 'Jeddah'], $employee));

        $this->service->update($branchId, ['code' => 'JED', 'name' => 'Jeddah Central'], $employee);

        $this->assertSame('Jeddah Central', $this->service->find($branchId)->name);
    }

    public function testUpdate_RejectsACodeAlreadyInUse(): void
    {
        $employee = $this->manager();
        $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);
        $other = $this->service->create(['code' => 'DMM', 'name' => 'Dammam'], $employee);

        $this->assertFailsWith(BranchError::CodeTaken, fn () => $this->service->update($other, ['code' => 'JED', 'name' => 'Dammam'], $employee));
    }

    public function testUpdate_RejectsABranchOutsideTheEmployeesScope(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->manager([$second]);

        $this->assertFailsWith(BranchError::NotAllowed, fn () => $this->service->update(1, ['code' => 'HQ', 'name' => 'Renamed'], $employee));

        $this->service->update($second, ['code' => 'B2', 'name' => 'Renamed'], $employee);

        $this->assertSame('Renamed', $this->service->find($second)->name);
    }

    public function testUpdate_RejectsAnUnknownBranch(): void
    {
        $this->assertFailsWith(BranchError::NotFound, fn () => $this->service->update(9999, ['code' => 'X', 'name' => 'X'], $this->manager()));
    }

    public function testDeactivate_RejectsTheDefaultBranch(): void
    {
        $this->assertFailsWith(BranchError::DefaultLocked, fn () => $this->service->deactivate(1, $this->manager()));
    }

    public function testDeactivate_RejectsABranchWithDraftJournals(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);

        (new JournalService())->saveDraft($this->journalInput() + ['branch_id' => $branchId]);

        $this->assertFailsWith(BranchError::HasDrafts, fn () => $this->service->deactivate($branchId, $employee));
    }

    public function testDeactivate_StopsTheBranchFromReceivingNewJournals(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);

        $this->service->deactivate($branchId, $employee);

        $this->assertSame('0', (string) $this->service->find($branchId)->active);
        $this->assertFailsWith(BranchError::AlreadyInactive, fn () => $this->service->deactivate($branchId, $employee));
    }

    public function testActivate_RestoresAnInactiveBranch(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);

        $this->assertFailsWith(BranchError::AlreadyActive, fn () => $this->service->activate($branchId, $employee));

        $this->service->deactivate($branchId, $employee);
        $this->service->activate($branchId, $employee);

        $this->assertSame('1', (string) $this->service->find($branchId)->active);
    }

    public function testDelete_RemovesABranchNobodyUsed(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);

        $this->service->delete($branchId, $employee);

        $this->assertNull($this->service->find($branchId));
    }

    public function testDelete_RejectsABranchWithJournals(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);
        $this->postedJournalIn($branchId);

        $this->assertFailsWith(BranchError::InUse, fn () => $this->service->delete($branchId, $employee));
        $this->assertArrayHasKey('journals', $this->service->references($branchId));
    }

    public function testDelete_RejectsABranchWithEmployeesOrLocations(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);
        $this->employeeWithGrants([], [$branchId]);

        $this->assertFailsWith(BranchError::InUse, fn () => $this->service->delete($branchId, $employee));
        $this->assertArrayHasKey('employees', $this->service->references($branchId));
    }

    public function testDelete_RejectsTheDefaultBranch(): void
    {
        $this->assertFailsWith(BranchError::DefaultLocked, fn () => $this->service->delete(1, $this->manager()));
    }

    public function testDelete_RequiresEveryBranch(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->manager([$second]);

        $this->assertFailsWith(BranchError::CompanyScopeNeeded, fn () => $this->service->delete($second, $employee));
    }

    public function testAssignLocations_MovesTheLocationsToTheBranch(): void
    {
        $employee  = $this->manager();
        $branchId  = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);
        $locations = array_map(static fn (object $row): int => (int) $row->location_id, $this->service->locations());

        $this->assertNotEmpty($locations);

        $this->service->assignLocations($branchId, [$locations[0]], $employee);

        $byId = [];

        foreach ($this->service->locations() as $location) {
            $byId[(int) $location->location_id] = (int) $location->branch_id;
        }

        $this->assertSame($branchId, $byId[$locations[0]]);
    }

    public function testAssignLocations_ReturnsDroppedLocationsToTheDefaultBranch(): void
    {
        $employee  = $this->manager();
        $branchId  = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);
        $locations = array_map(static fn (object $row): int => (int) $row->location_id, $this->service->locations());

        $this->service->assignLocations($branchId, [$locations[0]], $employee);
        $this->service->assignLocations($branchId, [], $employee);

        foreach ($this->service->locations() as $location) {
            $this->assertSame(1, (int) $location->branch_id);
        }
    }

    public function testAssignLocations_RejectsALocationThatDoesNotExist(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);

        $this->assertFailsWith(BranchError::LocationNotFound, fn () => $this->service->assignLocations($branchId, [987654], $employee));
    }

    public function testAssignLocations_RequiresEveryBranch(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->manager([$second]);

        $this->assertFailsWith(BranchError::CompanyScopeNeeded, fn () => $this->service->assignLocations($second, [], $employee));
    }

    public function testAll_ListsOnlyTheBranchesInTheEmployeesScope(): void
    {
        $second = $this->createBranch('B2');
        $this->createBranch('B3');

        $this->assertSame(['HQ', 'B2', 'B3'], array_column($this->service->all($this->manager()), 'code'));
        $this->assertSame(['B2'], array_column($this->service->all($this->manager([$second])), 'code'));
        $this->assertSame([], $this->service->all($this->manager([])));
    }

    public function testAll_CountsLocationsEmployeesAndJournals(): void
    {
        $employee = $this->manager();
        $branchId = $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee);
        $this->postedJournalIn($branchId);
        $this->employeeWithGrants([], [$branchId]);

        $branch = array_values(array_filter($this->service->all(), static fn (object $row): bool => (int) $row->branch_id === $branchId))[0];

        $this->assertSame(1, (int) $branch->journal_count);
        $this->assertSame(1, (int) $branch->employee_count);
        $this->assertSame(0, (int) $branch->location_count);
    }

    public function testSuggestCode_SkipsCodesAlreadyTaken(): void
    {
        $this->createBranch('BR2');

        $this->assertSame('BR3', $this->service->suggestCode());
    }

    public function testCreate_DoesNotNeedTheAccountingPermissions(): void
    {
        $employee = $this->employeeWithGrants([BranchGuard::BRANCHES_MANAGE]);

        $this->assertFalse((new AccountingGuard())->has(AccountingGuard::JOURNALS_POST, $employee));
        $this->assertGreaterThan(0, $this->service->create(['code' => 'JED', 'name' => 'Jeddah'], $employee));
    }
}
