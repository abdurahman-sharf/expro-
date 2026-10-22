<?php

namespace Tests\Libraries\Organization;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class BranchGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BranchGuard $guard;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->guard = new BranchGuard();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    public function testAllowedBranchIds_ReturnsNullForEmployeeWithEveryBranch(): void
    {
        $employee = $this->employeeWithGrants([]);

        $this->assertNull($this->guard->allowedBranchIds($employee));
    }

    public function testAllowedBranchIds_ReturnsNullForTheSystem(): void
    {
        $this->assertNull($this->guard->allowedBranchIds(null));
    }

    public function testAllowedBranchIds_ListsOnlyTheAssignedBranches(): void
    {
        $second   = $this->createBranch('B2');
        $this->createBranch('B3');
        $employee = $this->employeeWithGrants([], [$second]);

        $this->assertSame([$second], $this->guard->allowedBranchIds($employee));
    }

    public function testAllowedBranchIds_ReturnsEmptyListForEmployeeWithoutBranches(): void
    {
        $employee = $this->employeeWithGrants([], []);

        $this->assertSame([], $this->guard->allowedBranchIds($employee));
    }

    public function testCanAccess_RejectsBranchOutsideTheScope(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([], [1]);

        $this->assertTrue($this->guard->canAccess(1, $employee));
        $this->assertFalse($this->guard->canAccess($second, $employee));
    }

    public function testRequireBranch_RejectsBranchOutsideTheScope(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([], [1]);

        try {
            $this->guard->requireBranch($second, $employee);
            $this->fail('Expected the branch to be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }
    }

    public function testRequireCompanyScope_AcceptsEmployeeWithEveryBranch(): void
    {
        $this->guard->requireCompanyScope($this->employeeWithGrants([]));
        $this->guard->requireCompanyScope(null);

        $this->addToAssertionCount(1);
    }

    public function testRequireCompanyScope_RejectsEmployeeLimitedToBranches(): void
    {
        $employee = $this->employeeWithGrants([], [1]);

        try {
            $this->guard->requireCompanyScope($employee);
            $this->fail('Expected the company scope to be required.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::CompanyScopeNeeded, $exception->error);
        }
    }

    public function testDefaultBranchId_ReturnsTheEmployeesOwnDefault(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([], [$second, 1]);

        $this->assertSame($second, $this->guard->defaultBranchId($employee));
    }

    public function testDefaultBranchId_SkipsAnInactiveBranch(): void
    {
        $inactive = $this->createBranch('B2', active: false);
        $employee = $this->employeeWithGrants([], [$inactive, 1]);

        $this->assertSame(1, $this->guard->defaultBranchId($employee));
    }

    public function testDefaultBranchId_ReturnsTheCompanyDefaultForEmployeeWithEveryBranch(): void
    {
        $this->createBranch('B2');

        $this->assertSame(1, $this->guard->defaultBranchId($this->employeeWithGrants([])));
        $this->assertSame(1, $this->guard->defaultBranchId(null));
    }

    public function testSelectableBranches_ExcludesInactiveBranches(): void
    {
        $this->createBranch('B2');
        $this->createBranch('B3', active: false);

        $codes = array_column($this->guard->selectableBranches(null), 'code');

        $this->assertSame(['HQ', 'B2'], $codes);
    }

    public function testSelectableBranches_ListsOnlyTheScopedBranches(): void
    {
        $second   = $this->createBranch('B2');
        $this->createBranch('B3');
        $employee = $this->employeeWithGrants([], [$second]);

        $this->assertSame(['B2'], array_column($this->guard->selectableBranches($employee), 'code'));
    }

    public function testSeesBranches_RequiresMoreThanOneSelectableBranch(): void
    {
        $employee = $this->employeeWithGrants([]);

        $this->assertFalse($this->guard->seesBranches($employee));

        $second = $this->createBranch('B2');

        $this->assertTrue($this->guard->seesBranches($employee));
        $this->assertFalse($this->guard->seesBranches($this->employeeWithGrants([], [$second])));
    }
}
