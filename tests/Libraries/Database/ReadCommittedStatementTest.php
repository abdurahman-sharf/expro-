<?php

namespace Tests\Libraries\Database;

use App\Libraries\Database\ReadCommittedStatement;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\UncommittedWriterTrait;

/**
 * The statement that builds a temporary table from a SELECT takes no locks on the rows it reads, and leaves
 * the connection at the isolation level it had, whether it succeeds, fails or runs inside a transaction.
 *
 * @internal
 */
final class ReadCommittedStatementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use UncommittedWriterTrait;

    private const TABLE         = 'read_committed_probe_temp';
    private const DEFAULT_LEVEL = 'REPEATABLE-READ';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Database::connect()->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    }

    protected function tearDown(): void
    {
        Database::connect()->query('DROP TEMPORARY TABLE IF EXISTS ' . $this->probe());

        parent::tearDown();
    }

    private function isolationOfTheNextTransaction(): string
    {
        $db = Database::connect();

        $db->transStart();
        $level = $this->isolationNow();
        $db->transComplete();

        return $level;
    }

    private function isolationNow(): string
    {
        $row = Database::connect()->query("SHOW SESSION VARIABLES WHERE Variable_name IN ('tx_isolation', 'transaction_isolation')")->getRow();

        return (string) $row->Value;
    }

    private function probe(): string
    {
        return Database::connect()->prefixTable(self::TABLE);
    }

    private function probeRows(): int
    {
        return (int) Database::connect()->query('SELECT COUNT(*) AS found FROM ' . $this->probe())->getRow()->found;
    }

    private function buildProbe(): void
    {
        ReadCommittedStatement::run(Database::connect(), 'CREATE TEMPORARY TABLE IF NOT EXISTS ' . $this->probe() . ' AS (SELECT sale_id FROM ' . Database::connect()->prefixTable('sales') . ')');
    }

    public function testRun_BuildsTheTableWithoutWaitingForARowThatIsBeingWritten(): void
    {
        $this->whileASaleIsBeingSaved($this->createEmployee(), $this->createTestItem(HAS_NO_STOCK), null, function (): void {
            $this->buildProbe();
        });

        $this->assertSame((int) Database::connect()->table('sales')->countAllResults(), $this->probeRows());
    }

    public function testRun_LeavesTheNextTransactionAtTheIsolationLevelOfTheConnection(): void
    {
        $this->buildProbe();

        $this->assertSame(self::DEFAULT_LEVEL, $this->isolationOfTheNextTransaction());
    }

    public function testRun_LeavesTheNextTransactionAtTheIsolationLevelOfTheConnectionWhenTheStatementFails(): void
    {
        try {
            ReadCommittedStatement::run(Database::connect(), 'CREATE TEMPORARY TABLE ' . $this->probe() . ' AS (SELECT * FROM table_that_does_not_exist)');
            $this->fail('The statement is meant to fail.');
        } catch (DatabaseException) {
            $this->assertSame(self::DEFAULT_LEVEL, $this->isolationOfTheNextTransaction());
        }
    }

    public function testRun_RunsTheStatementInsideATransactionAtTheLevelOfThatTransaction(): void
    {
        $db = Database::connect();

        $db->transStart();
        $this->buildProbe();
        $inside = $this->isolationNow();
        $db->transComplete();

        $this->assertSame(self::DEFAULT_LEVEL, $inside);
        $this->assertSame((int) $db->table('sales')->countAllResults(), $this->probeRows());
    }

    public function testRun_BuildsTheTableWhenTheLevelCannotBeSet(): void
    {
        $db = Database::connect();

        $db->query('START TRANSACTION');

        try {
            $this->buildProbe();

            $this->assertSame((int) $db->table('sales')->countAllResults(), $this->probeRows());
        } finally {
            $db->query('ROLLBACK');
        }
    }
}
