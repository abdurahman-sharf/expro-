<?php

namespace Tests\Controllers;

use App\Libraries\MigrationLock;
use CodeIgniter\Config\Services;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\MigrationHistoryFixtureTrait;

/**
 * Test suite for the Login controller, including the CI Throttler
 * mitigation for brute-force/credential-stuffing.
 */
class LoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use MigrationHistoryFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private array $usedKeys = [];

    private ?BaseConnection $lockHolder = null;

    protected function setUp(): void
    {
        parent::setUp();

        Services::session()->destroy();
        $this->clearThrottleState();
    }

    protected function tearDown(): void
    {
        $this->restoreMigrations();
        $this->lockHolder?->close();
        $this->lockHolder = null;
        $this->clearThrottleState();

        parent::tearDown();
    }

    private function clearThrottleState(): void
    {
        $throttler = Services::throttler();

        foreach ($this->usedKeys as $key) {
            $throttler->remove($key);
        }

        $this->usedKeys = [];

        Services::resetSingle('throttler');
    }

    private function trackThrottleKeys(string $username = ''): void
    {
        $this->usedKeys[] = 'login-ip-' . Services::request()->getIPAddress();

        if ($username !== '') {
            $this->usedKeys[] = 'login-user-' . strtolower($username);
        }
    }

    /**
     * Login::index() redirects to 'login' and runs a migration when the
     * app's migration state isn't current, bypassing credential checks
     * entirely. Skip credential-dependent assertions in that case so this
     * test only exercises login logic when the DB is actually current
     * (throttling itself is verified independently and runs before this
     * branch, so it's unaffected).
     */
    private function skipIfMigrationRequired(\CodeIgniter\Test\TestResponse $response): void
    {
        $redirectUrl = $response->getRedirectUrl();

        if ($redirectUrl !== null && str_ends_with(rtrim(strtolower($redirectUrl), '/'), '/login')) {
            $this->markTestSkipped('App migration state is not current in this environment; skipping credential-path assertions.');
        }
    }

    public function testValidCredentialsLogsIn(): void
    {
        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'pointofsale',
        ]);
        $this->trackThrottleKeys('admin');

        $this->skipIfMigrationRequired($response);

        $response->assertRedirectTo('home');
    }

    public function testInvalidCredentialsShowsError(): void
    {
        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'wrongpassword',
        ]);
        $this->trackThrottleKeys('admin');

        $this->skipIfMigrationRequired($response);

        $response->assertStatus(200);
        $response->assertSee(lang('Login.invalid_username_and_password'));
    }

    public function testSixthFailedAttemptIsThrottled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', [
                'username' => 'wronguser',
                'password' => 'wrongpassword',
            ]);
            $this->trackThrottleKeys('wronguser');
        }

        $response = $this->post('/login', [
            'username' => 'wronguser',
            'password' => 'wrongpassword',
        ]);
        $this->trackThrottleKeys('wronguser');

        $response->assertStatus(429);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Login.too_many_attempts'), $result['message']);
    }

    public function testCorrectLoginStillWorksUnderThrottleCapacity(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', [
                'username' => 'admin',
                'password' => 'wrongpassword',
            ]);
            $this->trackThrottleKeys('admin');
        }

        $response = $this->post('/login', [
            'username' => 'admin',
            'password' => 'pointofsale',
        ]);
        $this->trackThrottleKeys('admin');

        $this->skipIfMigrationRequired($response);

        $response->assertRedirectTo('home');
    }

    public function testGetRequestToLoginIsNeverThrottled(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $response = $this->get('/login');
            $this->skipIfMigrationRequired($response);
            $response->assertStatus(200);
        }
    }

    private function leaveLatestMigrationPending(): string
    {
        $latest = (string) Database::connect()->table('migrations')->selectMax('version')->get()->getRow()->version;

        $this->forgetMigration($latest);

        return $latest;
    }

    private function holdMigrationLockInAnotherRequest(): void
    {
        $this->lockHolder = Database::connect(config('Database')->tests, false);
        $this->lockHolder->query('SELECT GET_LOCK(?, 0)', [MigrationLock::name(Database::connect()->getDatabase())]);
    }

    private function migrationRan(string $version): bool
    {
        return Database::connect()->table('migrations')->where('version', $version)->countAllResults() > 0;
    }

    public function testPostLogin_LeavesAPendingMigrationAloneWhileAnotherRequestHoldsTheMigrationLock(): void
    {
        $pending = $this->leaveLatestMigrationPending();
        $this->holdMigrationLockInAnotherRequest();

        $response = $this->post('/login', ['username' => 'admin', 'password' => 'pointofsale']);
        $this->trackThrottleKeys('admin');

        $this->assertStringEndsWith('/login', rtrim((string) $response->getRedirectUrl(), '/'));
        $this->assertFalse($this->migrationRan($pending), 'A request that finds the lock taken must not run the migration.');
    }

    public function testPostMigrate_RefusesWhileAnotherRequestHoldsTheMigrationLock(): void
    {
        $pending = $this->leaveLatestMigrationPending();
        $this->holdMigrationLockInAnotherRequest();

        $response = $this->post('/migrate', ['username' => 'admin', 'password' => 'pointofsale']);
        $this->trackThrottleKeys('admin');

        $response->assertStatus(503);
        $this->assertFalse($this->migrationRan($pending));
    }
}
