<?php

namespace Tests\Libraries\Setup;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingNotReadyException;
use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\LockOutcome;
use App\Libraries\Accounting\NamedLock;
use App\Libraries\Setup\InstallState;
use App\Libraries\Setup\OwnerClaim;
use App\Libraries\Setup\OwnerClaimException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\InstallStateFixtureTrait;

class OwnerClaimTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use InstallStateFixtureTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;
    private string $code;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->snapshotInstall();
        $this->code = $this->makeInstallFresh();
        $this->accountingDb()->table('accounting_audit_log')->truncate();
    }

    protected function tearDown(): void
    {
        $this->restoreInstall();
        $this->setConfigValue('accounting_enabled', '0');

        parent::tearDown();
    }

    /**
     * @param array<string, string> $changes
     *
     * @return array<string, string>
     */
    private function input(array $changes = []): array
    {
        return $changes + [
            'code'             => $this->code,
            'first_name'       => 'Huda',
            'last_name'        => 'Nasser',
            'username'         => 'owner.one',
            'email'            => 'Huda@Example.com',
            'password'         => 'a-long-secret-1',
            'password_confirm' => 'a-long-secret-1',
        ];
    }

    private function claim(array $changes = []): int
    {
        return (new OwnerClaim())->claim($this->input($changes), '203.0.113.7');
    }

    private function assertRefusedWith(string $key, callable $action): void
    {
        try {
            $action();
        } catch (OwnerClaimException $failure) {
            $this->assertSame($key, $failure->key);

            return;
        }

        $this->fail("Expected the refusal {$key}.");
    }

    private function owner(): object
    {
        return $this->installDb()->table('employees')->where('person_id', InstallState::OWNER_ID)->get()->getRow();
    }

    private function auditActions(): array
    {
        return array_column($this->accountingDb()->table('accounting_audit_log')->orderBy('audit_id')->get()->getResultArray(), 'action');
    }

    public function testClaim_MakesThePersonWithTheCodeTheOwnerWithTheNameAndPasswordTheyChose(): void
    {
        $personId = $this->claim();

        $owner  = $this->owner();
        $person = $this->installDb()->table('people')->where('person_id', $personId)->get()->getRow();

        $this->assertSame(InstallState::OWNER_ID, $personId);
        $this->assertSame('owner.one', $owner->username);
        $this->assertTrue(password_verify('a-long-secret-1', $owner->password));
        $this->assertFalse(password_verify('pointofsale', $owner->password));
        $this->assertSame(['Huda', 'Nasser', 'huda@example.com'], [$person->first_name, $person->last_name, $person->email]);
    }

    public function testClaim_ClosesTheSignUpAndForgetsTheCode(): void
    {
        $this->claim();

        $state = new InstallState();

        $this->assertSame(InstallState::PENDING_SETUP, $state->state());
        $this->assertFalse($state->needsOwner());
        $this->assertFalse($state->codeMatches($this->code));
        $this->assertFileDoesNotExist(WRITEPATH . InstallState::CODE_FILE);
    }

    public function testClaim_GivesTheOwnerEveryModuleAndPermissionTheInstallationHas(): void
    {
        $this->claim();

        $permissions = array_column($this->installDb()->table('permissions')->select('permission_id')->get()->getResultArray(), 'permission_id');
        $held        = array_column($this->installDb()->table('grants')->select('permission_id')->where('person_id', InstallState::OWNER_ID)->get()->getResultArray(), 'permission_id');

        $this->assertSame([], array_values(array_diff($permissions, $held)));
        $this->assertContains('accounting_chart', $held);
        $this->assertContains(AccountingGuard::SETTINGS_MANAGE, $held);
        $this->assertContains(AccountingGuard::PERIODS_CLOSE, $held);
    }

    public function testClaim_GivesTheModulesTheGroupOfTheirOwnAndThePermissionsNone(): void
    {
        $this->claim();

        $module     = $this->installDb()->table('grants')->where('person_id', InstallState::OWNER_ID)->where('permission_id', 'accounting_chart')->get()->getRow();
        $permission = $this->installDb()->table('grants')->where('person_id', InstallState::OWNER_ID)->where('permission_id', AccountingGuard::SETTINGS_MANAGE)->get()->getRow();

        $this->assertNotSame('--', $module->menu_group);
        $this->assertSame('--', $permission->menu_group);
    }

    public function testClaim_DoesNotLetTheOwnerPassAChecktheOtherEmployeesFail(): void
    {
        $this->claim();
        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_base_currency', '');

        $this->expectException(AccountingNotReadyException::class);
        (new AccountingSettings())->enable(InstallState::OWNER_ID);
    }

    public function testClaim_WritesTheClaimInTheAuditWithoutThePasswordOrTheCode(): void
    {
        $this->claim();

        $row = $this->accountingDb()->table('accounting_audit_log')->where('action', 'owner_claimed')->get()->getRow();

        $this->assertNotNull($row);
        $this->assertStringNotContainsString('a-long-secret-1', (string) $row->details);
        $this->assertStringNotContainsString(str_replace('-', '', $this->code), (string) $row->details);
        $this->assertSame((string) InstallState::OWNER_ID, (string) $row->employee_id);
    }

    public function testClaim_RefusesAWrongCodeWritesNothingAndRecordsTheAttempt(): void
    {
        $this->assertRefusedWith('code_wrong', fn () => $this->claim(['code' => 'AAAA-AAAA-AAAA-AAAA']));

        $state = new InstallState();

        $this->assertTrue($state->needsOwner());
        $this->assertSame('admin', $this->owner()->username);
        $this->assertContains('owner_claim_failed', $this->auditActions());
    }

    public function testClaim_RefusesAMissingCode(): void
    {
        $this->assertRefusedWith('code_wrong', fn () => $this->claim(['code' => '']));
        $this->assertTrue((new InstallState())->needsOwner());
    }

    public function testClaim_AcceptsTheCodeTypedInLowerCaseWithSpaces(): void
    {
        $this->assertSame(InstallState::OWNER_ID, $this->claim(['code' => strtolower(str_replace('-', ' ', $this->code))]));
    }

    public function testClaim_RefusesAnInstallationThatHasItsOwnerAlready(): void
    {
        $this->claim();

        $this->assertRefusedWith('closed', fn () => $this->claim(['username' => 'someone.else']));
        $this->assertSame('owner.one', $this->owner()->username);
    }

    public function testClaim_RefusesAnInstallationRestoredFromABackupThatWasSetUp(): void
    {
        foreach ([InstallState::PENDING_SETUP, InstallState::COMPLETE] as $stored) {
            $this->setInstallState($stored);

            $this->assertRefusedWith('closed', fn () => $this->claim());
        }

        $this->assertSame('admin', $this->owner()->username);
    }

    public function testClaim_RefusesAnInstallationRestoredFromABackupThatKnewNothingOfTheSetup(): void
    {
        $this->installDb()->table('app_config')->where('key', InstallState::KEY)->delete();

        $this->assertRefusedWith('closed', fn () => $this->claim());
    }

    public function testClaim_LetsOnlyOneOfTwoRequestsTakeTheOwnerPlace(): void
    {
        $state = new InstallState();

        $this->assertTrue($state->takeOwnerPlace());
        $this->assertRefusedWith('closed', fn () => $this->claim());
        $this->assertSame('admin', $this->owner()->username);
    }

    public function testClaim_IsRefusedWhileAnotherRequestHoldsTheClaim(): void
    {
        $other = $this->openSecondConnection();
        $name  = 'ospos_owner_claim_' . substr(sha1($this->installDb()->getDatabase()), 0, 10);
        $lock  = new NamedLock($other, 0);

        $this->assertSame(LockOutcome::Taken, $lock->take($name));

        try {
            $this->assertRefusedWith('busy', fn () => $this->claim());
            $this->assertTrue((new InstallState())->needsOwner());
        } finally {
            $lock->release($name);
            $other->close();
        }

        $this->assertSame(InstallState::OWNER_ID, $this->claim());
    }

    public function testClaim_RefusesWhatIsNotAGoodNameUsernameEmailOrPassword(): void
    {
        $this->assertRefusedWith('name_required', fn () => $this->claim(['first_name' => '   ']));
        $this->assertRefusedWith('name_required', fn () => $this->claim(['last_name' => str_repeat('x', OwnerClaim::NAME_MAX + 1)]));
        $this->assertRefusedWith('username_invalid', fn () => $this->claim(['username' => 'ab']));
        $this->assertRefusedWith('username_invalid', fn () => $this->claim(['username' => 'has space']));
        $this->assertRefusedWith('username_invalid', fn () => $this->claim(['username' => str_repeat('a', 33)]));
        $this->assertRefusedWith('email_invalid', fn () => $this->claim(['email' => 'not-an-email']));
        $this->assertRefusedWith('password_mismatch', fn () => $this->claim(['password_confirm' => 'a-long-secret-2']));
        $this->assertTrue((new InstallState())->needsOwner());
        $this->assertSame('admin', $this->owner()->username);
    }

    public function testClaim_RefusesAWeakPasswordWithTheLineThatSaysWhy(): void
    {
        foreach ([['short', 'Employees.password_minlength'], ['pointofsale', 'Employees.password_is_default'], ['Owner.One', 'Employees.password_same_as_username']] as [$password, $line]) {
            try {
                $this->claim(['password' => $password, 'password_confirm' => $password]);
                $this->fail('Expected the refusal.');
            } catch (OwnerClaimException $failure) {
                $this->assertSame('password_policy', $failure->key);
                $this->assertSame($line, $failure->context['line']);
            }
        }

        $this->assertTrue((new InstallState())->needsOwner());
    }

    public function testClaim_RefusesAUsernameAnotherEmployeeHasAndLeavesTheSignUpOpen(): void
    {
        $this->createEmployee(username: 'taken.name');

        $this->assertRefusedWith('username_taken', fn () => $this->claim(['username' => 'taken.name']));

        $this->assertTrue((new InstallState())->needsOwner());
        $this->assertSame('admin', $this->owner()->username);
    }

    public function testClaim_CanBeRepeatedAfterARefusalWithoutLosingTheCode(): void
    {
        $this->assertRefusedWith('password_mismatch', fn () => $this->claim(['password_confirm' => 'different-1']));

        $this->assertSame(InstallState::OWNER_ID, $this->claim());
    }
}
