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
        $db    = Database::connect();
        $table = $db->prefixTable('employee_roles');

        $db->query("RENAME TABLE `{$table}` TO `{$table}_away`");

        try {
            $this->assertTrue(RoleTables::migrated($db), 'The record says the migration ran, whatever became of a table');
        } finally {
            $db->query("RENAME TABLE `{$table}_away` TO `{$table}`");
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

    public function testMigration_NamesTheVersionOfTheMigrationThatCreatesTheTables(): void
    {
        $files = glob(APPPATH . 'Database/Migrations/*_RolesAndRecordScope.php');

        $this->assertCount(1, $files);
        $this->assertSame(RoleTables::MIGRATION, substr(basename($files[0]), 0, 14));
    }
}
