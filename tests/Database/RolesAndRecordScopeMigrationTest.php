<?php

namespace Tests\Database;

use App\Database\Migrations\RolesAndRecordScope;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\RoleFixtureTrait;

class RolesAndRecordScopeMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['roles', 'role_permissions', 'employee_roles', 'employee_record_scopes', 'employee_default_locations'];

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new RolesAndRecordScope())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->roleDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->roleDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    public function testUp_CreatesTheFiveTablesAndGivesNoEmployeeARoleAScopeOrALocation(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $employee = $this->createEmployee();

        foreach (['employee_roles', 'employee_record_scopes', 'employee_default_locations'] as $table) {
            $this->assertSame(0, $this->roleDb()->table($table)->where('person_id', $employee)->countAllResults(), $table);
        }
    }

    public function testUp_IsSafeToRunTwiceAndKeepsWhatTheTablesHold(): void
    {
        $role = $this->createRole(['sales']);

        (new RolesAndRecordScope())->up();

        $this->assertSame(1, $this->roleDb()->table('role_permissions')->where('role_id', $role)->countAllResults());
    }

    public function testUp_TouchesNoCoreTableAndCopiesNoGrant(): void
    {
        $grants = $this->roleDb()->table('grants')->countAllResults();

        (new RolesAndRecordScope())->up();

        $this->assertSame($grants, $this->roleDb()->table('grants')->countAllResults());
        $this->assertSame('0', (string) $this->roleDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)',
            [$this->roleDb()->prefixTable('employees'), 'record_scope', 'default_location_id'],
        )->getRow()->found, 'The employees table is the application\'s own and stays as it is');
    }

    public function testUp_RefusesARoleNameUsedTwiceAndARecordScopeThatIsNotAllOrOwn(): void
    {
        $this->roleDb()->table('roles')->insert(['name' => 'Unique name']);

        try {
            $this->roleDb()->table('roles')->insert(['name' => 'Unique name']);
            $this->fail('A name was used twice.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DatabaseException::class);

        $this->roleDb()->table('roles')->insert(['name' => 'Odd scope', 'record_scope' => 'branch']);
    }

    public function testUp_RefusesAPermissionThatDoesNotExistAndAnEmployeeWhoDoesNotExist(): void
    {
        $role = $this->createRole();

        try {
            $this->roleDb()->table('role_permissions')->insert(['role_id' => $role, 'permission_id' => 'no_such_permission']);
            $this->fail('A permission that does not exist was given to a role.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DatabaseException::class);

        $this->roleDb()->table('employee_roles')->insert(['person_id' => 999999, 'role_id' => $role]);
    }

    public function testUp_GivesAnEmployeeOneDefaultLocationAtMostAndOneRecordScopeAtMost(): void
    {
        $employee = $this->createEmployee();

        $this->roleDb()->table('employee_record_scopes')->insert(['person_id' => $employee, 'record_scope' => 'own']);
        $this->roleDb()->table('employee_default_locations')->insert(['person_id' => $employee, 'location_id' => 1]);

        try {
            $this->roleDb()->table('employee_default_locations')->insert(['person_id' => $employee, 'location_id' => 1]);
            $this->fail('An employee was given two defaults.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DatabaseException::class);

        $this->roleDb()->table('employee_record_scopes')->insert(['person_id' => $employee, 'record_scope' => 'all']);
    }

    public function testUp_TakesThePermissionsAndTheLinksAwayWithTheirRole(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);

        $this->roleDb()->table('roles')->where('role_id', $role)->delete();

        $this->assertSame(0, $this->roleDb()->table('role_permissions')->where('role_id', $role)->countAllResults());
        $this->assertSame(0, $this->roleDb()->table('employee_roles')->where('person_id', $employee)->countAllResults());
    }

    public function testDown_TakesTheTablesAwayAndUpBringsThemBack(): void
    {
        $migration = new RolesAndRecordScope();

        try {
            $migration->down();

            foreach (self::TABLES as $table) {
                $this->assertFalse($this->hasTable($table), $table);
            }

            $migration->down();
        } finally {
            $migration->up();
        }

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }
    }
}
