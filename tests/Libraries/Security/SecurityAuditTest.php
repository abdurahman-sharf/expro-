<?php

namespace Tests\Libraries\Security;

use App\Libraries\Integrations\ApiRateLimiter;
use App\Libraries\Security\SecurityAudit;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class SecurityAuditTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private int $now = 1_800_000_000;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->clean();
        $this->now = 1_800_000_000;
    }

    protected function tearDown(): void
    {
        $this->clean();

        parent::tearDown();
    }

    private function clean(): void
    {
        $this->db->table('accounting_audit_log')->whereIn('entity_type', [SecurityAudit::SESSION_ENTITY, SecurityAudit::EMPLOYEE_ENTITY, SecurityAudit::SETTINGS_ENTITY])->delete();
        $this->db->table('api_rate_windows')->like('bucket', 'login-failed:', 'after')->delete();
    }

    private function audit(): SecurityAudit
    {
        return new SecurityAudit($this->db, new ApiRateLimiter($this->db, fn (): int => $this->now));
    }

    /**
     * @return list<object>
     */
    private function entries(string $action): array
    {
        return $this->db->table('accounting_audit_log')->where('action', $action)->orderBy('audit_id')->get()->getResult();
    }

    public function testLoginSucceeded_WritesTheEmployeeWhoSignedInAndTheAddress(): void
    {
        $this->audit()->loginSucceeded(7, '10.0.0.5');

        $entry = $this->entries('employee_login_succeeded')[0];

        $this->assertSame('employee_session', $entry->entity_type);
        $this->assertSame('7', (string) $entry->entity_id);
        $this->assertSame('7', (string) $entry->employee_id);
        $this->assertSame('user', $entry->origin);
        $this->assertSame(['address' => '10.0.0.5'], json_decode($entry->details, true));
    }

    public function testLoginFailed_WritesTheNameAndTheAddressAndNoEmployee(): void
    {
        $this->audit()->loginFailed('cashier1', '10.0.0.5');

        $entry = $this->entries('employee_login_failed')[0];

        $this->assertNull($entry->employee_id);
        $this->assertSame('system', $entry->origin);
        $this->assertSame(['username' => 'cashier1', 'address' => '10.0.0.5'], json_decode($entry->details, true));
    }

    public function testLoginFailed_WritesOnceAMinuteForTheSameNameAndAddress(): void
    {
        $audit = $this->audit();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $audit->loginFailed('cashier1', '10.0.0.5');
        }

        $this->assertCount(1, $this->entries('employee_login_failed'));
    }

    public function testLoginFailed_TreatsTheNameInAnyCaseAsTheSameName(): void
    {
        $audit = $this->audit();

        $audit->loginFailed('Cashier1', '10.0.0.5');
        $audit->loginFailed('CASHIER1', '10.0.0.5');

        $this->assertCount(1, $this->entries('employee_login_failed'));
    }

    public function testLoginFailed_WritesAgainOnceTheMinuteIsOver(): void
    {
        $audit = $this->audit();

        $audit->loginFailed('cashier1', '10.0.0.5');
        $this->now += ApiRateLimiter::WINDOW_SECONDS;
        $audit->loginFailed('cashier1', '10.0.0.5');

        $this->assertCount(2, $this->entries('employee_login_failed'));
    }

    public function testLoginFailed_WritesForAnotherNameOrAnotherAddress(): void
    {
        $audit = $this->audit();

        $audit->loginFailed('cashier1', '10.0.0.5');
        $audit->loginFailed('cashier2', '10.0.0.5');
        $audit->loginFailed('cashier1', '10.0.0.6');

        $this->assertCount(3, $this->entries('employee_login_failed'));
    }

    public function testLoginFailed_KeepsTheNameEscapedAndNoLongerThanSixtyFourCharacters(): void
    {
        $this->audit()->loginFailed('<img src=x onerror=alert(1)>' . str_repeat('a', 100), '10.0.0.5');

        $name = json_decode($this->entries('employee_login_failed')[0]->details, true)['username'];

        $this->assertStringNotContainsString('<', $name);
        $this->assertStringStartsWith('&lt;img src=x onerror=alert(1)&gt;', $name);
        $this->assertLessThanOrEqual(SecurityAudit::NAME_LENGTH * 6, strlen($name));
    }

    public function testLogout_WritesTheEmployeeWhoSignedOut(): void
    {
        $this->audit()->logout(7);

        $entry = $this->entries('employee_logout')[0];

        $this->assertSame('7', (string) $entry->employee_id);
        $this->assertSame('employee_session', $entry->entity_type);
    }

    public function testPasswordChanged_NamesAnEmployeeWhoChangedTheirOwnPassword(): void
    {
        $this->audit()->passwordChanged(7, 7);

        $this->assertCount(1, $this->entries('employee_password_changed'));
        $this->assertSame([], $this->entries('employee_password_reset'));
    }

    public function testPasswordChanged_NamesTheActorAndTheTargetWhenSomeoneElseChangedIt(): void
    {
        $this->audit()->passwordChanged(1, 7);

        $entry = $this->entries('employee_password_reset')[0];

        $this->assertSame('7', (string) $entry->entity_id);
        $this->assertSame('1', (string) $entry->employee_id);
        $this->assertNull($entry->details);
    }

    public function testEmployeeCreated_WritesTheNewEmployeeAndTheirUsername(): void
    {
        $this->audit()->employeeCreated(1, 9, 'new.cashier');

        $entry = $this->entries('employee_created')[0];

        $this->assertSame('9', (string) $entry->entity_id);
        $this->assertSame(['username' => 'new.cashier'], json_decode($entry->details, true));
    }

    public function testEmployeeDeleted_WritesTheDeletedEmployeeAndWhoDeletedThem(): void
    {
        $this->audit()->employeeDeleted(1, 9);

        $entry = $this->entries('employee_deleted')[0];

        $this->assertSame('9', (string) $entry->entity_id);
        $this->assertSame('1', (string) $entry->employee_id);
    }

    public function testUsernameChanged_WritesTheOldAndTheNewName(): void
    {
        $this->audit()->usernameChanged(1, 9, 'old.name', 'new.name');

        $this->assertSame(['before' => 'old.name', 'after' => 'new.name'], json_decode($this->entries('employee_username_changed')[0]->details, true));
    }

    public function testUsernameChanged_WritesNothingWhenTheNameIsTheSame(): void
    {
        $this->audit()->usernameChanged(1, 9, 'same.name', 'same.name');

        $this->assertSame([], $this->entries('employee_username_changed'));
    }

    public function testPermissionsChanged_WritesWhatWasAddedAndWhatWasRemoved(): void
    {
        $this->audit()->permissionsChanged(1, 9, ['sales', 'customers'], ['customers', 'items']);

        $this->assertSame(['added' => ['items'], 'removed' => ['sales']], json_decode($this->entries('employee_permissions_changed')[0]->details, true));
    }

    public function testPermissionsChanged_WritesNothingWhenThePermissionsAreTheSame(): void
    {
        $this->audit()->permissionsChanged(1, 9, ['sales', 'customers'], ['customers', 'sales']);

        $this->assertSame([], $this->entries('employee_permissions_changed'));
    }

    public function testSettingsChanged_NamesTheSettingsThatChangedAndKeepsNoValue(): void
    {
        $this->audit()->settingsChanged(1, ['smtp_pass' => 'old-secret', 'smtp_host' => 'mail.example.com', 'gcaptcha_enable' => '0'], ['smtp_pass' => 'new-secret', 'smtp_host' => 'mail.example.com', 'gcaptcha_enable' => true]);

        $entry = $this->entries('security_settings_changed')[0];

        $this->assertSame(['settings' => ['gcaptcha_enable', 'smtp_pass']], json_decode($entry->details, true));
        $this->assertStringNotContainsString('secret', (string) $entry->details);
        $this->assertSame('security_settings', $entry->entity_type);
    }

    public function testSettingsChanged_IgnoresSettingsThatDoNotGuardTheApplication(): void
    {
        $this->audit()->settingsChanged(1, ['lines_per_page' => '10'], ['lines_per_page' => '20']);

        $this->assertSame([], $this->entries('security_settings_changed'));
    }

    public function testSettingsChanged_WritesNothingWhenABooleanIsSavedAsItWas(): void
    {
        $this->audit()->settingsChanged(1, ['gcaptcha_enable' => '1', 'enforce_privacy' => ''], ['gcaptcha_enable' => true, 'enforce_privacy' => false]);

        $this->assertSame([], $this->entries('security_settings_changed'));
    }
}
