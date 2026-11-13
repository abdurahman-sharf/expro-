<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalLogin;
use App\Libraries\Portal\PortalLoginGuard;
use App\Libraries\Portal\PortalLoginOutcome;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\PortalFixtureTrait;

/**
 * @internal
 */
final class PortalLoginTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use PortalFixtureTrait;

    private const NOW          = 1_800_000_000;
    private const ADDRESS      = '10.1.2.3';
    private const NEW_PASSWORD = 'Brand-new-Pass-77';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /**
     * @var array{account_id: int, username: string, customer_id: int}
     */
    private array $account;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->account = $this->portalAccountOf($this->insertCustomer());
    }

    protected function tearDown(): void
    {
        $this->cleanPortal();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function attempt(string $password, string $username = 'ahmed', string $address = self::ADDRESS, int $now = self::NOW): PortalLoginOutcome
    {
        return (new PortalLogin())->attempt($username, $password, $address, $now)->outcome;
    }

    private function failures(int $times, string $username = 'ahmed', string $address = self::ADDRESS, int $now = self::NOW): void
    {
        for ($index = 0; $index < $times; $index++) {
            $this->assertSame(PortalLoginOutcome::Invalid, $this->attempt('wrong password ' . $index, $username, $address, $now));
        }
    }

    public function testAttempt_OpensTheAccountWithTheRightPasswordAndRecordsTheSignIn(): void
    {
        $result = (new PortalLogin())->attempt('Ahmed', self::PORTAL_PASSWORD, self::ADDRESS, self::NOW);

        $this->assertSame(PortalLoginOutcome::Success, $result->outcome);
        $this->assertSame($this->account['account_id'], (int) $result->account->account_id);
        $this->assertNotNull((new PortalAccounts())->find($this->account['account_id'])->last_login_at);
    }

    public function testAttempt_WritesAnAuditEntryOfASuccessWithTheAddressAndNoPassword(): void
    {
        $this->attempt(self::PORTAL_PASSWORD);
        $audit = $this->portalAudit('portal_login_succeeded');

        $this->assertCount(1, $audit);
        $this->assertSame(['customer_id' => $this->account['customer_id'], 'address' => self::ADDRESS], json_decode($audit[0]['details'], true));
        $this->assertStringNotContainsString(self::PORTAL_PASSWORD, json_encode($this->accountingDb()->table('accounting_audit_log')->get()->getResultArray()));
    }

    public function testAttempt_AnswersAWrongPasswordAnUnknownNameAndADisabledAccountTheSameWay(): void
    {
        (new PortalAccounts())->setActive($this->insertedAccount('sara'), false, $this->portalManager());

        $this->assertSame(PortalLoginOutcome::Invalid, $this->attempt('not it'));
        $this->assertSame(PortalLoginOutcome::Invalid, $this->attempt(self::PORTAL_PASSWORD, 'nobody'));
        $this->assertSame(PortalLoginOutcome::Invalid, $this->attempt(self::PORTAL_PASSWORD, 'sara'));
    }

    private function insertedAccount(string $username): int
    {
        return $this->portalAccountOf($this->insertCustomer('Sara', 'Nasser'), $username)['account_id'];
    }

    public function testAttempt_WritesAnAuditEntryOfEachFailureWithTheReasonAndNeverThePassword(): void
    {
        $this->attempt('a wrong guess');
        $this->attempt('another guess', 'nobody');
        $audit = $this->portalAudit('portal_login_failed');

        $this->assertCount(2, $audit);
        $this->assertSame(['name_ref' => substr(PortalLoginGuard::userKey('ahmed'), 0, 16), 'reason' => 'wrong_password', 'address' => self::ADDRESS], json_decode($audit[0]['details'], true));
        $this->assertSame('unknown_user', json_decode($audit[1]['details'], true)['reason']);
        $this->assertNull($audit[1]['entity_id']);
        $this->assertStringNotContainsString('a wrong guess', json_encode($audit));
        $this->assertStringNotContainsString('another guess', json_encode($audit));
    }

    public function testAttempt_NeverWritesTheNameThatWasTypedBecauseItMayBeAPasswordInTheWrongField(): void
    {
        $this->attempt('x', 'ab3defgh4jkm5n');
        $this->attempt('y', 'ab3defgh4jkm5n');
        $this->attempt('z', 'Another-Name');
        $audit = $this->portalAudit('portal_login_failed');

        $this->assertStringNotContainsStringIgnoringCase('ab3defgh4jkm5n', json_encode($audit));
        $this->assertStringNotContainsStringIgnoringCase('another-name', json_encode($audit));
        $this->assertSame(json_decode($audit[0]['details'], true)['name_ref'], json_decode($audit[1]['details'], true)['name_ref']);
        $this->assertNotSame(json_decode($audit[0]['details'], true)['name_ref'], json_decode($audit[2]['details'], true)['name_ref']);
        $this->assertSame(16, strlen(json_decode($audit[0]['details'], true)['name_ref']));
    }

    #[DataProvider('provideAttempt_RefusesASpellingTheCollationTakesForTheName')]
    public function testAttempt_RefusesASpellingTheCollationTakesForTheNameEvenWithTheRightPassword(string $spelling): void
    {
        $this->assertSame(PortalLoginOutcome::Invalid, $this->attempt(self::PORTAL_PASSWORD, $spelling));
        $this->assertNull((new PortalAccounts())->find($this->account['account_id'])->last_login_at);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideAttempt_RefusesASpellingTheCollationTakesForTheName(): iterable
    {
        return ['an accent on the first letter' => ["\u{E1}hmed"], 'an accent on the last letter' => ["ahme\u{301}d"], 'an accent inside' => ["ahm\u{E9}d"]];
    }

    public function testAttempt_CountsEachSpellingOnItsOwnSoThatNoSpellingGuessesForTheAccountAndNoneLocksIt(): void
    {
        foreach (["\u{E1}hmed", "ahm\u{E9}d", "ahme\u{301}d", "\u{E1}hm\u{E9}d"] as $index => $spelling) {
            $address = '10.5.5.' . $index;

            $this->failures(PortalLoginGuard::USER_THRESHOLD, $spelling, $address);
            $this->assertSame(PortalLoginOutcome::Locked, $this->attempt(self::PORTAL_PASSWORD, $spelling, $address));
        }

        $this->assertSame(PortalLoginOutcome::Success, $this->attempt(self::PORTAL_PASSWORD));
    }

    public function testAttempt_LocksTheNameOfTheAccountWhateverTheCaseAndTheSpacesAroundWhatWasTyped(): void
    {
        $this->failures(2, 'AHMED');
        $this->failures(2, ' ahmed ');
        $this->failures(1, 'Ahmed');

        $this->assertSame(PortalLoginOutcome::Locked, $this->attempt(self::PORTAL_PASSWORD));
    }

    public function testAttempt_RefusesAnAttemptThatCannotHaveItsTurnWithoutLookingAtThePasswordOrCountingIt(): void
    {
        $other = Database::connect('tests', false);
        $guard = new PortalLoginGuard(null, 0);
        $name  = $guard->turnName(PortalLoginGuard::userKey('ahmed'));

        $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS taken', [$name])->getRow()->taken);

        try {
            $result = (new PortalLogin(null, null, $guard))->attempt('ahmed', self::PORTAL_PASSWORD, self::ADDRESS, self::NOW);
            $this->assertSame(PortalLoginOutcome::Locked, $result->outcome);
            $this->assertNull((new PortalAccounts())->find($this->account['account_id'])->last_login_at);
            $this->assertSame(0, $this->countRows('portal_login_failures'));
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
        }

        $this->assertSame(PortalLoginOutcome::Success, (new PortalLogin(null, null, $guard))->attempt('ahmed', self::PORTAL_PASSWORD, self::ADDRESS, self::NOW)->outcome);
    }

    public function testAttempt_GivesTheTurnBackAfterASuccessAndAfterAFailure(): void
    {
        $other = Database::connect('tests', false);
        $name  = (new PortalLoginGuard())->turnName(PortalLoginGuard::userKey('ahmed'));

        $this->attempt('not it');
        $this->attempt(self::PORTAL_PASSWORD);

        try {
            $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS taken', [$name])->getRow()->taken);
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function testAttempt_KeepsTheTurnsOfDifferentNamesApart(): void
    {
        $other = Database::connect('tests', false);
        $guard = new PortalLoginGuard();
        $name  = $guard->turnName(PortalLoginGuard::userKey('somebody-else'));

        $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS taken', [$name])->getRow()->taken);

        try {
            $this->assertSame(PortalLoginOutcome::Success, $this->attempt(self::PORTAL_PASSWORD));
            $this->assertNotSame($name, $guard->turnName(PortalLoginGuard::userKey('ahmed')));
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    public function testAttempt_LocksTheUserNameAfterFiveFailuresAndRefusesTheRightPasswordWhileItLocks(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD);

        $this->assertSame(PortalLoginOutcome::Locked, $this->attempt(self::PORTAL_PASSWORD));
        $this->assertSame(PortalLoginOutcome::Locked, $this->attempt(self::PORTAL_PASSWORD, 'ahmed', '10.9.9.9'));
    }

    public function testAttempt_LetsTheRightPasswordInOnTheFourthTryAfterThreeFailures(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD - 1);

        $this->assertSame(PortalLoginOutcome::Success, $this->attempt(self::PORTAL_PASSWORD));
    }

    public function testAttempt_AdmitsTheCustomerAgainWhenTheFirstLockOfFifteenMinutesHasRunOut(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD);

        $this->assertSame(PortalLoginOutcome::Locked, $this->attempt(self::PORTAL_PASSWORD, 'ahmed', self::ADDRESS, self::NOW + PortalLoginGuard::BASE_LOCK_SECONDS - 1));
        $this->assertSame(PortalLoginOutcome::Success, $this->attempt(self::PORTAL_PASSWORD, 'ahmed', self::ADDRESS, self::NOW + PortalLoginGuard::BASE_LOCK_SECONDS));
    }

    public function testAttempt_LocksAUserNameThatNoAccountHasInTheSameWay(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD, 'ghost');

        $this->assertSame(PortalLoginOutcome::Locked, $this->attempt('anything', 'ghost'));
    }

    public function testAttempt_LocksAnAddressAfterTwentyFailuresAcrossDifferentNames(): void
    {
        for ($index = 0; $index < PortalLoginGuard::ADDRESS_THRESHOLD; $index++) {
            $this->attempt('wrong', 'guess' . $index, '10.7.7.7');
        }

        $this->assertSame(PortalLoginOutcome::Locked, $this->attempt(self::PORTAL_PASSWORD, 'ahmed', '10.7.7.7'));
        $this->assertSame(PortalLoginOutcome::Success, $this->attempt(self::PORTAL_PASSWORD, 'ahmed', '10.8.8.8'));
    }

    public function testAttempt_ClearsTheFailuresOfTheNameOnASuccessButNotThoseOfTheAddress(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD - 1);
        $this->attempt(self::PORTAL_PASSWORD);

        $this->failures(PortalLoginGuard::USER_THRESHOLD - 1);

        $this->assertSame(PortalLoginOutcome::Success, $this->attempt(self::PORTAL_PASSWORD));
        $this->assertSame(1, $this->accountingDb()->table('portal_login_failures')->where('failure_key', PortalLoginGuard::addressKey(self::ADDRESS))->countAllResults());
        $this->assertSame(PortalLoginGuard::USER_THRESHOLD * 2 - 2, (int) $this->accountingDb()->table('portal_login_failures')->where('failure_key', PortalLoginGuard::addressKey(self::ADDRESS))->get()->getRow()->failures);
    }

    public function testAttempt_WritesOneAuditEntryWhenALockBeginsAndNoneForTheRefusedAttemptsAfterIt(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD);
        $this->attempt('after the lock');
        $this->attempt(self::PORTAL_PASSWORD);

        $this->assertCount(1, $this->portalAudit('portal_login_locked'));
        $this->assertCount(PortalLoginGuard::USER_THRESHOLD, $this->portalAudit('portal_login_failed'));
        $this->assertSame('username', json_decode($this->portalAudit('portal_login_locked')[0]['details'], true)['by']);
    }

    public function testAttempt_DoesNotLookAtThePasswordOfALockedName(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD);
        $before = $this->countRows('accounting_audit_log');

        $this->attempt(self::PORTAL_PASSWORD);

        $this->assertSame($before, $this->countRows('accounting_audit_log'));
        $this->assertNull((new PortalAccounts())->find($this->account['account_id'])->last_login_at);
    }

    private function change(string $current, string $new = self::NEW_PASSWORD, string $address = self::ADDRESS, int $now = self::NOW): ?AccountingError
    {
        try {
            (new PortalLogin())->changePassword($this->account['account_id'], $current, $new, $address, $now);
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    private function hashOf(): string
    {
        return (string) (new PortalAccounts())->find($this->account['account_id'])->password_hash;
    }

    public function testChangePassword_ChangesThePasswordWhenTheCurrentOneIsRightAndClearsTheFailuresOfTheName(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD - 1);

        $this->assertNull($this->change(self::PORTAL_PASSWORD));

        $this->assertSame(0, $this->accountingDb()->table('portal_login_failures')->where('failure_key', PortalLoginGuard::userKey('ahmed'))->countAllResults());
        $this->assertNotNull((new PortalAccounts())->check('ahmed', self::NEW_PASSWORD)[0]);
    }

    public function testChangePassword_CountsAWrongCurrentPasswordAndLocksTheNameAfterFiveEvenForTheRightOne(): void
    {
        $before = $this->hashOf();

        for ($index = 0; $index < PortalLoginGuard::USER_THRESHOLD; $index++) {
            $this->assertSame(AccountingError::PortalPasswordWrong, $this->change('wrong guess ' . $index));
        }

        $this->assertSame(AccountingError::PortalLocked, $this->change(self::PORTAL_PASSWORD));
        $this->assertSame($before, $this->hashOf());
    }

    public function testChangePassword_LocksTheSignInOfTheNameToo(): void
    {
        for ($index = 0; $index < PortalLoginGuard::USER_THRESHOLD; $index++) {
            $this->change('wrong guess ' . $index);
        }

        $this->assertSame(PortalLoginOutcome::Locked, $this->attempt(self::PORTAL_PASSWORD));
    }

    public function testChangePassword_RefusesWhileTheNameIsLockedByFailedSignIns(): void
    {
        $this->failures(PortalLoginGuard::USER_THRESHOLD);
        $before = $this->hashOf();

        $this->assertSame(AccountingError::PortalLocked, $this->change(self::PORTAL_PASSWORD));
        $this->assertSame($before, $this->hashOf());
    }

    public function testChangePassword_RefusesWhileTheAddressIsLocked(): void
    {
        for ($index = 0; $index < PortalLoginGuard::ADDRESS_THRESHOLD; $index++) {
            $this->attempt('wrong', 'guess' . $index, '10.7.7.7');
        }

        $this->assertSame(AccountingError::PortalLocked, $this->change(self::PORTAL_PASSWORD, self::NEW_PASSWORD, '10.7.7.7'));
        $this->assertNull($this->change(self::PORTAL_PASSWORD, self::NEW_PASSWORD, '10.8.8.8'));
    }

    public function testChangePassword_CountsAFailureAgainstTheAddressToo(): void
    {
        $this->change('wrong guess');

        $this->assertSame(1, (int) $this->accountingDb()->table('portal_login_failures')->where('failure_key', PortalLoginGuard::addressKey(self::ADDRESS))->get()->getRow()->failures);
    }

    public function testChangePassword_WritesAnAuditEntryForEachWrongCurrentPasswordAndNeverAPassword(): void
    {
        $this->change('a wrong guess');
        $this->change(self::PORTAL_PASSWORD, 'short');
        $audit = $this->portalAudit('portal_password_change_failed');

        $this->assertCount(1, $audit);
        $this->assertSame((string) $this->account['account_id'], (string) $audit[0]['entity_id']);
        $this->assertSame(['name_ref' => substr(PortalLoginGuard::userKey('ahmed'), 0, 16), 'reason' => 'wrong_password', 'address' => self::ADDRESS], json_decode($audit[0]['details'], true));
        $this->assertStringNotContainsString('a wrong guess', json_encode($this->accountingDb()->table('accounting_audit_log')->get()->getResultArray()));
    }

    public function testChangePassword_WritesOneAuditEntryWhenTheFifthWrongPasswordLocksTheName(): void
    {
        for ($index = 0; $index < PortalLoginGuard::USER_THRESHOLD + 1; $index++) {
            $this->change('wrong guess ' . $index);
        }

        $locked = $this->portalAudit('portal_login_locked');

        $this->assertCount(1, $locked);
        $this->assertSame('username', json_decode($locked[0]['details'], true)['by']);
        $this->assertCount(PortalLoginGuard::USER_THRESHOLD, $this->portalAudit('portal_password_change_failed'));
    }

    public function testChangePassword_DoesNotCountANewPasswordThatBreaksTheRules(): void
    {
        for ($index = 0; $index < PortalLoginGuard::USER_THRESHOLD * 2; $index++) {
            $this->assertSame(AccountingError::PortalPasswordInvalid, $this->change(self::PORTAL_PASSWORD, 'short'));
        }

        $this->assertSame(0, $this->countRows('portal_login_failures'));
        $this->assertNull($this->change(self::PORTAL_PASSWORD));
    }

    public function testChangePassword_RefusesAnAccountThatDoesNotExist(): void
    {
        try {
            (new PortalLogin())->changePassword(987654, self::PORTAL_PASSWORD, self::NEW_PASSWORD, self::ADDRESS, self::NOW);
            $this->fail('No refusal');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PortalAccountUnknown, $failure->error);
        }
    }

    public function testChangePassword_RefusesAnAttemptThatCannotHaveItsTurnWithoutLookingAtThePassword(): void
    {
        $other = Database::connect('tests', false);
        $guard = new PortalLoginGuard(null, 0);
        $name  = $guard->turnName(PortalLoginGuard::userKey('ahmed'));
        $other->query('SELECT GET_LOCK(?, 0) AS taken', [$name]);
        $before = $this->hashOf();

        try {
            (new PortalLogin(null, null, $guard))->changePassword($this->account['account_id'], self::PORTAL_PASSWORD, self::NEW_PASSWORD, self::ADDRESS, self::NOW);
            $this->fail('No refusal');
        } catch (AccountingException $failure) {
            $this->assertSame(AccountingError::PortalLocked, $failure->error);
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
        }

        $this->assertSame($before, $this->hashOf());
        $this->assertNull($this->change(self::PORTAL_PASSWORD));
    }

    public function testChangePassword_GivesTheTurnBackAfterARefusalAndAfterASuccess(): void
    {
        $other = Database::connect('tests', false);
        $name  = (new PortalLoginGuard())->turnName(PortalLoginGuard::userKey('ahmed'));

        $this->change('wrong guess');
        $this->change(self::PORTAL_PASSWORD);

        try {
            $this->assertSame(1, (int) $other->query('SELECT GET_LOCK(?, 0) AS taken', [$name])->getRow()->taken);
        } finally {
            $other->query('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }
}
