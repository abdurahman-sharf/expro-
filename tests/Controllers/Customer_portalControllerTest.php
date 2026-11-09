<?php

namespace Tests\Controllers;

use App\Libraries\Portal\PortalAccounts;
use App\Libraries\Portal\PortalPasswords;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\PortalFixtureTrait;

/**
 * What an employee does with the portal of a customer: only with the permission to manage it, with the
 * temporary password shown once in the answer and nowhere else, and without anything sent to the customer.
 *
 * @internal
 */
final class Customer_portalControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use PortalFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $customer;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = $this->insertCustomer();
    }

    protected function tearDown(): void
    {
        $this->cleanPortal();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function actAs(int $employeeId): void
    {
        $this->withSession(['person_id' => $employeeId, 'menu_group' => 'office']);
    }

    /**
     * @param array<string, string> $form
     *
     * @return array<string, mixed>
     */
    private function post_(string $path, array $form = []): array
    {
        return json_decode($this->post('/customer_portal/' . $path, $form)->getJSON(), true);
    }

    public function testPostCreateMakesTheAccountAndShowsTheTemporaryPasswordOnceWithoutKeepingIt(): void
    {
        $this->actAs($this->portalManager());

        $response = $this->post('/customer_portal/create/' . $this->customer, ['username' => 'Ahmed']);
        $answer   = json_decode($response->getJSON(), true);

        $this->assertTrue($answer['success']);
        $this->assertSame(PortalPasswords::TEMPORARY_LENGTH, strlen($answer['temporary_password']));
        $this->assertStringContainsString('no-store', $response->response()->getHeaderLine('Cache-Control'));
        $account = (new PortalAccounts())->forCustomer($this->customer);
        $this->assertSame('ahmed', $account->username);
        $this->assertTrue(PortalPasswords::verify($answer['temporary_password'], $account->password_hash));
        $this->assertStringNotContainsString($answer['temporary_password'], json_encode($this->accountingDb()->table('portal_accounts')->get()->getResultArray()));
        $this->assertStringNotContainsString($answer['temporary_password'], json_encode($this->accountingDb()->table('accounting_audit_log')->get()->getResultArray()));
    }

    public function testPostCreateIsRefusedForAnEmployeeWithoutThePermissionAndMakesNothing(): void
    {
        $this->actAs($this->employeeWithGrants(['customers']));

        $response = $this->post('/customer_portal/create/' . $this->customer, ['username' => 'ahmed']);

        $this->assertSame(403, $response->response()->getStatusCode());
        $this->assertFalse(json_decode($response->getJSON(), true)['success']);
        $this->assertSame(0, $this->countRows('portal_accounts'));
    }

    public function testPostCreateIsRefusedForAnEmployeeWithNoRightToTheCustomers(): void
    {
        $this->actAs($this->employeeWithGrants([]));

        $response = $this->post('/customer_portal/create/' . $this->customer, ['username' => 'ahmed']);

        $this->assertStringContainsString('no_access/customers', (string) $response->getRedirectUrl());
        $this->assertSame(0, $this->countRows('portal_accounts'));
    }

    public function testPostCreateSaysWhyWhenTheUserNameIsBadTheCustomerHasAnAccountOrTheNameIsTaken(): void
    {
        $this->actAs($this->portalManager());
        $other = $this->insertCustomer('Hind', 'Saad');

        $this->assertFalse($this->post_('create/' . $this->customer, ['username' => 'a b'])['success']);
        $this->assertTrue($this->post_('create/' . $this->customer, ['username' => 'ahmed'])['success']);
        $this->assertFalse($this->post_('create/' . $this->customer, ['username' => 'another'])['success']);
        $this->assertFalse($this->post_('create/' . $other, ['username' => 'AHMED'])['success']);
        $this->assertSame(1, $this->countRows('portal_accounts'));
    }

    public function testPostCreateIsRefusedForACustomerThatWasDeleted(): void
    {
        $this->actAs($this->portalManager());
        $this->accountingDb()->table('customers')->where('person_id', $this->customer)->update(['deleted' => 1]);

        $this->assertFalse($this->post_('create/' . $this->customer, ['username' => 'ahmed'])['success']);
        $this->assertSame(0, $this->countRows('portal_accounts'));
    }

    public function testPostResetGivesANewTemporaryPasswordThatMustBeChangedAndEndsTheOldOne(): void
    {
        $manager = $this->portalManager();
        $account = $this->portalAccountOf($this->customer);
        $this->actAs($manager);

        $answer = $this->post_('reset/' . $account['account_id']);

        $this->assertTrue($answer['success']);
        $this->assertSame([null, 'wrong_password'], (new PortalAccounts())->check('ahmed', self::PORTAL_PASSWORD));
        $this->assertNotNull((new PortalAccounts())->check('ahmed', $answer['temporary_password'])[0]);
        $this->assertSame('1', (string) (new PortalAccounts())->find($account['account_id'])->must_change);
    }

    public function testPostResetIsRefusedWithoutThePermission(): void
    {
        $account = $this->portalAccountOf($this->customer);
        $this->actAs($this->employeeWithGrants(['customers']));

        $response = $this->post('/customer_portal/reset/' . $account['account_id']);

        $this->assertSame(403, $response->response()->getStatusCode());
        $this->assertNotNull((new PortalAccounts())->check('ahmed', self::PORTAL_PASSWORD)[0]);
    }

    public function testPostResetSaysTheAccountDoesNotExistForANumberThatIsNoAccount(): void
    {
        $this->actAs($this->portalManager());

        $this->assertFalse($this->post_('reset/987654')['success']);
    }

    public function testPostStatusDisablesAndEnablesTheAccount(): void
    {
        $account = $this->portalAccountOf($this->customer);
        $this->actAs($this->portalManager());

        $this->assertTrue($this->post_('status/' . $account['account_id'], ['active' => '0'])['success']);
        $this->assertSame('disabled', (new PortalAccounts())->find($account['account_id'])->status);

        $this->assertTrue($this->post_('status/' . $account['account_id'], ['active' => '1'])['success']);
        $this->assertSame('active', (new PortalAccounts())->find($account['account_id'])->status);
    }

    public function testPostStatusIsRefusedWithoutThePermission(): void
    {
        $account = $this->portalAccountOf($this->customer);
        $this->actAs($this->employeeWithGrants(['customers']));

        $this->assertSame(403, $this->post('/customer_portal/status/' . $account['account_id'], ['active' => '0'])->response()->getStatusCode());
        $this->assertSame('active', (new PortalAccounts())->find($account['account_id'])->status);
    }

    public function testPostEnabledSwitchesThePortalOnAndOff(): void
    {
        $this->actAs($this->portalManager());

        $this->assertTrue($this->post_('enabled', ['enabled' => '1'])['success']);
        $this->assertSame('1', $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->get()->getRow()->value);

        $this->assertTrue($this->post_('enabled', ['enabled' => '0'])['success']);
        $this->assertSame('0', $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->get()->getRow()->value);
    }

    public function testPostEnabledIsRefusedWithoutThePermissionAndLeavesThePortalOff(): void
    {
        $this->actAs($this->employeeWithGrants(['customers', 'config']));

        $this->assertSame(403, $this->post('/customer_portal/enabled', ['enabled' => '1'])->response()->getStatusCode());
        $this->assertSame('0', $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->get()->getRow()->value);
    }

    public function testPostEnabledTreatsAnythingButOneAsOff(): void
    {
        $this->actAs($this->portalManager());
        $this->switchPortal(true);

        $this->post_('enabled', ['enabled' => 'true']);

        $this->assertSame('0', $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->get()->getRow()->value);
    }

    public function testEveryActionChangesNoJournalAndNoBalanceAndSendsNoMessage(): void
    {
        $manager  = $this->portalManager();
        $db       = $this->accountingDb();
        $tables   = array_map(static fn (string $table): string => $db->prefixTable($table), ['accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'sales', 'sales_payments', 'customer_credit_applications']);
        $checksum = static fn (): array => array_column($db->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->getResultArray(), 'Checksum', 'Table');
        $before   = $checksum();
        $this->actAs($manager);

        $made = $this->post_('create/' . $this->customer, ['username' => 'ahmed']);
        $this->post_('reset/' . $made['account_id']);
        $this->post_('status/' . $made['account_id'], ['active' => '0']);
        $this->post_('enabled', ['enabled' => '1']);

        $this->assertSame($before, $checksum());
        $this->assertSame(0, $db->tableExists($db->prefixTable('outbound_messages'), false) ? $this->countRows('outbound_messages') : 0);
    }

    public function testEveryActionIsWrittenToTheAuditLogWithoutAnyPassword(): void
    {
        $this->actAs($this->portalManager());

        $made  = $this->post_('create/' . $this->customer, ['username' => 'ahmed']);
        $reset = $this->post_('reset/' . $made['account_id']);
        $this->post_('status/' . $made['account_id'], ['active' => '0']);
        $this->post_('status/' . $made['account_id'], ['active' => '1']);
        $this->post_('enabled', ['enabled' => '1']);

        $log = json_encode($this->accountingDb()->table('accounting_audit_log')->get()->getResultArray());

        foreach (['portal_account_created', 'portal_password_reset', 'portal_account_disabled', 'portal_account_enabled', 'portal_setting_changed'] as $action) {
            $this->assertCount(1, $this->portalAudit($action), $action);
        }

        $this->assertStringNotContainsString($made['temporary_password'], $log);
        $this->assertStringNotContainsString($reset['temporary_password'], $log);
    }
}
