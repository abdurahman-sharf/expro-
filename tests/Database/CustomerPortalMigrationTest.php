<?php

namespace Tests\Database;

use App\Database\Migrations\CustomerPortal as CustomerPortalMigration;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\PortalFixtureTrait;

/**
 * The tables, the permission and the setting of the portal: additive, safe to run again, the permission
 * registered and granted to nobody, the setting added off, one account for each customer and one customer for
 * each user name, and no row of the books touched.
 *
 * @internal
 */
final class CustomerPortalMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use PortalFixtureTrait;

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
        (new CustomerPortalMigration())->up();
        $this->cleanPortal();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->tableExists($this->accountingDb()->prefixTable($table), false);
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        return array_column($this->accountingDb()->query('SELECT COLUMN_NAME AS name FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$this->accountingDb()->prefixTable($table)])->getResultArray(), 'name');
    }

    /**
     * @return array<string, mixed>
     */
    private function account(int $customerId, string $username = 'ahmed'): array
    {
        return ['customer_id' => $customerId, 'username' => $username, 'password_hash' => password_hash('x', PASSWORD_DEFAULT)];
    }

    public function testUp_CreatesTheTablesWithTheColumnsOfAnAccountAndOfTheLedgerOfFailures(): void
    {
        $this->assertSame(['account_id', 'customer_id', 'username', 'password_hash', 'must_change', 'temporary_expires_at', 'credential_version', 'status', 'last_login_at', 'password_changed_at', 'created_by', 'created_at', 'updated_at'], $this->columns('portal_accounts'));
        $this->assertSame(['failure_key', 'failures', 'lockouts', 'locked_until', 'last_failure_at'], $this->columns('portal_login_failures'));
        $this->assertSame(0, $this->countRows('portal_accounts'));
    }

    public function testUp_RegistersThePermissionUnderTheCustomersModuleAndGrantsItToNobody(): void
    {
        $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::PORTAL_MANAGE)->delete();
        $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::PORTAL_MANAGE)->delete();

        (new CustomerPortalMigration())->up();

        $row = $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::PORTAL_MANAGE)->get()->getRow();

        $this->assertSame('customers', $row->module_id);
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::PORTAL_MANAGE)->countAllResults());
    }

    public function testUp_AddsThePortalSettingOffAndDoesNotSwitchItOffAgainWhenRunAfterItWasOn(): void
    {
        $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->delete();

        (new CustomerPortalMigration())->up();

        $this->assertSame('0', $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->get()->getRow()->value);

        $this->switchPortal(true);
        (new CustomerPortalMigration())->up();

        $this->assertSame('1', $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->get()->getRow()->value);
    }

    public function testUp_CanRunAgainAndKeepsTheAccountsAndTheGrantsThatAreThere(): void
    {
        $holder   = $this->employeeWithGrants([AccountingGuard::PORTAL_MANAGE]);
        $customer = $this->insertCustomer();
        $this->accountingDb()->table('portal_accounts')->insert($this->account($customer));

        (new CustomerPortalMigration())->up();
        (new CustomerPortalMigration())->up();

        $this->assertSame(1, $this->countRows('portal_accounts'));
        $this->assertSame(1, $this->accountingDb()->table('grants')->where(['permission_id' => AccountingGuard::PORTAL_MANAGE, 'person_id' => $holder])->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::PORTAL_MANAGE)->countAllResults());
    }

    public function testUp_AllowsOneAccountForEachCustomerAndOneCustomerForEachUserName(): void
    {
        $first  = $this->insertCustomer();
        $second = $this->insertCustomer('Hind', 'Saad');
        $this->accountingDb()->table('portal_accounts')->insert($this->account($first));

        try {
            $this->accountingDb()->table('portal_accounts')->insert($this->account($first, 'another'));
            $this->fail('A customer got a second account.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('portal_accounts')->insert($this->account($second));
    }

    public function testUp_RejectsAnAccountForAPartyThatIsNotACustomerAndAStatusOutsideTheTwo(): void
    {
        try {
            $this->accountingDb()->table('portal_accounts')->insert($this->account(987654));
            $this->fail('An account was made for nobody.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

        $customer = $this->insertCustomer();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('portal_accounts')->insert($this->account($customer) + ['status' => 'suspended']);
    }

    public function testUp_KeepsACustomerThatHasAnAccountFromBeingRemovedFromUnderIt(): void
    {
        $customer = $this->insertCustomer();
        $this->accountingDb()->table('portal_accounts')->insert($this->account($customer));

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('customers')->where('person_id', $customer)->delete();
    }

    public function testUp_ChangesNoRowOfTheBooksOrOfTheParties(): void
    {
        $db       = $this->accountingDb();
        $tables   = array_map(static fn (string $table): string => $db->prefixTable($table), ['accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'customers', 'people', 'sales', 'sales_payments']);
        $checksum = static fn (): array => array_column($db->query('CHECKSUM TABLE `' . implode('`, `', $tables) . '`')->getResultArray(), 'Checksum', 'Table');
        $before   = $checksum();

        (new CustomerPortalMigration())->up();

        $this->assertSame($before, $checksum());
    }

    public function testDown_RefusesWhileAccountsExistAndRemovesTablesPermissionAndSettingWhenNone(): void
    {
        $customer = $this->insertCustomer();
        $this->accountingDb()->table('portal_accounts')->insert($this->account($customer));

        try {
            (new CustomerPortalMigration())->down();
            $this->fail('The migration was rolled back over portal accounts.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('accounts', $refusal->getMessage());
        }

        $this->assertTrue($this->hasTable('portal_accounts'));

        $this->accountingDb()->query('DELETE FROM ' . $this->accountingDb()->prefixTable('portal_accounts'));
        (new CustomerPortalMigration())->down();

        $this->assertFalse($this->hasTable('portal_accounts'));
        $this->assertFalse($this->hasTable('portal_login_failures'));
        $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::PORTAL_MANAGE)->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('app_config')->where('key', 'portal_enabled')->countAllResults());

        (new CustomerPortalMigration())->up();

        $this->assertTrue($this->hasTable('portal_accounts'));
    }
}
