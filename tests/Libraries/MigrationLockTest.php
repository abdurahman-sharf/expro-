<?php

namespace Tests\Libraries;

use App\Libraries\MigrationLock;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;

/**
 * The migrations that a web request starts take a lock of the database server, so that two
 * requests cannot run them together.
 */
class MigrationLockTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ?BaseConnection $holder = null;

    protected function tearDown(): void
    {
        if ($this->holder !== null) {
            $this->holder->close();
            $this->holder = null;
        }

        parent::tearDown();
    }

    private function other(): BaseConnection
    {
        return $this->holder ??= Database::connect(config('Database')->tests, false);
    }

    private function lockIsFree(string $name): bool
    {
        return (int) $this->other()->query('SELECT IS_FREE_LOCK(?) AS free', [$name])->getRow()->free === 1;
    }

    public function testRun_RunsTheWorkOnceAndSaysSo(): void
    {
        $runs = 0;

        $ran = (new MigrationLock())->run(static function () use (&$runs): void {
            $runs++;
        });

        $this->assertTrue($ran);
        $this->assertSame(1, $runs);
    }

    public function testRun_HoldsTheLockWhileTheWorkRunsAndReleasesItAfterwards(): void
    {
        $name   = MigrationLock::name(Database::connect()->getDatabase());
        $during = null;

        (new MigrationLock())->run(function () use ($name, &$during): void {
            $during = $this->lockIsFree($name);
        });

        $this->assertFalse($during, 'Another connection must find the lock taken while the work runs.');
        $this->assertTrue($this->lockIsFree($name), 'The lock is released when the work is done.');
    }

    public function testRun_RefusesToRunWhileAnotherConnectionHoldsTheLock(): void
    {
        $name = MigrationLock::name(Database::connect()->getDatabase());
        $this->other()->query('SELECT GET_LOCK(?, 0)', [$name]);
        $runs = 0;

        $ran = (new MigrationLock(null, 0))->run(static function () use (&$runs): void {
            $runs++;
        });

        $this->assertFalse($ran);
        $this->assertSame(0, $runs);
        $this->assertFalse($this->lockIsFree($name), 'The lock of the other connection is still its own.');
    }

    public function testRun_WaitsTheSecondsItWasGivenForALockThatStaysTaken(): void
    {
        $name = MigrationLock::name(Database::connect()->getDatabase());
        $this->other()->query('SELECT GET_LOCK(?, 0)', [$name]);
        $start = microtime(true);

        $ran = (new MigrationLock(null, 1))->run(static function (): void {
        });

        $this->assertFalse($ran);
        $this->assertGreaterThanOrEqual(0.9, microtime(true) - $start);
    }

    public function testRun_RunsAgainOnceTheOtherConnectionLetsGo(): void
    {
        $name = MigrationLock::name(Database::connect()->getDatabase());
        $this->other()->query('SELECT GET_LOCK(?, 0)', [$name]);
        $this->other()->query('SELECT RELEASE_LOCK(?)', [$name]);

        $this->assertTrue((new MigrationLock())->run(static function (): void {
        }));
    }

    public function testRun_ReleasesTheLockWhenTheWorkThrows(): void
    {
        $name = MigrationLock::name(Database::connect()->getDatabase());

        try {
            (new MigrationLock())->run(static function (): void {
                throw new RuntimeException('the migration broke');
            });
            $this->fail('The failure of the work must reach the caller.');
        } catch (RuntimeException $failure) {
            $this->assertSame('the migration broke', $failure->getMessage());
        }

        $this->assertTrue($this->lockIsFree($name));
    }

    public function testRun_IgnoresALockTakenForAnotherDatabase(): void
    {
        $this->other()->query('SELECT GET_LOCK(?, 0)', [MigrationLock::name('another_database')]);

        $this->assertTrue((new MigrationLock())->run(static function (): void {
        }));
    }

    public function testName_DiffersPerDatabaseAndStaysWithinTheLengthTheServerAllows(): void
    {
        $this->assertNotSame(MigrationLock::name('shop_one'), MigrationLock::name('shop_two'));
        $this->assertLessThanOrEqual(64, strlen(MigrationLock::name(str_repeat('d', 64))));
    }
}
