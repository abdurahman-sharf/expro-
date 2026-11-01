<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckGroup;
use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\ReadinessRunner;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Events\Events;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\MigrationHistoryFixtureTrait;
use Tests\Support\ReadOnlyRefusingConnection;

/**
 * The runner reads inside one read-only transaction, so that the database itself refuses a check
 * that tries to write, and gives back the checks, the pending migrations and the way it read.
 */
class ReadinessRunnerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use DatabaseFingerprintTrait;
    use MigrationHistoryFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        while ($db->transDepth > 0) {
            $db->transRollback();
        }

        $db->resetTransStatus();
        $db->table('app_config')->where('key', 'readiness_probe')->delete();
        $this->restoreMigrations();

        parent::tearDown();
    }

    /**
     * A group whose one check is the work given.
     *
     * @param callable(BaseConnection): CheckResult $work
     */
    private function groupOf(BaseConnection $db, callable $work): CheckGroup
    {
        return new class ($db, $work) extends CheckGroup {
            /** @param callable(BaseConnection): CheckResult $work */
            public function __construct(BaseConnection $db, private $work)
            {
                parent::__construct($db);
            }

            public function run(): array
            {
                return [$this->check('probe', 'PROBE', 'Probe', [], fn (): CheckResult => ($this->work)($this->db))];
            }
        };
    }

    private function writer(BaseConnection $db): CheckResult
    {
        return $db->table('app_config')->insert(['key' => 'readiness_probe', 'value' => '1']) === false
            ? CheckResult::pass('the write was refused')
            : CheckResult::fail('the database accepted a write from a check');
    }

    private function connectionRefusingReadOnly(bool $throws): BaseConnection
    {
        $connection         = new ReadOnlyRefusingConnection(config('Database')->tests);
        $connection->throws = $throws;

        return $connection;
    }

    public function testRun_RunsEveryCheckOfTheReportOnceAndInOrder(): void
    {
        $result = (new ReadinessRunner())->run();
        $ids    = array_map(static fn (CheckResult $check): string => $check->id, $result->checks);

        $this->assertCount(24, $result->checks);
        $this->assertSame($ids, array_values(array_unique($ids)));
        $this->assertSame('migrations', $ids[0]);
        $this->assertSame(['purchase_discount', 'bank_placement', 'bank_details', 'payment_stamping'], array_slice($ids, 1, 4));
        $this->assertSame('opening_balances', $ids[19]);
        $this->assertSame(['routing_types', 'item_routing', 'entity_routing'], array_slice($ids, 21, 3));
    }

    public function testRun_NamesTheDatabaseAndTheMoment(): void
    {
        $result = (new ReadinessRunner())->run();

        $this->assertSame(Database::connect()->getDatabase(), $result->database);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $result->generatedAt);
    }

    public function testRun_GivesThePendingMigrationsAndThePhase(): void
    {
        $this->assertSame('post-migration', (new ReadinessRunner())->run()->phase());

        $this->forgetMigration('20261029000000');
        $result = (new ReadinessRunner())->run();

        $this->assertSame('pre-migration', $result->phase());
        $this->assertSame(['20261029000000'], array_column($result->pendingMigrations, 'version'));
    }

    public function testRun_ReadsInsideAReadOnlyTransactionAndSaysSo(): void
    {
        $result = (new ReadinessRunner(Database::connect(), [$this->groupOf(Database::connect(), static fn (): CheckResult => CheckResult::pass('read'))]))->run();

        $this->assertTrue($result->readOnly);
    }

    public function testRun_ShowsEveryCheckOneSnapshotWhateverTheIsolationOfTheSession(): void
    {
        $db     = Database::connect();
        $other  = Database::connect(config('Database')->tests, false);
        $counts = [];
        $count  = static fn (): int => $db->table('app_config')->where('key', 'readiness_probe')->countAllResults();

        $db->query('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

        try {
            (new ReadinessRunner($db, [
                $this->groupOf($db, static function () use ($count, $other, &$counts): CheckResult {
                    $counts[] = $count();
                    $other->table('app_config')->insert(['key' => 'readiness_probe', 'value' => '1']);

                    return CheckResult::pass('read');
                }),
                $this->groupOf($db, static function () use ($count, &$counts): CheckResult {
                    $counts[] = $count();

                    return CheckResult::pass('read');
                }),
            ]))->run();
        } finally {
            $db->query('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        }

        $this->assertSame([0, 0], $counts, 'A row another request committed while the report ran must stay out of its later checks.');
        $this->assertSame(1, $count(), 'The row is committed: only the snapshot of the report hid it.');
    }

    public function testRun_RefusesTheWriteOfACheckAndChangesNoRow(): void
    {
        $db     = Database::connect();
        $before = $this->databaseFingerprint();

        $result = (new ReadinessRunner($db, [$this->groupOf($db, fn (): CheckResult => $this->writer($db))]))->run();

        $this->assertSame(CheckStatus::Pass, $result->checks[0]->status);
        $this->assertSame('the write was refused', $result->checks[0]->summary);
        $this->assertDatabaseUnchanged($before);
    }

    public function testRun_StillCompletesTheReportAfterACheckThatTriedToWrite(): void
    {
        $db = Database::connect();

        $result = (new ReadinessRunner($db, [
            $this->groupOf($db, fn (): CheckResult => $this->writer($db)),
            $this->groupOf($db, static fn (): CheckResult => CheckResult::pass('read')),
        ]))->run();

        $this->assertSame(['the write was refused', 'read'], array_map(static fn (CheckResult $check): string => $check->summary, $result->checks));
    }

    public function testRun_LeavesNoTransactionOpen(): void
    {
        $db = Database::connect();

        (new ReadinessRunner($db, [$this->groupOf($db, static fn (): CheckResult => CheckResult::pass('read'))]))->run();

        $this->assertSame(0, $db->transDepth);
    }

    public function testRun_LeavesNoTransactionOpenWhenAGroupThrows(): void
    {
        $db    = Database::connect();
        $group = new class ($db) extends CheckGroup {
            public function run(): array
            {
                throw new RuntimeException('the group broke');
            }
        };

        try {
            (new ReadinessRunner($db, [$group]))->run();
            $this->fail('The failure of a group must reach the caller.');
        } catch (RuntimeException $failure) {
            $this->assertSame('the group broke', $failure->getMessage());
        }

        $this->assertSame(0, $db->transDepth);
    }

    public function testRun_ReadsOnInsideATransactionTheCallerOpenedAndLeavesItOpen(): void
    {
        $db = Database::connect();
        $db->transStart();
        $db->table('app_config')->insert(['key' => 'readiness_probe', 'value' => '1']);
        $statements = [];
        $listener   = static function ($query) use (&$statements): void {
            $statements[] = (string) $query->getQuery();
        };
        Events::on('DBQuery', $listener);

        try {
            $result = (new ReadinessRunner($db, [$this->groupOf($db, static fn (): CheckResult => CheckResult::pass('read'))]))->run();
        } finally {
            Events::removeListener('DBQuery', $listener);
        }

        $this->assertFalse($result->readOnly);
        $this->assertSame(1, $db->transDepth, 'The transaction of the caller stays open.');
        $this->assertSame([], array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'SET TRANSACTION')));
        $this->assertSame(1, $db->table('app_config')->where('key', 'readiness_probe')->countAllResults(), 'What the caller wrote is still there.');

        $db->transRollback();
        $db->resetTransStatus();
    }

    public function testRun_SaysItReadWithoutAReadOnlyTransactionWhenTheConnectionRefusesOne(): void
    {
        foreach ([false, true] as $throw) {
            $db     = $this->connectionRefusingReadOnly($throw);
            $result = (new ReadinessRunner($db, [$this->groupOf($db, static fn (): CheckResult => CheckResult::pass('read'))]))->run();

            $this->assertFalse($result->readOnly, $throw ? 'throws' : 'returns false');
            $this->assertSame(CheckStatus::Pass, $result->checks[0]->status);
            $this->assertSame(0, $db->transDepth);
        }
    }

    public function testRun_RollsBackWhatACheckWroteWhenNoReadOnlyTransactionIsAvailable(): void
    {
        $db = $this->connectionRefusingReadOnly(false);

        $result = (new ReadinessRunner($db, [$this->groupOf($db, fn (): CheckResult => $this->writer($db))]))->run();

        $this->assertSame('the database accepted a write from a check', $result->checks[0]->summary);
        $this->assertSame(0, Database::connect()->table('app_config')->where('key', 'readiness_probe')->countAllResults(), 'The transaction is rolled back, not committed.');
    }

    public function testRun_ChangesNoRowWhenItRunsEveryCheck(): void
    {
        $before = $this->databaseFingerprint();

        (new ReadinessRunner())->run();

        $this->assertDatabaseUnchanged($before);
    }
}
