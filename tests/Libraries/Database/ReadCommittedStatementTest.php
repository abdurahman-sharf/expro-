<?php

namespace Tests\Libraries\Database;

use App\Libraries\Database\ReadCommittedStatement;
use App\Models\Customer;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ConcurrentDbRaceTrait;
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
    use ConcurrentDbRaceTrait;
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use UncommittedWriterTrait;

    private const TABLE         = 'read_committed_probe_temp';
    private const DEFAULT_LEVEL = 'REPEATABLE-READ';
    private const HISTORY_DAY   = '2003-07-05';
    private const SAVES         = 40;

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
        $this->forgetTheRace();
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

    private function createCustomer(): int
    {
        $person   = ['first_name' => 'Lock', 'last_name' => 'Race', 'gender' => null, 'phone_number' => '', 'email' => '', 'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '', 'comments' => ''];
        $customer = ['company_name' => null, 'employee_id' => 1];

        $this->assertTrue((bool) model(Customer::class)->save_customer($person, $customer, NEW_ENTRY));

        return (int) $person['person_id'];
    }

    private function seedHistoricalSales(int $employeeId, int $itemId, int $count): void
    {
        $db     = Database::connect();
        $digits = '(SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4 UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        $sales  = $db->prefixTable('sales');

        $db->query("INSERT INTO {$sales} (sale_time, employee_id, comment, sale_status, sale_type, branch_id) SELECT '" . self::HISTORY_DAY . " 00:00:00' + INTERVAL (a.n + 10 * b.n + 100 * c.n + 1000 * d.n) SECOND, {$employeeId}, 'lock race history', 0, 0, 1 FROM {$digits} a, {$digits} b, {$digits} c, {$digits} d WHERE a.n + 10 * b.n + 100 * c.n + 1000 * d.n < {$count}");
        $db->query('INSERT INTO ' . $db->prefixTable('sales_items') . " (sale_id, item_id, line, quantity_purchased, item_cost_price, item_unit_price, item_location) SELECT sale_id, {$itemId}, 1, 1, 1, 10, 1 FROM {$sales} WHERE comment = 'lock race history'");
        $db->query('INSERT INTO ' . $db->prefixTable('sales_payments') . " (sale_id, payment_type, payment_amount, cash_refund, employee_id, reference_code) SELECT sale_id, 'Cash', 10, 0, {$employeeId}, '' FROM {$sales} WHERE comment = 'lock race history'");
    }

    private function forgetTheRace(): void
    {
        $db = Database::connect();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['sales' => 'sale_id', 'receivings' => 'receiving_id'] as $document => $key) {
            $ids = array_column($db->table($document)->select($key)->whereIn('comment', ['lock race', 'lock race history'])->get()->getResultArray(), $key);

            if ($ids === []) {
                continue;
            }

            foreach ($document === 'sales' ? ['sales_payments', 'sales_items_taxes', 'sales_items', 'sales_taxes', 'sales_reward_points'] : ['receivings_items'] as $table) {
                $db->table($table)->whereIn($key, $ids)->delete();
            }

            $db->table($document)->whereIn($key, $ids)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function deadlocksSoFar(): int
    {
        $db     = Database::connect();
        $status = $db->query("SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'")->getRow();

        if ($status !== null) {
            return (int) $status->Value;
        }

        return (int) $db->query("SELECT `COUNT` AS deadlocks FROM information_schema.INNODB_METRICS WHERE `NAME` = 'lock_deadlocks'")->getRow()->deadlocks;
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function builders(): array
    {
        return [
            'the list of sales'               => ['saleList', 'saveSale'],
            'the temporary tables of reports' => ['saleTemp', 'saveSale'],
            'the figures of a customer'       => ['customerStats', 'saveSale'],
            'the summary of sales'            => ['summarySales', 'saveSale'],
            'the summary of payments'         => ['summaryPayments', 'saveSale'],
            'the temporary table of receivings' => ['receivingTemp', 'savePurchase'],
        ];
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

    #[DataProvider('builders')]
    public function testRun_LetsTheDocumentsBeSavedWhileTheBuilderRunsOverAndOverInAnotherSession(string $builder, string $saver): void
    {
        $employeeId = $this->createEmployee();
        $itemId     = $this->createTestItem();
        $customerId = $this->createCustomer();
        $stopFile   = (string) tempnam(sys_get_temp_dir(), 'lock_race_stop_');

        unlink($stopFile);
        $this->seedHistoricalSales($employeeId, $itemId, 3000);

        $deadlocksBefore = $this->deadlocksSoFar();

        try {
            [$saving, $building] = array_map(static fn (string $answer): array => json_decode($answer, true), $this->raceSessions('lockRace', [
                [$saver, (string) $itemId, (string) $employeeId, $saver === 'savePurchase' ? '0' : (string) $customerId, (string) self::SAVES, $stopFile],
                [$builder, (string) $itemId, (string) $employeeId, (string) $customerId, '0', $stopFile],
            ]));
        } finally {
            if (is_file($stopFile)) {
                unlink($stopFile);
            }
        }

        $summary = ['saved' => $saving['done'], 'refused' => $saving['failed'], 'built' => $building['done'], 'failed' => $building['failed'], 'deadlocks' => $this->deadlocksSoFar() - $deadlocksBefore, 'errors' => [...$saving['errors'], ...$building['errors']]];

        if (getenv('LOCK_RACE_LOG') !== false) {
            file_put_contents((string) getenv('LOCK_RACE_LOG'), $builder . ' ' . json_encode($summary) . "\n", FILE_APPEND);
        }

        $this->assertSame(['saved' => self::SAVES, 'refused' => 0, 'failed' => 0, 'deadlocks' => 0, 'errors' => []], ['saved' => $summary['saved'], 'refused' => $summary['refused'], 'failed' => $summary['failed'], 'deadlocks' => $summary['deadlocks'], 'errors' => $summary['errors']], $builder . ' ' . json_encode($summary));
        $this->assertGreaterThan(0, $building['done']);
    }
}
