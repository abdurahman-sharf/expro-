<?php

namespace Tests\Libraries\Integrations;

use App\Libraries\Integrations\ApiRateLimiter;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\IntegrationFixtureTrait;

/**
 * The counting of calls in windows of a minute: each bucket on its own, a new window starting from nothing,
 * and the windows of the past forgotten.
 */
class ApiRateLimiterTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use IntegrationFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $now = 1_800_000_000;
    private ApiRateLimiter $limiter;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->wipeIntegration();
        $this->now     = intdiv(1_800_000_000, 60) * 60;
        $this->limiter = new ApiRateLimiter(null, fn (): int => $this->now);
    }

    protected function tearDown(): void
    {
        $this->wipeIntegration();

        parent::tearDown();
    }

    public function testHit_CountsTheCallsOfABucketInTheMinute(): void
    {
        $this->assertSame(1, $this->limiter->hit('key:1'));
        $this->assertSame(2, $this->limiter->hit('key:1'));
        $this->assertSame(3, $this->limiter->hit('key:1'));
        $this->assertSame(3, $this->limiter->count('key:1'));
    }

    public function testHit_CountsEachBucketOnItsOwn(): void
    {
        $this->limiter->hit('key:1');
        $this->limiter->hit('key:1');

        $this->assertSame(1, $this->limiter->hit('key:2'));
        $this->assertSame(0, $this->limiter->count('key:3'));
    }

    public function testHit_StartsFromNothingInTheNextMinuteAndNotBefore(): void
    {
        $this->limiter->hit('key:1');
        $this->now += 59;

        $this->assertSame(2, $this->limiter->hit('key:1'), 'The last second of the minute is in the minute.');

        $this->now += 1;

        $this->assertSame(1, $this->limiter->hit('key:1'));
        $this->assertSame(1, $this->limiter->count('key:1'));
    }

    public function testRetryAfter_IsTheSecondsLeftInTheMinuteAndAtLeastOne(): void
    {
        $this->assertSame(60, $this->limiter->retryAfter());

        $this->now += 45;

        $this->assertSame(15, $this->limiter->retryAfter());

        $this->now += 14;

        $this->assertSame(1, $this->limiter->retryAfter());
    }

    public function testHit_ForgetsWindowsOlderThanAnHourWhenANewWindowStarts(): void
    {
        $this->limiter->hit('key:old');
        $this->now += 3601;
        $this->limiter->hit('key:new');

        $db = Database::connect();

        $this->assertSame(0, $db->table('api_rate_windows')->where('bucket', 'key:old')->countAllResults());
        $this->assertSame(1, $db->table('api_rate_windows')->where('bucket', 'key:new')->countAllResults());
    }

    public function testHit_KeepsWindowsOfTheLastHour(): void
    {
        $this->limiter->hit('key:recent');
        $this->now += 3000;
        $this->limiter->hit('key:new');

        $this->assertSame(1, Database::connect()->table('api_rate_windows')->where('bucket', 'key:recent')->countAllResults());
    }
}
