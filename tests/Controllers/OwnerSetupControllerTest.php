<?php

namespace Tests\Controllers;

use App\Libraries\Setup\InstallState;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Config\Services;
use Tests\Support\InstallStateFixtureTrait;

class OwnerSetupControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use InstallStateFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private string $code;

    /** @var array{putenv: string|false, env: ?string, server: ?string} */
    private array $capacity;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotInstall();
        $this->code     = $this->makeInstallFresh();
        $this->capacity = ['putenv' => getenv('throttle.capacity'), 'env' => $_ENV['throttle.capacity'] ?? null, 'server' => $_SERVER['throttle.capacity'] ?? null];
        $this->setThrottleCapacity('0');
    }

    protected function tearDown(): void
    {
        $this->restoreInstall();
        $this->clearThrottleState();

        $this->restoreThrottleCapacity();

        parent::tearDown();
    }

    private function setThrottleCapacity(string $value): void
    {
        putenv("throttle.capacity={$value}");
        $_ENV['throttle.capacity']    = $value;
        $_SERVER['throttle.capacity'] = $value;
    }

    private function restoreThrottleCapacity(): void
    {
        if ($this->capacity['putenv'] === false) {
            putenv('throttle.capacity');
        } else {
            putenv("throttle.capacity={$this->capacity['putenv']}");
        }

        if ($this->capacity['env'] === null) {
            unset($_ENV['throttle.capacity']);
        } else {
            $_ENV['throttle.capacity'] = $this->capacity['env'];
        }

        if ($this->capacity['server'] === null) {
            unset($_SERVER['throttle.capacity']);
        } else {
            $_SERVER['throttle.capacity'] = $this->capacity['server'];
        }
    }

    private function clearThrottleState(): void
    {
        Services::throttler()->remove('login-ip-' . hash_hmac('sha256', Services::request()->getIPAddress(), config('Encryption')->key));
        Services::resetSingle('throttler');
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function form(array $changes = []): array
    {
        return $changes + [
            'code'             => $this->code,
            'first_name'       => 'Huda',
            'last_name'        => 'Nasser',
            'username'         => 'owner.one',
            'email'            => 'huda@example.com',
            'password'         => 'a-long-secret-1',
            'password_confirm' => 'a-long-secret-1',
        ];
    }

    public function testGetLogin_ShowsTheOwnerSignUpWhileTheInstallationWaitsForItsOwner(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('id="owner-form"');
        $response->assertSee(lang('Owner_setup.code_label'));
        $response->assertDontSee('id="login-form"');
    }

    public function testGetLogin_ShowsTheOrdinaryLoginOnceTheOwnerExists(): void
    {
        $this->setInstallState(InstallState::PENDING_SETUP);

        $response = $this->get('/login');

        $response->assertStatus(200);
        $response->assertSee('id="login-form"');
        $response->assertDontSee('id="owner-form"');
    }

    public function testGetLogin_ShowsTheOrdinaryLoginForAnInstallationThatKnowsNothingOfTheSetup(): void
    {
        $this->installDb()->table('app_config')->where('key', InstallState::KEY)->delete();

        $this->get('/login')->assertSee('id="login-form"');
    }

    public function testPostOwnerSetup_CreatesTheOwnerSignsThemInAndSendsThemHome(): void
    {
        $response = $this->post('/owner_setup', $this->form());

        $response->assertRedirect();
        $this->assertSame('owner.one', $this->installDb()->table('employees')->where('person_id', InstallState::OWNER_ID)->get()->getRow()->username);
        $this->assertSame(InstallState::PENDING_SETUP, (new InstallState())->state());
        $this->assertSame(InstallState::OWNER_ID, (int) session('person_id'));
    }

    public function testPostOwnerSetup_ShowsWhatIsWrongAndKeepsTheSignUpOpen(): void
    {
        $response = $this->post('/owner_setup', $this->form(['code' => 'AAAA-AAAA-AAAA-AAAA']));

        $response->assertStatus(422);
        $response->assertSee(lang('Owner_setup.error_code_wrong'));
        $response->assertSee('value="owner.one"');
        $this->assertTrue((new InstallState())->needsOwner());
    }

    public function testPostOwnerSetup_NeverSendsBackThePasswordOrTheCode(): void
    {
        $response = $this->post('/owner_setup', $this->form(['password_confirm' => 'different-1']));

        $response->assertStatus(422);
        $response->assertSee(lang('Owner_setup.error_password_mismatch'));
        $response->assertDontSee('a-long-secret-1');
        $response->assertDontSee(str_replace('-', '', $this->code));
    }

    public function testPostOwnerSetup_SaysWhyAWeakPasswordIsRefused(): void
    {
        $response = $this->post('/owner_setup', $this->form(['password' => 'short', 'password_confirm' => 'short']));

        $response->assertStatus(422);
        $response->assertSee(lang('Employees.password_minlength', [8]));
    }

    public function testPostOwnerSetup_AnswersNotFoundOnceTheOwnerExists(): void
    {
        $this->setInstallState(InstallState::PENDING_SETUP);

        try {
            $this->post('/owner_setup', $this->form());
            $this->fail('The sign-up answered although the owner exists.');
        } catch (PageNotFoundException) {
            $this->assertTrue(true);
        }

        $this->assertSame('admin', $this->installDb()->table('employees')->where('person_id', InstallState::OWNER_ID)->get()->getRow()->username);
    }

    public function testPostOwnerSetup_AnswersNotFoundForAnInstallationThatKnowsNothingOfTheSetup(): void
    {
        $this->installDb()->table('app_config')->where('key', InstallState::KEY)->delete();

        $this->expectException(PageNotFoundException::class);

        $this->post('/owner_setup', $this->form());
    }

    public function testPostOwnerSetup_RefusesAValueThatIsNotText(): void
    {
        $this->post('/owner_setup', $this->form(['username' => ['owner.one']]))->assertStatus(400);
        $this->assertTrue((new InstallState())->needsOwner());
    }

    public function testPostOwnerSetup_StopsAnAddressThatKeepsTryingCodes(): void
    {
        $this->setThrottleCapacity('2');
        $this->clearThrottleState();

        $wrong = $this->form(['code' => 'AAAA-AAAA-AAAA-AAAA']);

        $this->post('/owner_setup', $wrong)->assertStatus(422);
        $this->post('/owner_setup', $wrong)->assertStatus(422);
        $this->post('/owner_setup', $this->form())->assertStatus(429);
        $this->assertTrue((new InstallState())->needsOwner());
    }
}
