<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalLogin;
use App\Libraries\Portal\PortalLoginGuard;
use App\Libraries\Portal\PortalLoginOutcome;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
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

    private const NOW     = 1_800_000_000;
    private const ADDRESS = '10.1.2.3';

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
        $this->assertSame(['username' => 'ahmed', 'reason' => 'wrong_password', 'address' => self::ADDRESS], json_decode($audit[0]['details'], true));
        $this->assertSame('unknown_user', json_decode($audit[1]['details'], true)['reason']);
        $this->assertNull($audit[1]['entity_id']);
        $this->assertStringNotContainsString('a wrong guess', json_encode($audit));
        $this->assertStringNotContainsString('another guess', json_encode($audit));
    }

    public function testAttempt_CutsALongUserNameInTheAuditEntryToFiftyCharacters(): void
    {
        $this->attempt('x', str_repeat('a', 300));

        $this->assertSame(50, strlen(json_decode($this->portalAudit('portal_login_failed')[0]['details'], true)['username']));
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
}
