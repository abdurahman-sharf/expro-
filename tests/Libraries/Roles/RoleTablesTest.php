<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Roles\RoleTables;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * @internal
 */
final class RoleTablesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    public function testExist_IsTrueWhenEveryTableNamedExists(): void
    {
        $this->assertTrue(RoleTables::exist(Database::connect(), 'roles'));
        $this->assertTrue(RoleTables::exist(Database::connect(), 'roles', 'role_permissions', 'employee_roles', 'employee_record_scopes', 'employee_default_locations'));
    }

    public function testExist_IsFalseWhenOneTableNamedDoesNotExist(): void
    {
        $this->assertFalse(RoleTables::exist(Database::connect(), 'roles', 'no_such_table'));
        $this->assertFalse(RoleTables::exist(Database::connect(), 'no_such_table'));
    }

    public function testExist_AsksTheServerAndNotTheListOfTablesTheConnectionRemembers(): void
    {
        $db = Database::connect();

        $db->listTables();
        $db->query('CREATE TABLE ' . $db->prefixTable('role_tables_probe') . ' (id INT)');

        try {
            $this->assertTrue(RoleTables::exist($db, 'role_tables_probe'), 'A table created after the list was read exists');
        } finally {
            $db->query('DROP TABLE ' . $db->prefixTable('role_tables_probe'));
        }

        $this->assertFalse(RoleTables::exist($db, 'role_tables_probe'));
    }
}
