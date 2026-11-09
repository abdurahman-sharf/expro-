<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalLoginGuard;
use App\Libraries\Portal\PortalPasswords;
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
final class PortalAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use PortalFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $manager;
    private int $customer;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->manager  = $this->portalManager();
        $this->customer = $this->insertCustomer();
    }

    protected function tearDown(): void
    {
        $this->cleanPortal();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function accounts(): PortalAccounts
    {
        return new PortalAccounts();
    }

    private function refusalOf(callable $action): ?AccountingError
    {
        try {
            $action();
        } catch (AccountingException $failure) {
            return $failure->error;
        }

        return null;
    }

    private function row(int $accountId): object
    {
        return $this->accountingDb()->table('portal_accounts')->where('account_id', $accountId)->get()->getRow();
    }

    public function testCreate_StoresTheHashOfTheTemporaryPasswordAndNeverThePasswordItself(): void
    {
        $made = $this->accounts()->create($this->customer, 'Ahmed', $this->manager);
        $row  = $this->row($made['account_id']);

        $this->assertSame('ahmed', $row->username);
        $this->assertNotSame($made['temporary_password'], $row->password_hash);
        $this->assertTrue(PortalPasswords::verify($made['temporary_password'], $row->password_hash));
        $this->assertSame(password_get_info(password_hash('x', PASSWORD_DEFAULT))['algoName'], password_get_info($row->password_hash)['algoName']);
        $this->assertSame('1', (string) $row->must_change);
        $this->assertSame('active', $row->status);
        $this->assertNotNull($row->temporary_expires_at);
    }

    public function testCreate_LeavesTheTemporaryPasswordInNoRowOfTheDatabase(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        $this->assertStringNotContainsString($made['temporary_password'], json_encode($this->row($made['account_id'])));
        $this->assertStringNotContainsString($made['temporary_password'], json_encode($this->accountingDb()->table('accounting_audit_log')->get()->getResultArray()));
    }

    public function testCreate_WritesOneAuditEntryWithTheCustomerAndTheUserNameButNoPassword(): void
    {
        $made  = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $audit = $this->portalAudit('portal_account_created');

        $this->assertCount(1, $audit);
        $this->assertSame([(string) $made['account_id'], (string) $this->manager, 'portal_account'], [(string) $audit[0]['entity_id'], (string) $audit[0]['employee_id'], $audit[0]['entity_type']]);
        $this->assertSame(['customer_id' => $this->customer, 'username' => 'ahmed'], json_decode($audit[0]['details'], true));
    }

    public function testCreate_RequiresThePermissionToManageThePortal(): void
    {
        $clerk = $this->employeeWithGrants(['customers']);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->accounts()->create($this->customer, 'ahmed', $clerk)));
        $this->assertSame(0, $this->countRows('portal_accounts'));
    }

    public function testCreate_RefusesTheSystemAsTheAuthor(): void
    {
        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->accounts()->create($this->customer, 'ahmed', null)));
        $this->assertSame(0, $this->countRows('portal_accounts'));
    }

    public function testCreate_IsNotGrantedToAnAdministratorByHavingEveryOtherPermission(): void
    {
        $holder = $this->employeeWithGrants(array_values(array_diff(AccountingGuard::SENSITIVE, [AccountingGuard::PORTAL_MANAGE])));

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->accounts()->create($this->customer, 'ahmed', $holder)));
    }

    #[DataProvider('provideCreate_RejectsAUserNameOutsideTheRules')]
    public function testCreate_RejectsAUserNameOutsideTheRules(string $username): void
    {
        $this->assertSame(AccountingError::PortalUsernameInvalid, $this->refusalOf(fn () => $this->accounts()->create($this->customer, $username, $this->manager)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideCreate_RejectsAUserNameOutsideTheRules(): iterable
    {
        return ['two characters' => ['ab'], 'a space' => ['ah med'], 'a slash' => ['ahmed/admin'], 'markup' => ['<b>ahmed</b>'], 'fifty-one characters' => [str_repeat('a', 51)], 'arabic letters' => ['أحمد'], 'empty' => ['']];
    }

    public function testCreate_AcceptsAUserNameOfThreeAndOfFiftyCharactersWithTheAllowedSymbols(): void
    {
        $this->assertGreaterThan(0, $this->accounts()->create($this->customer, 'a.b', $this->manager)['account_id']);

        $other = $this->insertCustomer('Hind', 'Saad');

        $this->assertGreaterThan(0, $this->accounts()->create($other, str_repeat('a', 46) . '@x-y', $this->manager)['account_id']);
    }

    public function testCreate_RejectsASecondAccountForTheSameCustomerAndAUserNameAnotherAccountHas(): void
    {
        $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $other = $this->insertCustomer('Hind', 'Saad');

        $this->assertSame(AccountingError::PortalAccountExists, $this->refusalOf(fn () => $this->accounts()->create($this->customer, 'another', $this->manager)));
        $this->assertSame(AccountingError::PortalUsernameTaken, $this->refusalOf(fn () => $this->accounts()->create($other, 'AHMED', $this->manager)));
        $this->assertSame(1, $this->countRows('portal_accounts'));
    }

    public function testCreate_RejectsACustomerThatDoesNotExistOrWasDeleted(): void
    {
        $this->assertSame(AccountingError::PortalCustomerUnknown, $this->refusalOf(fn () => $this->accounts()->create(987654, 'ahmed', $this->manager)));

        $this->accountingDb()->table('customers')->where('person_id', $this->customer)->update(['deleted' => 1]);

        $this->assertSame(AccountingError::PortalCustomerUnknown, $this->refusalOf(fn () => $this->accounts()->create($this->customer, 'ahmed', $this->manager)));
    }

    public function testFindByUsername_FindsTheAccountWhateverTheSpacesAroundTheNameAndItsCase(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        foreach (['ahmed', 'AHMED', '  Ahmed', "\tahmed  "] as $typed) {
            $this->assertSame($made['account_id'], (int) $this->accounts()->findByUsername($typed)?->account_id, $typed);
        }

        $this->assertNull($this->accounts()->findByUsername('ahmed2'));
        $this->assertNull($this->accounts()->findByUsername(''));
    }

    public function testCheck_OpensTheAccountWithTheRightPasswordAndSaysWhyItDoesNotOtherwise(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        $this->assertSame($made['account_id'], (int) $this->accounts()->check('AHMED', $made['temporary_password'])[0]->account_id);
        $this->assertSame([null, 'wrong_password'], $this->accounts()->check('ahmed', 'not the password'));
        $this->assertSame([null, 'unknown_user'], $this->accounts()->check('nobody', $made['temporary_password']));
    }

    public function testCheck_RefusesADisabledAccountAndTheAccountOfADeletedCustomer(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        $this->accounts()->setActive($made['account_id'], false, $this->manager);
        $this->assertSame([null, 'disabled'], $this->accounts()->check('ahmed', $made['temporary_password']));

        $this->accounts()->setActive($made['account_id'], true, $this->manager);
        $this->accountingDb()->table('customers')->where('person_id', $this->customer)->update(['deleted' => 1]);
        $this->assertSame([null, 'customer_deleted'], $this->accounts()->check('ahmed', $made['temporary_password']));
    }

    public function testCheck_RefusesATemporaryPasswordAfterItsWeekAndAcceptsItBefore(): void
    {
        $made    = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $expires = strtotime((string) $this->row($made['account_id'])->temporary_expires_at);

        $this->assertNotNull($this->accounts()->check('ahmed', $made['temporary_password'], $expires - 1)[0]);
        $this->assertSame([null, 'temporary_expired'], $this->accounts()->check('ahmed', $made['temporary_password'], $expires + 1));
        $this->assertEqualsWithDelta(PortalAccounts::TEMPORARY_SECONDS, $expires - time(), 5);
    }

    public function testResetPassword_MakesANewTemporaryPasswordAndEndsTheOldOneAndTheSessions(): void
    {
        $made    = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $version = (int) $this->row($made['account_id'])->credential_version;

        $new = $this->accounts()->resetPassword($made['account_id'], $this->manager);
        $row = $this->row($made['account_id']);

        $this->assertNotSame($made['temporary_password'], $new);
        $this->assertSame([null, 'wrong_password'], $this->accounts()->check('ahmed', $made['temporary_password']));
        $this->assertNotNull($this->accounts()->check('ahmed', $new)[0]);
        $this->assertSame($version + 1, (int) $row->credential_version);
        $this->assertSame('1', (string) $row->must_change);
        $this->assertNull($this->accounts()->live($made['account_id'], $version));
        $this->assertStringNotContainsString($new, json_encode($this->accountingDb()->table('accounting_audit_log')->get()->getResultArray()));
        $this->assertCount(1, $this->portalAudit('portal_password_reset'));
    }

    public function testResetPassword_LiftsTheLockOnTheUserName(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $key  = PortalLoginGuard::userKey('ahmed');

        for ($index = 0; $index < PortalLoginGuard::USER_THRESHOLD; $index++) {
            (new PortalLoginGuard())->recordFailure($key, PortalLoginGuard::USER_THRESHOLD);
        }

        $this->assertNotNull((new PortalLoginGuard())->lockedUntil($key));

        $this->accounts()->resetPassword($made['account_id'], $this->manager);

        $this->assertNull((new PortalLoginGuard())->lockedUntil($key));
    }

    public function testResetPassword_RequiresThePermissionAndAnAccountThatExists(): void
    {
        $made  = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $clerk = $this->employeeWithGrants(['customers']);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->accounts()->resetPassword($made['account_id'], $clerk)));
        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->accounts()->resetPassword($made['account_id'], null)));
        $this->assertSame(AccountingError::PortalAccountUnknown, $this->refusalOf(fn () => $this->accounts()->resetPassword(987654, $this->manager)));
        $this->assertCount(0, $this->portalAudit('portal_password_reset'));
    }

    public function testSetActive_DisablesAndEnablesAndEndsTheSessionsWhenItDisables(): void
    {
        $made    = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $version = (int) $this->row($made['account_id'])->credential_version;

        $this->accounts()->setActive($made['account_id'], false, $this->manager);

        $this->assertSame('disabled', $this->row($made['account_id'])->status);
        $this->assertSame($version + 1, (int) $this->row($made['account_id'])->credential_version);
        $this->assertNull($this->accounts()->live($made['account_id'], $version + 1));

        $this->accounts()->setActive($made['account_id'], true, $this->manager);

        $this->assertSame('active', $this->row($made['account_id'])->status);
        $this->assertCount(1, $this->portalAudit('portal_account_disabled'));
        $this->assertCount(1, $this->portalAudit('portal_account_enabled'));
    }

    public function testSetActive_WritesNothingWhenTheStatusIsAlreadyTheOneAsked(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        $this->accounts()->setActive($made['account_id'], true, $this->manager);

        $this->assertCount(0, $this->portalAudit('portal_account_enabled'));
        $this->assertSame('1', (string) $this->row($made['account_id'])->credential_version);
    }

    public function testSetActive_RequiresThePermission(): void
    {
        $made  = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $clerk = $this->employeeWithGrants(['customers']);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusalOf(fn () => $this->accounts()->setActive($made['account_id'], false, $clerk)));
        $this->assertSame('active', $this->row($made['account_id'])->status);
    }

    public function testChangePassword_StoresTheNewHashClearsTheForcedChangeAndEndsTheOtherSessions(): void
    {
        $made    = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $version = $this->accounts()->changePassword($made['account_id'], $made['temporary_password'], 'A-New-Password-7');
        $row     = $this->row($made['account_id']);

        $this->assertSame(2, $version);
        $this->assertSame(2, (int) $row->credential_version);
        $this->assertSame('0', (string) $row->must_change);
        $this->assertNull($row->temporary_expires_at);
        $this->assertNotNull($row->password_changed_at);
        $this->assertTrue(PortalPasswords::verify('A-New-Password-7', $row->password_hash));
        $this->assertStringNotContainsString('A-New-Password-7', $row->password_hash);
        $this->assertSame([null, 'wrong_password'], $this->accounts()->check('ahmed', $made['temporary_password']));
        $this->assertStringNotContainsString('A-New-Password-7', json_encode($this->accountingDb()->table('accounting_audit_log')->get()->getResultArray()));
        $this->assertCount(1, $this->portalAudit('portal_password_changed'));
    }

    public function testChangePassword_RejectsAWrongCurrentPasswordAndAWeakNewOne(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        $this->assertSame(AccountingError::PortalPasswordWrong, $this->refusalOf(fn () => $this->accounts()->changePassword($made['account_id'], 'not it', 'A-New-Password-7')));
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf(fn () => $this->accounts()->changePassword($made['account_id'], $made['temporary_password'], 'short')));
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf(fn () => $this->accounts()->changePassword($made['account_id'], $made['temporary_password'], 'ahmed')));
        $this->assertSame(AccountingError::PortalPasswordInvalid, $this->refusalOf(fn () => $this->accounts()->changePassword($made['account_id'], $made['temporary_password'], $made['temporary_password'])));
        $this->assertSame('1', (string) $this->row($made['account_id'])->must_change);
    }

    public function testLive_AcceptsTheVersionOfTheSessionAndRejectsAnotherOrADisabledAccountOrADeletedCustomer(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        $this->assertNotNull($this->accounts()->live($made['account_id'], 1));
        $this->assertNull($this->accounts()->live($made['account_id'], 2));
        $this->assertNull($this->accounts()->live(987654, 1));

        $this->accountingDb()->table('portal_accounts')->where('account_id', $made['account_id'])->update(['status' => 'disabled']);
        $this->assertNull($this->accounts()->live($made['account_id'], 1), 'A disabled account has no live session even at the version of the session');
        $this->accountingDb()->table('portal_accounts')->where('account_id', $made['account_id'])->update(['status' => 'active']);

        $this->accountingDb()->table('customers')->where('person_id', $this->customer)->update(['deleted' => 1]);

        $this->assertNull($this->accounts()->live($made['account_id'], 1));
    }

    public function testCloseForCustomer_DisablesTheAccountEndsItsSessionsAndRecordsIt(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);

        $this->accounts()->closeForCustomer($this->customer, false, $this->manager);

        $row = $this->row($made['account_id']);
        $this->assertSame(['disabled', 'ahmed', '2'], [$row->status, $row->username, (string) $row->credential_version]);
        $this->assertCount(1, $this->portalAudit('portal_account_disabled'));
    }

    public function testCloseForCustomer_ScramblesTheUserNameWhenTheShopScramblesDeletedCustomers(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed@example.com', $this->manager);

        $this->accounts()->closeForCustomer($this->customer, true, $this->manager);

        $this->assertSame('deleted-' . $made['account_id'], $this->row($made['account_id'])->username);
    }

    public function testCloseForCustomer_DoesNothingForACustomerWithoutAnAccount(): void
    {
        $this->accounts()->closeForCustomer($this->customer, false, $this->manager);

        $this->assertCount(0, $this->portalAudit('portal_account_disabled'));
    }

    public function testRecordLogin_StoresTheTimeAndUpgradesAnOldHash(): void
    {
        $made = $this->accounts()->create($this->customer, 'ahmed', $this->manager);
        $this->accountingDb()->table('portal_accounts')->where('account_id', $made['account_id'])->update(['password_hash' => password_hash('Correct-Horse-9', PASSWORD_BCRYPT, ['cost' => 4])]);

        $this->accounts()->recordLogin($this->row($made['account_id']), 'Correct-Horse-9');
        $row = $this->row($made['account_id']);

        $this->assertNotNull($row->last_login_at);
        $this->assertFalse(PortalPasswords::needsRehash($row->password_hash));
        $this->assertTrue(PortalPasswords::verify('Correct-Horse-9', $row->password_hash));
    }
}
