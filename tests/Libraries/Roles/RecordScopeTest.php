<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Roles\RecordScope;
use App\Libraries\Roles\RecordScopeUnavailableException;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use App\Libraries\Roles\RoleTablesUnavailableException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FailingTableConnection;
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

    public function testOwnOnly_IsFalseForAnEmployeeWhoWasGivenNothingAndForTheBooks(): void
    {
        $this->assertFalse($this->scope->ownOnly($this->createEmployee()));
        $this->assertFalse($this->scope->ownOnly(null));
        $this->assertFalse($this->scope->ownOnly(999999));
    }

    public function testOwnOnly_IsTrueForAnEmployeeSetToTheirOwnRecords(): void
    {
        $employee = $this->createEmployee();

        (new RoleService())->setRecordScope($employee, 'own', null);

        $this->assertTrue($this->scope->ownOnly($employee));
    }

    public function testOwnOnly_IsTrueForAnEmployeeWhoHoldsARoleOfTheirOwnRecords(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'], 'own')]);

        $this->assertTrue($this->scope->ownOnly($employee));
    }

    public function testOwnOnly_IsFalseForARoleThatShowsEveryRecord(): void
    {
        $this->assertFalse($this->scope->ownOnly($this->employeeWithRoles([$this->createRole(['sales'], 'all')])));
    }

    public function testOwnOnly_KeepsNarrowingByAnInactiveRoleOfTheirOwnRecords(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'], 'own', false)]);

        $this->assertTrue($this->scope->ownOnly($employee));
    }

    public function testOwnOnly_StaysNarrowWhenTheRoleOfTheirOwnRecordsIsDeactivated(): void
    {
        $name     = 'Deactivated ' . uniqid();
        $role     = $this->createRole(['sales'], 'own', true, $name);
        $employee = $this->employeeWithRoles([$role]);

        $this->assertTrue($this->scope->ownOnly($employee));

        (new RoleService())->update($role, ['name' => $name, 'record_scope' => 'own', 'active' => 0], null);

        $this->assertTrue($this->scope->ownOnly($employee), 'Taking a role out of use never gives its holders more records');

        (new RoleService())->update($role, ['name' => $name, 'record_scope' => 'all', 'active' => 0], null);

        $this->assertFalse($this->scope->ownOnly($employee), 'The administrator lifts the restriction on purpose');
    }

    public function testOwnOnly_TakesTheNarrowestOfSeveralRolesAndOfTheEmployeeThemselves(): void
    {
        $wide   = $this->createRole(['sales'], 'all');
        $narrow = $this->createRole(['items'], 'own');

        $this->assertTrue($this->scope->ownOnly($this->employeeWithRoles([$wide, $narrow])), 'One role of their own records narrows the employee');

        $own = $this->employeeWithRoles([$wide]);

        (new RoleService())->setRecordScope($own, 'own', null);

        $this->assertTrue($this->scope->ownOnly($own), 'A role that shows everything never widens an employee set to their own records');
    }

    public function testOwnOnly_FollowsTheRoleWhenItChangesAndWhenItIsTaken(): void
    {
        $role     = $this->createRole(['sales'], 'all');
        $employee = $this->employeeWithRoles([$role]);

        $this->assertFalse($this->scope->ownOnly($employee));

        (new RoleService())->update($role, ['name' => 'Narrowed ' . uniqid(), 'record_scope' => 'own'], null);

        $this->assertTrue($this->scope->ownOnly($employee));

        (new RoleService())->assign($employee, [], null);

        $this->assertFalse($this->scope->ownOnly($employee));
    }

    public function testAllows_LetsAnEmployeeWhoSeesEverythingUseAnyRecord(): void
    {
        $employee = $this->createEmployee();

        $this->assertTrue($this->scope->allows($employee, $employee));
        $this->assertTrue($this->scope->allows($employee, $employee + 1));
        $this->assertTrue($this->scope->allows($employee, null));
        $this->assertTrue($this->scope->allows(null, 12345), 'The books see everything');
    }

    public function testAllows_LetsAnEmployeeWhoSeesTheirOwnRecordsUseOnlyTheirOwn(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole([], 'own')]);

        $this->assertTrue($this->scope->allows($employee, $employee));
        $this->assertTrue($this->scope->allows($employee, (string) $employee), 'A column read from the database is text');
        $this->assertFalse($this->scope->allows($employee, $employee + 1));
        $this->assertFalse($this->scope->allows($employee, null), 'A record of nobody is not theirs');
        $this->assertFalse($this->scope->allows($employee, 'x'));
    }

    public function testLimit_NamesTheEmployeeWhoseRecordsAreKeptAndNobodyWhenNothingIsCut(): void
    {
        $own = $this->employeeWithRoles([$this->createRole([], 'own')]);
        $all = $this->createEmployee();

        $this->assertSame($own, $this->scope->limit($own));
        $this->assertNull($this->scope->limit($all));
        $this->assertNull($this->scope->limit(null));
    }

    public function testCut_AddsTheConditionOnlyForAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $own     = $this->employeeWithRoles([$this->createRole([], 'own')]);
        $builder = $this->roleDb()->table('expenses');

        $this->scope->cut($builder, 'expenses.employee_id', $own);

        $this->assertStringContainsString('employee_id', $builder->getCompiledSelect());

        $untouched = $this->roleDb()->table('expenses');

        $this->scope->cut($untouched, 'expenses.employee_id', $this->createEmployee());

        $this->assertStringNotContainsString('WHERE', $untouched->getCompiledSelect());
    }

    public function testCondition_ReturnsTheSqlAndTheBindingOrNothing(): void
    {
        $own = $this->employeeWithRoles([$this->createRole([], 'own')]);

        $this->assertSame(['p.employee_id = ?', [$own]], $this->scope->condition('p.employee_id', $own));
        $this->assertSame(['', []], $this->scope->condition('p.employee_id', $this->createEmployee()));
        $this->assertSame(['', []], $this->scope->condition('p.employee_id', null));
    }

    public function testSessionEmployee_ReadsThePersonOfTheSessionAndIsNullWithoutOne(): void
    {
        unset($_SESSION['person_id']);

        $this->assertNull(RecordScope::sessionEmployee());

        $_SESSION['person_id'] = '7';

        $this->assertSame(7, RecordScope::sessionEmployee());

        $_SESSION['person_id'] = 0;

        $this->assertNull(RecordScope::sessionEmployee());
    }

    public function testOwnOnly_AsksAgainWhetherTheMigrationRanOnceTheGrantsWereFlushed(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole([], 'own')]);

        $this->assertTrue($this->scope->ownOnly($employee));

        $this->withoutRoleTables(function () use ($employee): void {
            $this->assertFalse($this->scope->ownOnly($employee));
        });

        $this->assertTrue($this->scope->ownOnly($employee));
    }

    public function testOwnOnly_IsFalseBeforeTheMigrationHasRunEvenWhenTheTablesAreThere(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole([], 'own')]);

        $this->withoutTheMigrationRecord(function () use ($employee): void {
            $this->assertFalse((new RecordScope())->ownOnly($employee));
        });

        $this->assertTrue((new RecordScope())->ownOnly($employee));
    }

    public function testOwnOnly_RefusesWhenTheMigrationIsRecordedButTheTableOfScopesIsGone(): void
    {
        $db       = $this->roleDb();
        $employee = $this->employeeOfTheirOwnRecords();
        $table    = $db->prefixTable('employee_record_scopes');

        RoleGrants::flush();
        $db->query("RENAME TABLE `{$table}` TO `{$table}_away`");

        try {
            $this->expectException(RecordScopeUnavailableException::class);

            (new RecordScope())->ownOnly($employee);
        } finally {
            $db->query("RENAME TABLE `{$table}_away` TO `{$table}`");
            RoleGrants::flush();
        }
    }

    private function failingConnection(?string $table = null, bool $throws = false): FailingTableConnection
    {
        $db               = new FailingTableConnection(config('Database')->tests);
        $db->failingTable = $table;
        $db->throws       = $throws;

        return $db;
    }

    private function employeeOfTheirOwnRecords(): int
    {
        $employee = $this->createEmployee();

        (new RoleService())->setRecordScope($employee, 'own', null);

        return $employee;
    }

    public function testOwnOnly_RefusesWhenTheScopeOfTheEmployeeCannotBeRead(): void
    {
        $employee = $this->employeeOfTheirOwnRecords();

        try {
            (new RecordScope($this->failingConnection('employee_record_scopes')))->ownOnly($employee);
            $this->fail('A scope that could not be read was taken for one that was read.');
        } catch (RecordScopeUnavailableException $exception) {
            $this->assertSame($employee, $exception->employeeId);
            $this->assertStringContainsString('employee_record_scopes', $exception->getMessage());
        }
    }

    public function testOwnOnly_RefusesWithTheSameExceptionWhenTheDriverThrowsWhileTheScopeIsRead(): void
    {
        $employee = $this->employeeOfTheirOwnRecords();

        try {
            (new RecordScope($this->failingConnection('employee_record_scopes', true)))->ownOnly($employee);
            $this->fail('A scope that could not be read was taken for one that was read.');
        } catch (RecordScopeUnavailableException $exception) {
            $this->assertSame($employee, $exception->employeeId);
            $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
        }
    }

    public function testOwnOnly_RefusesWhenTheRolesOfTheEmployeeCannotBeRead(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole([], 'own')]);

        foreach ([false, true] as $throws) {
            foreach (['employee_roles', 'roles'] as $table) {
                try {
                    (new RecordScope($this->failingConnection($table, $throws)))->ownOnly($employee);
                    $this->fail("A scope whose roles could not be read ({$table}) was taken for one that was read.");
                } catch (RecordScopeUnavailableException $exception) {
                    $this->assertSame($employee, $exception->employeeId);
                }
            }
        }
    }

    public function testOwnOnly_RefusesWhenTheRecordOfTheMigrationCannotBeRead(): void
    {
        $employee = $this->employeeOfTheirOwnRecords();

        foreach ([false, true] as $throws) {
            $connection = $this->failingConnection('migrations', $throws);

            try {
                (new RecordScope($connection))->ownOnly($employee);
                $this->fail('A record of the migration that could not be read was taken for a migration that has not run.');
            } catch (RecordScopeUnavailableException $exception) {
                $this->assertSame($employee, $exception->employeeId);
                $this->assertInstanceOf(RoleTablesUnavailableException::class, $exception->getPrevious());

                if ($throws) {
                    $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious()->getPrevious());
                }
            }
        }
    }

    public function testOwnOnly_DoesNotRememberAFailureWhicheverReadFailedAndAnswersOnceItWorks(): void
    {
        $byScope = $this->employeeOfTheirOwnRecords();
        $byRole  = $this->employeeWithRoles([$this->createRole([], 'own')]);

        foreach ([false, true] as $throws) {
            foreach ([['employee_record_scopes', $byScope], ['roles', $byRole], ['migrations', $byScope]] as [$table, $employee]) {
                $connection = $this->failingConnection($table, $throws);
                $scope      = new RecordScope($connection);

                try {
                    $scope->ownOnly($employee);
                    $this->fail('A scope that could not be read was taken for one that was read.');
                } catch (RecordScopeUnavailableException) {
                    $connection->failingTable = null;
                }

                $this->assertTrue($scope->ownOnly($employee));
            }
        }
    }

    public function testOwnOnly_NamesTheEmployeeTheSourceAndTheMessageOfTheDriverInTheException(): void
    {
        $byScope = $this->employeeOfTheirOwnRecords();
        $byRole  = $this->employeeWithRoles([$this->createRole([], 'own')]);

        foreach ([['employee_record_scopes', $byScope, 'employee_record_scopes', 'simulated failure'], ['roles', $byRole, 'employee_roles', 'simulated failure'], ['migrations', $byScope, 'the record of the roles migration', 'The record of the migration of the roles could not be read']] as [$table, $employee, $source, $detail]) {
            $connection = $this->failingConnection($table, true);

            try {
                (new RecordScope($connection))->ownOnly($employee);
                $this->fail('A scope that could not be read was taken for one that was read.');
            } catch (RecordScopeUnavailableException $exception) {
                $this->assertStringContainsString("employee {$employee} ", $exception->getMessage());
                $this->assertStringContainsString("from {$source}:", $exception->getMessage());
                $this->assertStringContainsString($detail, $exception->getMessage());
            }
        }
    }

    public function testOwnOnly_IsFalseForTheBooksWithoutAskingTheDatabaseEvenWhenItFails(): void
    {
        $connection = $this->failingConnection('migrations');

        $this->assertFalse((new RecordScope($connection))->ownOnly(null));
    }

    public function testAllows_RefusesWhenTheScopeCannotBeReadInsteadOfAllowing(): void
    {
        $this->expectException(RecordScopeUnavailableException::class);

        (new RecordScope($this->failingConnection('employee_record_scopes')))->allows($this->employeeOfTheirOwnRecords(), 999999);
    }

    public function testLimit_RefusesWhenTheScopeCannotBeReadInsteadOfCuttingNothing(): void
    {
        $this->expectException(RecordScopeUnavailableException::class);

        (new RecordScope($this->failingConnection('employee_record_scopes')))->limit($this->employeeOfTheirOwnRecords());
    }

    public function testCut_RefusesWhenTheScopeCannotBeReadInsteadOfAddingNoCondition(): void
    {
        $builder = Database::connect()->table('sales');

        $this->expectException(RecordScopeUnavailableException::class);

        (new RecordScope($this->failingConnection('employee_record_scopes')))->cut($builder, 'sales.employee_id', $this->employeeOfTheirOwnRecords());
    }

    public function testCondition_RefusesWhenTheScopeCannotBeReadInsteadOfReturningNoCondition(): void
    {
        $this->expectException(RecordScopeUnavailableException::class);

        (new RecordScope($this->failingConnection('employee_record_scopes')))->condition('sales.employee_id', $this->employeeOfTheirOwnRecords());
    }

    public function testOwnOnly_RefusesWhenAConnectionWithoutDebuggingCannotReadTheTableOfScopes(): void
    {
        $employee = $this->employeeOfTheirOwnRecords();

        $this->withUnreadableRecordScopes(function () use ($employee): void {
            $this->expectException(RecordScopeUnavailableException::class);

            (new RecordScope())->ownOnly($employee);
        });
    }
}
