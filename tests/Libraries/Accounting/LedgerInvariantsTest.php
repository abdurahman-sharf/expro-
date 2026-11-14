<?php

namespace Tests\Libraries\Accounting;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Ledger\LedgerDatabase;
use Tests\Support\Ledger\LedgerRunner;
use Tests\Support\Ledger\LedgerScenario;

/**
 * Seeded runs of mixed operations on the real services, with the accounting invariants asserted after every
 * batch and at the end. LEDGER_PROFILE picks the size: quick (3 seeds of 80 operations, the default), full
 * (20 of 300) or long (one run of 3000). LEDGER_SEED and LEDGER_OPS replay one run, and LEDGER_SKIP lists
 * positions to leave out of the replay.
 */
class LedgerInvariantsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const BATCH = 20;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $bcScale;

    public static function setUpBeforeClass(): void
    {
        $saved = LedgerDatabase::savedSnapshot();

        if (getenv('LEDGER_REUSE') === '1' && $saved !== null && is_file($saved)) {
            return;
        }

        if ($saved !== null && is_file($saved)) {
            unlink($saved);
        }

        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->bcScale = bcscale();
        bcscale(4);
    }

    protected function tearDown(): void
    {
        bcscale($this->bcScale);

        parent::tearDown();
    }

    /**
     * @return array<string, array{int, int}>
     */
    public static function runs(): array
    {
        $seed = getenv('LEDGER_SEED');

        if ($seed !== false && $seed !== '') {
            return ['seed ' . $seed => [(int) $seed, (int) (getenv('LEDGER_OPS') ?: 300)]];
        }

        [$count, $operations, $first] = match (getenv('LEDGER_PROFILE') ?: 'quick') {
            'full'  => [20, 300, (int) (getenv('LEDGER_FIRST') ?: 1)],
            'long'  => [1, 3000, (int) (getenv('LEDGER_FIRST') ?: 9001)],
            default => [3, 80, 1],
        };

        $runs = [];

        for ($index = 0; $index < $count; $index++) {
            $runs['seed ' . ($first + $index)] = [$first + $index, $operations];
        }

        return $runs;
    }

    #[DataProvider('runs')]
    public function testRun_KeepsEveryInvariantAcrossMixedOperations(int $seed, int $operations): void
    {
        $listed = trim((string) getenv('LEDGER_SKIP'));
        $skip   = $listed === '' ? [] : array_map('intval', explode(',', $listed));
        $runner = new LedgerRunner($seed, $skip);
        $broken = $runner->run($operations, self::BATCH);

        $this->writeLog($seed, $runner->scenario);

        if ($broken !== null) {
            $this->fail($this->failure($runner, $seed, $operations, $skip, $broken));
        }

        $negative = $runner->invariants->locationsValuedBelowNothing();

        if ($negative !== []) {
            $runner->scenario->observed['F1-location-valued-below-nothing'] = ['count' => count($negative), 'example' => $negative[0]];
        }

        fwrite(STDERR, sprintf("seed %d: %d operations, invariants asked %s, outcomes %s, refusals %s, observed %s\n", $seed, $operations, json_encode($runner->invariants->asked), json_encode($runner->scenario->outcomes), json_encode($runner->scenario->refusals), json_encode($runner->scenario->observed)));

        $this->assertCount($operations, $runner->scenario->log);
    }

    private function writeLog(int $seed, LedgerScenario $scenario): void
    {
        $logDirectory = getenv('LEDGER_LOG');

        if ($logDirectory !== false && $logDirectory !== '') {
            file_put_contents($logDirectory . '/seed-' . $seed . '.log', implode("\n", $scenario->log) . "\n");
        }
    }

    /**
     * @param list<int> $skip
     */
    private function failure(LedgerRunner $runner, int $seed, int $operations, array $skip, string $message): string
    {
        $reached = $runner->scenario->position() + 1;
        $replay  = sprintf('LEDGER_SEED=%d LEDGER_OPS=%d%s vendor/bin/phpunit --no-coverage --filter testRun_KeepsEveryInvariantAcrossMixedOperations tests/Libraries/Accounting/LedgerInvariantsTest.php', $seed, $operations, $skip === [] ? '' : ' LEDGER_SKIP=' . implode(',', $skip));
        $text    = sprintf("LEDGER VIOLATION seed %d (stopped at operation %d): %s\nreplay: %s\n", $seed, $reached - 1, $message, $replay);

        if (getenv('LEDGER_MINIMIZE') === '1') {
            $dropped = $runner->minimize($operations, $message);
            $final   = new LedgerRunner($seed, $dropped);

            $final->run($operations, $operations);

            $kept = array_values(array_filter($final->scenario->log, static fn (string $line): bool => ! str_ends_with($line, ' skipped')));

            return $text . sprintf("minimal replay (%d operations kept): LEDGER_SEED=%d LEDGER_OPS=%d LEDGER_SKIP=%s\n%s", count($kept), $seed, $operations, implode(',', $dropped), implode("\n", $kept));
        }

        return $text . "last operations:\n" . implode("\n", array_slice($runner->scenario->log, -25));
    }
}
