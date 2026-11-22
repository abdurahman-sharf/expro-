<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Roles\RoleTables;
use App\Libraries\Roles\RoleTablesUnavailableException;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\FailingTableConnection;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class RoleTablesTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    private function failingConnection(?string $table, bool $throws = false): FailingTableConnection
    {
        $db               = new FailingTableConnection(config('Database')->tests);
        $db->failingTable = $table;
        $db->throws       = $throws;

        return $db;
    }

    public function testMigrated_IsTrueOnceTheMigrationIsRecorded(): void
    {
        $this->assertTrue(RoleTables::migrated(Database::connect()));
    }

    public function testMigrated_IsFalseWhileTheMigrationIsNotRecordedEvenIfTheTablesAreThere(): void
    {
        $this->withoutTheMigrationRecord(function (): void {
            $this->assertFalse(RoleTables::migrated(Database::connect()));
        });

        $this->assertTrue(RoleTables::migrated(Database::connect()));
    }

    public function testMigrated_AnswersFromTheRecordAndNotFromTheTables(): void
    {
        $db     = Database::connect();
        $tables = ['employee_default_locations', 'employee_record_scopes', 'employee_roles', 'role_permissions', 'roles'];

        foreach ($tables as $table) {
            $name = $db->prefixTable($table);
            $db->query("RENAME TABLE `{$name}` TO `{$name}_away`");
        }

        try {
            $this->assertTrue(RoleTables::migrated($db), 'The record says the migration ran, whatever became of the tables');
        } finally {
            foreach (array_reverse($tables) as $table) {
                $name = $db->prefixTable($table);
                $db->query("RENAME TABLE `{$name}_away` TO `{$name}`");
            }
        }
    }

    public function testMigrated_DoesNotAskTheListOfTablesOfTheServer(): void
    {
        $db                           = $this->failingConnection(null);
        $db->failingInformationSchema = true;

        $this->assertTrue(RoleTables::migrated($db));
    }

    public function testMigrated_RefusesWhenTheRecordCannotBeRead(): void
    {
        foreach ([false, true] as $throws) {
            try {
                RoleTables::migrated($this->failingConnection('migrations', $throws));
                $this->fail('A record that could not be read was taken for an answer.');
            } catch (RoleTablesUnavailableException $exception) {
                if ($throws) {
                    $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
                }

                $this->assertSame($throws, $exception->getPrevious() !== null);
            }
        }
    }

    public function testRead_ReturnsTheResultOfAQueryThatWorks(): void
    {
        $result = RoleTables::read($this->failingConnection(null)->table('migrations')->select('1', false)->where('version', RoleTables::MIGRATION), 'The migration');

        $this->assertCount(1, $result->getResultArray());
    }

    public function testRead_RefusesWhenTheDriverThrows(): void
    {
        $builder = $this->failingConnection('roles', true)->table('roles');

        try {
            RoleTables::read($builder, 'The roles of the employee 7');
            $this->fail('A read that failed was taken for a table that holds nothing.');
        } catch (RoleTablesUnavailableException $exception) {
            $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
            $this->assertStringContainsString('The roles of the employee 7 could not be read: simulated failure', $exception->getMessage());
        }
    }

    public function testRead_RefusesWhenTheConnectionAnswersFalse(): void
    {
        $builder = $this->failingConnection('roles')->table('roles');

        try {
            RoleTables::read($builder, 'The roles of the employee 7');
            $this->fail('A read that failed was taken for a table that holds nothing.');
        } catch (RoleTablesUnavailableException $exception) {
            $this->assertNull($exception->getPrevious());
            $this->assertSame('The roles of the employee 7 could not be read: unknown error', $exception->getMessage());
        }
    }

    public function testRead_NamesTheErrorOfTheDriverWhenAFailedQueryAnswersFalse(): void
    {
        $this->withUnreadableTable('role_permissions', function (): void {
            try {
                RoleTables::read(Database::connect()->table('role_permissions'), 'The permissions of a role');
                $this->fail('A read that failed was taken for a table that holds nothing.');
            } catch (RoleTablesUnavailableException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertStringContainsString(Database::connect()->prefixTable('role_permissions'), $exception->getMessage(), 'The message carries what the driver said');
            }
        });
    }

    public function testRead_RefusesInsideATransactionWhereAFailedQueryAnswersFalseWhateverTheDebugging(): void
    {
        $db = Database::connect();

        $db->transStart();

        try {
            RoleTables::read($db->table('role_tables_that_are_not_there'), 'A table that is not there');
            $this->fail('A read that failed was taken for a table that holds nothing.');
        } catch (RoleTablesUnavailableException $exception) {
            $this->assertStringContainsString('role_tables_that_are_not_there', $exception->getMessage());
        } finally {
            $db->transComplete();
            $db->resetTransStatus();
        }
    }

    public function testCount_ReturnsTheNumberOfRowsTheBuilderSelectsAndZeroWhenThereAreNone(): void
    {
        $db   = Database::connect();
        $role = $this->createRole(['sales', 'items']);

        $this->assertSame(2, RoleTables::count($db->table('role_permissions')->where('role_id', $role), 'The permissions of a role'));
        $this->assertSame(0, RoleTables::count($db->table('role_permissions')->where('role_id', 999999), 'The permissions of a role'));
    }

    public function testCount_KeepsTheJoinsAndTheConditionsOfTheBuilder(): void
    {
        $db       = Database::connect();
        $inactive = $this->createRole(['sales', 'items', 'customers'], active: false);
        $active   = $this->createRole(['sales', 'items', 'customers']);
        $builder  = static fn (int $roleId) => $db->table('role_permissions rp')->join('roles r', 'r.role_id = rp.role_id AND r.active = 1')->where('rp.role_id', $roleId);

        $this->assertSame(0, RoleTables::count($builder($inactive), 'The permissions of an active role'));
        $this->assertSame(3, RoleTables::count($builder($active), 'The permissions of an active role'));
    }

    public function testCount_RefusesWhenTheCountCannotBeReadInsteadOfAnsweringNone(): void
    {
        foreach ([false, true] as $throws) {
            try {
                RoleTables::count($this->failingConnection('role_permissions', $throws)->table('role_permissions'), 'The permissions of a role');
                $this->fail('A count that failed was taken for none.');
            } catch (RoleTablesUnavailableException $exception) {
                $this->assertStringStartsWith('The permissions of a role could not be read: ', $exception->getMessage());
                $this->assertSame($throws, $exception->getPrevious() instanceof DatabaseException);
            }
        }
    }

    public function testCount_RefusesOnAProductionConnectionWhoseTableCannotBeRead(): void
    {
        $this->withUnreadableTable('role_permissions', function (): void {
            try {
                RoleTables::count(Database::connect()->table('role_permissions'), 'The permissions of a role');
                $this->fail('A count that failed was taken for none.');
            } catch (RoleTablesUnavailableException $exception) {
                $this->assertNull($exception->getPrevious());
            }
        });
    }

    public function testMigration_NamesTheVersionOfTheMigrationThatCreatesTheTables(): void
    {
        $files = glob(APPPATH . 'Database/Migrations/*_RolesAndRecordScope.php');

        $this->assertCount(1, $files);
        $this->assertSame(RoleTables::MIGRATION, substr(basename($files[0]), 0, 14));
    }
}
