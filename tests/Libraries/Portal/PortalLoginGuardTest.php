<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Portal\PortalLoginGuard;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

/**
 * @internal
 */
final class PortalLoginGuardTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const NOW = 1_800_000_000;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('portal_login_failures'));

        parent::tearDown();
    }

    private function guard(): PortalLoginGuard
    {
        return new PortalLoginGuard();
    }

    private function failTimes(string $key, int $times, int $threshold, int $now): bool
    {
        $locked = false;

        for ($index = 0; $index < $times; $index++) {
            $locked = $this->guard()->recordFailure($key, $threshold, $now);
        }

        return $locked;
    }

    public function testUserKey_IsTheSameForTheNameInAnyCaseAndNeverTheNameItself(): void
    {
        $key = PortalLoginGuard::userKey('Ahmed');

        $this->assertSame($key, PortalLoginGuard::userKey(' ahmed '));
        $this->assertNotSame($key, PortalLoginGuard::userKey('ahmed2'));
        $this->assertSame(64, strlen($key));
        $this->assertStringNotContainsString('ahmed', $key);
        $this->assertNotSame(PortalLoginGuard::userKey('10.0.0.1'), PortalLoginGuard::addressKey('10.0.0.1'));
    }

    public function testRecordFailure_LocksOnTheFifthFailureOfAUserNameAndNotBefore(): void
    {
        $key = PortalLoginGuard::userKey('ahmed');

        $this->assertFalse($this->failTimes($key, PortalLoginGuard::USER_THRESHOLD - 1, PortalLoginGuard::USER_THRESHOLD, self::NOW));
        $this->assertNull($this->guard()->lockedUntil($key, self::NOW));

        $this->assertTrue($this->guard()->recordFailure($key, PortalLoginGuard::USER_THRESHOLD, self::NOW));
        $this->assertSame(self::NOW + PortalLoginGuard::BASE_LOCK_SECONDS, $this->guard()->lockedUntil($key, self::NOW));
    }

    public function testLockedUntil_ReleasesTheKeyWhenTheLockHasRunOut(): void
    {
        $key = PortalLoginGuard::userKey('ahmed');
        $this->failTimes($key, PortalLoginGuard::USER_THRESHOLD, PortalLoginGuard::USER_THRESHOLD, self::NOW);

        $this->assertNotNull($this->guard()->lockedUntil($key, self::NOW + PortalLoginGuard::BASE_LOCK_SECONDS - 1));
        $this->assertNull($this->guard()->lockedUntil($key, self::NOW + PortalLoginGuard::BASE_LOCK_SECONDS));
    }

    public function testRecordFailure_DoublesEachLockUpToADay(): void
    {
        $key  = PortalLoginGuard::userKey('ahmed');
        $now  = self::NOW;
        $seen = [];

        for ($lock = 0; $lock < 8; $lock++) {
            $this->failTimes($key, PortalLoginGuard::USER_THRESHOLD, PortalLoginGuard::USER_THRESHOLD, $now);
            $until  = $this->guard()->lockedUntil($key, $now);
            $seen[] = $until - $now;
            $now    = $until;
        }

        $this->assertSame([900, 1800, 3600, 7200, 14400, 28800, 57600, 86400], $seen);
    }

    public function testRecordFailure_ForgetsFailuresOlderThanADay(): void
    {
        $key = PortalLoginGuard::userKey('ahmed');
        $this->failTimes($key, PortalLoginGuard::USER_THRESHOLD - 1, PortalLoginGuard::USER_THRESHOLD, self::NOW);

        $this->assertFalse($this->guard()->recordFailure($key, PortalLoginGuard::USER_THRESHOLD, self::NOW + PortalLoginGuard::WINDOW_SECONDS + 1));
    }

    public function testRecordFailure_KeepsFailuresOfLessThanADayTogether(): void
    {
        $key = PortalLoginGuard::userKey('ahmed');
        $this->failTimes($key, PortalLoginGuard::USER_THRESHOLD - 1, PortalLoginGuard::USER_THRESHOLD, self::NOW);

        $this->assertTrue($this->guard()->recordFailure($key, PortalLoginGuard::USER_THRESHOLD, self::NOW + PortalLoginGuard::WINDOW_SECONDS - 1));
    }

    public function testRecordFailure_CountsTheKeysApart(): void
    {
        $this->failTimes(PortalLoginGuard::userKey('ahmed'), PortalLoginGuard::USER_THRESHOLD, PortalLoginGuard::USER_THRESHOLD, self::NOW);

        $this->assertNull($this->guard()->lockedUntil(PortalLoginGuard::userKey('sara'), self::NOW));
        $this->assertFalse($this->guard()->recordFailure(PortalLoginGuard::userKey('sara'), PortalLoginGuard::USER_THRESHOLD, self::NOW));
    }

    public function testClear_LiftsTheLockAndTheCount(): void
    {
        $key = PortalLoginGuard::userKey('ahmed');
        $this->failTimes($key, PortalLoginGuard::USER_THRESHOLD, PortalLoginGuard::USER_THRESHOLD, self::NOW);

        $this->guard()->clear($key);

        $this->assertNull($this->guard()->lockedUntil($key, self::NOW));
        $this->assertSame(0, $this->accountingDb()->table('portal_login_failures')->where('failure_key', $key)->countAllResults());
        $this->assertFalse($this->failTimes($key, PortalLoginGuard::USER_THRESHOLD - 1, PortalLoginGuard::USER_THRESHOLD, self::NOW));
    }

    public function testRecordFailure_LocksAnAddressOnlyAtTheHigherThreshold(): void
    {
        $key = PortalLoginGuard::addressKey('10.0.0.9');

        $this->assertFalse($this->failTimes($key, PortalLoginGuard::ADDRESS_THRESHOLD - 1, PortalLoginGuard::ADDRESS_THRESHOLD, self::NOW));
        $this->assertTrue($this->guard()->recordFailure($key, PortalLoginGuard::ADDRESS_THRESHOLD, self::NOW));
    }

    public function testTakeTurn_GivesTheTurnOnAKeyToOneConnectionAtATimeAndToTheNextOneWhenItEnds(): void
    {
        $key   = PortalLoginGuard::userKey('ahmed');
        $first = new PortalLoginGuard(null, 0);
        $other = new PortalLoginGuard(Database::connect('tests', false), 0);

        try {
            $this->assertTrue($first->takeTurn($key));
            $this->assertFalse($other->takeTurn($key));

            $first->endTurn($key);

            $this->assertTrue($other->takeTurn($key));
        } finally {
            $first->endTurn($key);
            $other->endTurn($key);
        }
    }

    public function testTakeTurn_KeepsTheTurnsOfDifferentKeysApart(): void
    {
        $first = new PortalLoginGuard(null, 0);
        $other = new PortalLoginGuard(Database::connect('tests', false), 0);
        $mine  = PortalLoginGuard::userKey('ahmed');
        $yours = PortalLoginGuard::userKey('sara');

        try {
            $this->assertTrue($first->takeTurn($mine));
            $this->assertTrue($other->takeTurn($yours));
        } finally {
            $first->endTurn($mine);
            $other->endTurn($yours);
        }
    }

    public function testTurnName_IsTheSameForTheSameKeyAndDiffersForAnotherKeyAndAnotherDatabaseAndFitsTheLimitOfALockName(): void
    {
        $key   = PortalLoginGuard::userKey('ahmed');
        $other = $this->createStub(BaseConnection::class);
        $other->method('getDatabase')->willReturn('another_shop');

        $this->assertSame($this->guard()->turnName($key), $this->guard()->turnName($key));
        $this->assertNotSame($this->guard()->turnName($key), $this->guard()->turnName(PortalLoginGuard::userKey('sara')));
        $this->assertNotSame($this->guard()->turnName($key), (new PortalLoginGuard($other))->turnName($key));
        $this->assertLessThanOrEqual(64, strlen($this->guard()->turnName($key)));
        $this->assertStringNotContainsString($key, $this->guard()->turnName($key));
    }
}
