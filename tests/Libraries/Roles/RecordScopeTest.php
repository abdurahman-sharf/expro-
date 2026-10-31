<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Roles\RecordScope;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class RecordScopeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private RecordScope $scope;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        RoleGrants::flush();

        $this->scope = new RecordScope();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['person_id']);

        parent::tearDown();
    }

    public function testOwnOnlyIsFalseForAnEmployeeWhoWasGivenNothingAndForTheBooks(): void
    {
        $this->assertFalse($this->scope->ownOnly($this->createEmployee()));
        $this->assertFalse($this->scope->ownOnly(null));
        $this->assertFalse($this->scope->ownOnly(999999));
    }

    public function testOwnOnlyIsTrueForAnEmployeeSetToTheirOwnRecords(): void
    {
        $employee = $this->createEmployee();

        (new RoleService())->setRecordScope($employee, 'own', null);

        $this->assertTrue($this->scope->ownOnly($employee));
    }

    public function testOwnOnlyIsTrueForAnEmployeeWhoHoldsARoleOfTheirOwnRecords(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'], 'own')]);

        $this->assertTrue($this->scope->ownOnly($employee));
    }

    public function testOwnOnlyIsFalseForARoleThatShowsEveryRecord(): void
    {
        $this->assertFalse($this->scope->ownOnly($this->employeeWithRoles([$this->createRole(['sales'], 'all')])));
    }

    public function testOwnOnlyIgnoresAnInactiveRoleOfTheirOwnRecords(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'], 'own', false)]);

        $this->assertFalse($this->scope->ownOnly($employee));
    }

    public function testOwnOnlyTakesTheNarrowestOfSeveralRolesAndOfTheEmployeeThemselves(): void
    {
        $wide   = $this->createRole(['sales'], 'all');
        $narrow = $this->createRole(['items'], 'own');

        $this->assertTrue($this->scope->ownOnly($this->employeeWithRoles([$wide, $narrow])), 'One role of their own records narrows the employee');

        $own = $this->employeeWithRoles([$wide]);

        (new RoleService())->setRecordScope($own, 'own', null);

        $this->assertTrue($this->scope->ownOnly($own), 'A role that shows everything never widens an employee set to their own records');
    }

    public function testOwnOnlyFollowsTheRoleWhenItChangesAndWhenItIsTaken(): void
    {
        $role     = $this->createRole(['sales'], 'all');
        $employee = $this->employeeWithRoles([$role]);

        $this->assertFalse($this->scope->ownOnly($employee));

        (new RoleService())->update($role, ['name' => 'Narrowed ' . uniqid(), 'record_scope' => 'own'], null);

        $this->assertTrue($this->scope->ownOnly($employee));

        (new RoleService())->assign($employee, [], null);

        $this->assertFalse($this->scope->ownOnly($employee));
    }

    public function testOwnOnlyIgnoresTheRolesOfADeletedEmployee(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'], 'own')]);

        $this->roleDb()->table('employees')->where('person_id', $employee)->update(['deleted' => 1]);
        RoleGrants::flush();

        $this->assertFalse($this->scope->ownOnly($employee));
    }

    public function testAllowsLetsAnEmployeeWhoSeesEverythingUseAnyRecord(): void
    {
        $employee = $this->createEmployee();

        $this->assertTrue($this->scope->allows($employee, $employee));
        $this->assertTrue($this->scope->allows($employee, $employee + 1));
        $this->assertTrue($this->scope->allows($employee, null));
        $this->assertTrue($this->scope->allows(null, 12345), 'The books see everything');
    }

    public function testAllowsLetsAnEmployeeWhoSeesTheirOwnRecordsUseOnlyTheirOwn(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole([], 'own')]);

        $this->assertTrue($this->scope->allows($employee, $employee));
        $this->assertTrue($this->scope->allows($employee, (string) $employee), 'A column read from the database is text');
        $this->assertFalse($this->scope->allows($employee, $employee + 1));
        $this->assertFalse($this->scope->allows($employee, null), 'A record of nobody is not theirs');
        $this->assertFalse($this->scope->allows($employee, 'x'));
    }

    public function testLimitNamesTheEmployeeWhoseRecordsAreKeptAndNobodyWhenNothingIsCut(): void
    {
        $own = $this->employeeWithRoles([$this->createRole([], 'own')]);
        $all = $this->createEmployee();

        $this->assertSame($own, $this->scope->limit($own));
        $this->assertNull($this->scope->limit($all));
        $this->assertNull($this->scope->limit(null));
    }

    public function testCutAddsTheConditionOnlyForAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $own     = $this->employeeWithRoles([$this->createRole([], 'own')]);
        $builder = $this->roleDb()->table('expenses');

        $this->scope->cut($builder, 'expenses.employee_id', $own);

        $this->assertStringContainsString('employee_id', $builder->getCompiledSelect());

        $untouched = $this->roleDb()->table('expenses');

        $this->scope->cut($untouched, 'expenses.employee_id', $this->createEmployee());

        $this->assertStringNotContainsString('WHERE', $untouched->getCompiledSelect());
    }

    public function testConditionReturnsTheSqlAndTheBindingOrNothing(): void
    {
        $own = $this->employeeWithRoles([$this->createRole([], 'own')]);

        $this->assertSame(['p.employee_id = ?', [$own]], $this->scope->condition('p.employee_id', $own));
        $this->assertSame(['', []], $this->scope->condition('p.employee_id', $this->createEmployee()));
        $this->assertSame(['', []], $this->scope->condition('p.employee_id', null));
    }

    public function testSessionEmployeeReadsThePersonOfTheSessionAndIsNullWithoutOne(): void
    {
        unset($_SESSION['person_id']);

        $this->assertNull(RecordScope::sessionEmployee());

        $_SESSION['person_id'] = '7';

        $this->assertSame(7, RecordScope::sessionEmployee());

        $_SESSION['person_id'] = 0;

        $this->assertNull(RecordScope::sessionEmployee());
    }
}
