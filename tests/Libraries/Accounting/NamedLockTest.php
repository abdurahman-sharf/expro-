<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use mysqli;
use Tests\Support\PooledConnection;

/**
 * A lock the database keeps by name counts only while the connection that asked for it is the
 * one that does the work: what is taken, what is refused, and what a pooler in between changes.
 */
class NamedLockTest extends CIUnitTestCase
{
    private const NAME = 'ospos_named_lock_test';

    protected function tearDown(): void
    {
        Database::connect()->query('SELECT RELEASE_LOCK(?)', [self::NAME]);

        parent::tearDown();
    }

    private function otherConnection(): mysqli
    {
        $config = config('Database')->tests;

        return mysqli_connect($config['hostname'], $config['username'], $config['password'], $config['database'], $config['port']);
    }

    private function anotherConnectionCanTake(string $name): bool
    {
        $link = $this->otherConnection();

        try {
            $row = mysqli_fetch_row(mysqli_query($link, "SELECT GET_LOCK('" . mysqli_real_escape_string($link, $name) . "', 0)"));

            return (int) $row[0] === 1;
        } finally {
            mysqli_close($link);
        }
    }

    public function testTake_AcceptsALockNobodyHoldsAndKeepsItUntilItIsReleased(): void
    {
        $lock = new NamedLock(Database::connect(), 0);

        $this->assertSame(LockOutcome::Taken, $lock->take(self::NAME));
        $this->assertFalse($this->anotherConnectionCanTake(self::NAME));

        $lock->release(self::NAME);

        $this->assertTrue($this->anotherConnectionCanTake(self::NAME));
    }

    public function testTake_ReportsBusyWhenAnotherConnectionHoldsTheLock(): void
    {
        $link = $this->otherConnection();
        mysqli_query($link, "SELECT GET_LOCK('" . self::NAME . "', 0)");

        try {
            $this->assertSame(LockOutcome::Busy, (new NamedLock(Database::connect(), 0))->take(self::NAME));
        } finally {
            mysqli_close($link);
        }
    }

    public function testTake_RejectsALockTheConnectionHeldBeforeItAskedAndLeavesItToItsHolder(): void
    {
        $db = Database::connect();
        $db->query('SELECT GET_LOCK(?, 0)', [self::NAME]);

        $this->assertSame(LockOutcome::Occupied, (new NamedLock($db, 0))->take(self::NAME));
        $this->assertFalse($this->anotherConnectionCanTake(self::NAME), 'Somebody else still holds it');
    }

    public function testTake_RejectsALockTheServerKeepsOnAnotherConnectionThanTheOneThatRunsTheWork(): void
    {
        $pooled = new PooledConnection(config('Database')->tests);
        $pooled->sendStatementsMentioning('IS_USED_LOCK', Database::connect('tests', false));

        $this->assertSame(LockOutcome::NotHeld, (new NamedLock($pooled, 0))->take(self::NAME));
        $this->assertTrue($this->anotherConnectionCanTake(self::NAME), 'The refused lock is let go');
    }

    public function testTake_LetsTheSameProcessTakeANameItHoldsAgainAndKeepsItUntilTheLastRelease(): void
    {
        $lock = new NamedLock(Database::connect(), 0);

        $this->assertSame(LockOutcome::Taken, $lock->take(self::NAME));
        $this->assertSame(LockOutcome::Taken, $lock->take(self::NAME));

        $lock->release(self::NAME);

        $this->assertFalse($this->anotherConnectionCanTake(self::NAME), 'The first hold is still there');

        $lock->release(self::NAME);

        $this->assertTrue($this->anotherConnectionCanTake(self::NAME));
    }

    public function testTake_StillCountsItsOwnHoldAsItsOwnAfterOneOfTwoReleases(): void
    {
        $lock = new NamedLock(Database::connect(), 0);

        $this->assertSame(LockOutcome::Taken, $lock->take(self::NAME));
        $this->assertSame(LockOutcome::Taken, $lock->take(self::NAME));
        $lock->release(self::NAME);

        $this->assertSame(LockOutcome::Taken, $lock->take(self::NAME));

        $lock->release(self::NAME);
        $lock->release(self::NAME);

        $this->assertTrue($this->anotherConnectionCanTake(self::NAME));
    }

    public function testProbe_SaysTheConnectionKeepsNamedLocksAndLeavesNothingHeld(): void
    {
        $this->assertSame(LockOutcome::Taken, (new NamedLock(Database::connect(), 0))->probe());
    }

    public function testProbe_SaysTheConnectionDoesNotKeepThemBehindAPooler(): void
    {
        $pooled = new PooledConnection(config('Database')->tests);
        $pooled->sendStatementsMentioning('IS_USED_LOCK', Database::connect('tests', false));

        $this->assertSame(LockOutcome::NotHeld, (new NamedLock($pooled, 0))->probe());
    }
}
