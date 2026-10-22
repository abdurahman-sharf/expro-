<?php

namespace Tests\Libraries\Organization;

use App\Libraries\Organization\BranchContext;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\AccountingFixtureTrait;

class BranchContextTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private BranchContext $context;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        Services::session()->remove('current_branch_id');
        $this->context = new BranchContext();
    }

    protected function tearDown(): void
    {
        Services::session()->remove('current_branch_id');
        $this->releaseOpenTransactions();

        parent::tearDown();
    }

    public function testCurrent_ReturnsNothingBeforeABranchIsChosen(): void
    {
        $this->assertNull($this->context->current($this->employeeWithGrants([])));
    }

    public function testSwitchTo_RemembersTheChosenBranch(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([]);

        $this->context->switchTo($employee, $second);

        $this->assertSame($second, $this->context->current($employee));
    }

    public function testSwitchTo_AcceptsEveryBranchAsNull(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([]);

        $this->context->switchTo($employee, $second);
        $this->context->switchTo($employee, null);

        $this->assertNull($this->context->current($employee));
    }

    public function testSwitchTo_RejectsABranchOutsideTheScope(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([], [1]);

        try {
            $this->context->switchTo($employee, $second);
            $this->fail('Expected the branch to be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }

        $this->assertNull($this->context->current($employee));
    }

    public function testSwitchTo_RejectsAnInactiveBranch(): void
    {
        $inactive = $this->createBranch('B2', active: false);

        $this->expectException(BranchException::class);

        $this->context->switchTo($this->employeeWithGrants([]), $inactive);
    }

    public function testCurrent_IgnoresAChoiceTheEmployeeCanNoLongerUse(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([], [1, $second]);

        $this->context->switchTo($employee, $second);
        $this->accountingDb()->table('employee_branches')->where('person_id', $employee)->where('branch_id', $second)->delete();

        $this->assertNull($this->context->current($employee));
    }

    public function testFormDefault_UsesTheChosenBranch(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([]);

        $this->assertSame(1, $this->context->formDefault($employee));

        $this->context->switchTo($employee, $second);

        $this->assertSame($second, $this->context->formDefault($employee));
    }

    public function testFormDefault_FallsBackToTheEmployeesDefaultWhenEveryBranchIsChosen(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->employeeWithGrants([], [$second, 1]);

        $this->context->switchTo($employee, null);

        $this->assertSame($second, $this->context->formDefault($employee));
    }
}
