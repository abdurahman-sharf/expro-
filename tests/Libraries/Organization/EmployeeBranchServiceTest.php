<?php

namespace Tests\Libraries\Organization;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\EmployeeBranchService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class EmployeeBranchServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private EmployeeBranchService $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->service = new EmployeeBranchService();
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

    private function scopeManager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([BranchGuard::SCOPE_MANAGE], $branchIds);
    }

    /**
     * Runs the action while the given employee is the only one with every branch.
     */
    private function whileOnlyUnrestricted(int $personId, callable $action): void
    {
        $db   = $this->accountingDb();
        $rows = $db->table('grants')->where('permission_id', BranchGuard::ALL_BRANCHES)->where('person_id !=', $personId)->get()->getResultArray();

        $db->table('grants')->where('permission_id', BranchGuard::ALL_BRANCHES)->where('person_id !=', $personId)->delete();

        try {
            $action();
        } finally {
            if ($rows !== []) {
                $db->table('grants')->insertBatch($rows);
            }
        }
    }

    public function testScopeOf_DescribesAnEmployeeLimitedToBranches(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([], [$second, 1]);

        $this->assertSame(['all' => false, 'branch_ids' => [1, $second], 'default_branch_id' => $second], $this->service->scopeOf($employee));
    }

    public function testScopeOf_DescribesAnEmployeeWithEveryBranch(): void
    {
        $scope = $this->service->scopeOf($this->employeeWithGrants([]));

        $this->assertTrue($scope['all']);
        $this->assertSame([], $scope['branch_ids']);
        $this->assertNull($scope['default_branch_id']);
    }

    public function testCanManageScope_RequiresThePermission(): void
    {
        $target = $this->employeeWithGrants([]);

        $this->assertTrue($this->service->canManageScope($this->scopeManager(), $target));
        $this->assertFalse($this->service->canManageScope($this->employeeWithGrants([]), $target));
    }

    public function testCanManageScope_RejectsTheActorsOwnScope(): void
    {
        $actor = $this->scopeManager();

        $this->assertFalse($this->service->canManageScope($actor, $actor));
        $this->assertTrue($this->service->canManageScope($actor));
    }

    public function testSetScope_ReplacesTheBranchesAndChoosesTheDefault(): void
    {
        $second = $this->createBranch('B2');
        $third  = $this->createBranch('B3');
        $target = $this->employeeWithGrants([], [1]);

        $this->service->setScope($this->scopeManager(), $target, false, [$second, $third], $third);

        $this->assertSame(['all' => false, 'branch_ids' => [$second, $third], 'default_branch_id' => $third], $this->service->scopeOf($target));
    }

    public function testSetScope_FallsBackToTheFirstBranchAsDefault(): void
    {
        $second = $this->createBranch('B2');
        $target = $this->employeeWithGrants([], [1]);

        $this->service->setScope($this->scopeManager(), $target, false, [$second]);

        $this->assertSame($second, $this->service->scopeOf($target)['default_branch_id']);
    }

    public function testSetScope_GrantsAndRevokesEveryBranch(): void
    {
        $actor  = $this->scopeManager();
        $target = $this->employeeWithGrants([], [1]);

        $this->service->setScope($actor, $target, true, []);

        $this->assertTrue($this->service->scopeOf($target)['all']);

        $this->service->setScope($actor, $target, false, [1]);

        $scope = $this->service->scopeOf($target);

        $this->assertFalse($scope['all']);
        $this->assertSame([1], $scope['branch_ids']);
    }

    public function testSetScope_RequiresThePermission(): void
    {
        $actor  = $this->employeeWithGrants([]);
        $target = $this->employeeWithGrants([], [1]);

        $this->assertFailsWith(BranchError::PermissionDenied, fn () => $this->service->setScope($actor, $target, false, [1]));
    }

    public function testSetScope_RejectsTheActorsOwnScope(): void
    {
        $actor = $this->scopeManager();

        $this->assertFailsWith(BranchError::OwnScope, fn () => $this->service->setScope($actor, $actor, false, [1]));
    }

    public function testSetScope_RejectsAnUnknownEmployee(): void
    {
        $this->assertFailsWith(BranchError::EmployeeNotFound, fn () => $this->service->setScope($this->scopeManager(), 987654, false, [1]));
    }

    public function testSetScope_RejectsGivingEveryBranchFromALimitedScope(): void
    {
        $actor  = $this->scopeManager([1]);
        $target = $this->employeeWithGrants([], [1]);

        $this->assertFailsWith(BranchError::ScopeTooWide, fn () => $this->service->setScope($actor, $target, true, []));
    }

    public function testSetScope_RejectsABranchTheActorCannotUse(): void
    {
        $second = $this->createBranch('B2');
        $actor  = $this->scopeManager([1]);
        $target = $this->employeeWithGrants([], [1]);

        $this->assertFailsWith(BranchError::ScopeTooWide, fn () => $this->service->setScope($actor, $target, false, [1, $second]));
    }

    public function testSetScope_KeepsTheBranchesTheActorCannotSee(): void
    {
        $second = $this->createBranch('B2');
        $third  = $this->createBranch('B3');
        $actor  = $this->scopeManager([$second]);
        $target = $this->employeeWithGrants([], [$second, $third]);

        $this->service->setScope($actor, $target, false, [$second], $second);

        $this->assertSame([$second, $third], $this->service->scopeOf($target)['branch_ids']);
    }

    public function testSetScope_RejectsAnEmployeeWithoutAnyBranch(): void
    {
        $target = $this->employeeWithGrants([], [1]);

        $this->assertFailsWith(BranchError::NoBranch, fn () => $this->service->setScope($this->scopeManager(), $target, false, []));
    }

    public function testSetScope_RejectsADefaultOutsideTheChosenBranches(): void
    {
        $second = $this->createBranch('B2');
        $target = $this->employeeWithGrants([], [1]);

        $this->assertFailsWith(BranchError::InvalidDefault, fn () => $this->service->setScope($this->scopeManager(), $target, false, [1], $second));
    }

    public function testSetScope_RejectsABranchThatDoesNotExist(): void
    {
        $target = $this->employeeWithGrants([], [1]);

        $this->assertFailsWith(BranchError::NotFound, fn () => $this->service->setScope($this->scopeManager(), $target, false, [987654]));
    }

    public function testSetScope_KeepsTheLastEmployeeWithEveryBranch(): void
    {
        $target = $this->employeeWithGrants([]);

        $this->whileOnlyUnrestricted($target, function () use ($target): void {
            $this->assertFailsWith(BranchError::LastUnrestricted, fn () => $this->service->setScope(null, $target, false, [1]));
        });

        $this->assertTrue($this->service->scopeOf($target)['all']);
    }

    public function testSetScope_RejectsChangingAnEmployeeWithEveryBranchFromALimitedScope(): void
    {
        $actor  = $this->scopeManager([1]);
        $target = $this->employeeWithGrants([]);

        $this->assertFailsWith(BranchError::ScopeTooWide, fn () => $this->service->setScope($actor, $target, false, [1]));
        $this->assertTrue($this->service->scopeOf($target)['all']);
    }

    public function testSetScope_AllowsRestrictingAnEmployeeWhenAnotherKeepsEveryBranch(): void
    {
        $actor  = $this->scopeManager();
        $target = $this->employeeWithGrants([]);

        $this->service->setScope($actor, $target, false, [1]);

        $this->assertFalse($this->service->scopeOf($target)['all']);
    }

    public function testSetScope_WritesNothingForADryRun(): void
    {
        $second = $this->createBranch('B2');
        $target = $this->employeeWithGrants([], [1]);
        $before = $this->service->scopeOf($target);

        $this->service->setScope($this->scopeManager(), $target, false, [$second], dryRun: true);

        $this->assertSame($before, $this->service->scopeOf($target));
        $this->assertFailsWith(BranchError::NoBranch, fn () => $this->service->setScope($this->scopeManager(), $target, false, [], dryRun: true));
    }

    public function testSetScope_RecordsTheChangeInTheAuditLog(): void
    {
        $second = $this->createBranch('B2');
        $actor  = $this->scopeManager();
        $target = $this->employeeWithGrants([], [1]);

        $this->service->setScope($actor, $target, false, [$second]);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'employee_scope')->where('entity_id', $target)->get()->getRow();

        $this->assertSame('scope_changed', $audit->action);
        $this->assertSame((string) $actor, (string) $audit->employee_id);
    }

    public function testAfterEmployeeSave_PutsBackTheEveryBranchGrantTheScreenRemoved(): void
    {
        $target = $this->employeeWithGrants([]);
        $before = $this->service->scopeOf($target);

        $this->accountingDb()->table('grants')->where('person_id', $target)->where('permission_id', BranchGuard::ALL_BRANCHES)->delete();

        $this->assertFalse($this->service->scopeOf($target)['all']);

        $this->service->afterEmployeeSave($this->employeeWithGrants([]), $target, $before, null);

        $this->assertTrue($this->service->scopeOf($target)['all']);
    }

    public function testAfterEmployeeSave_GivesANewEmployeeTheStartingBranch(): void
    {
        $second = $this->createBranch('B2');
        $actor  = $this->employeeWithGrants([], [$second]);
        $target = $this->employeeWithGrants([], []);

        $this->service->afterEmployeeSave($actor, $target, null, null);

        $this->assertSame(['all' => false, 'branch_ids' => [$second], 'default_branch_id' => $second], $this->service->scopeOf($target));
    }

    public function testAfterEmployeeSave_AppliesTheRequestedScopeForSomeoneWhoManagesIt(): void
    {
        $second = $this->createBranch('B2');
        $actor  = $this->scopeManager();
        $target = $this->employeeWithGrants([], [1]);

        $this->service->afterEmployeeSave($actor, $target, $this->service->scopeOf($target), ['all' => false, 'branch_ids' => [$second], 'default_branch_id' => $second]);

        $this->assertSame([$second], $this->service->scopeOf($target)['branch_ids']);
    }

    public function testAfterEmployeeSave_IgnoresARequestFromSomeoneWhoCannotManageScope(): void
    {
        $second = $this->createBranch('B2');
        $actor  = $this->employeeWithGrants([]);
        $target = $this->employeeWithGrants([], [1]);

        $this->service->afterEmployeeSave($actor, $target, $this->service->scopeOf($target), ['all' => false, 'branch_ids' => [$second], 'default_branch_id' => $second]);

        $this->assertSame([1], $this->service->scopeOf($target)['branch_ids']);
    }
}
