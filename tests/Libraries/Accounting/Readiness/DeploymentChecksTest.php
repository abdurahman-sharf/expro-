<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\DeploymentChecks;
use App\Libraries\Numbering\DocumentType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\InstallerPasswordFixtureTrait;

/**
 * What a new installation still holds from the installer: the published password of the account it
 * creates, and the texts, formats and switches that print on every document until they are changed.
 */
class DeploymentChecksTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use DatabaseFingerprintTrait;
    use InstallerPasswordFixtureTrait;

    private const FIRST_RUN_KEYS = ['company', 'address', 'phone', 'email', 'return_policy', 'invoice_default_comments', 'quote_default_comments', 'currency_symbol', 'dateformat', 'timezone', 'tax_id'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private DeploymentChecks $checks;

    /** @var array<string, string|null> */
    private array $settings = [];

    /** @var list<int> */
    private array $employeeIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->checks = new DeploymentChecks($this->accountingDb());

        foreach (self::FIRST_RUN_KEYS as $key) {
            $row                  = $this->accountingDb()->table('app_config')->where('key', $key)->get()->getRow();
            $this->settings[$key] = $row === null ? null : (string) $row->value;
        }

        foreach (['company' => 'Open Source Point of Sale', 'address' => '123 Nowhere street', 'phone' => '555-555-5555', 'email' => 'changeme@example.com', 'return_policy' => 'Test', 'invoice_default_comments' => 'This is a default comment', 'quote_default_comments' => 'This is a default quote comment', 'currency_symbol' => '$', 'dateformat' => 'm/d/Y', 'timezone' => 'America/New_York', 'tax_id' => ''] as $key => $value) {
            $this->setConfigValue($key, $value);
        }

        $this->accountingDb()->table('document_sequences')->truncate();
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();

        $this->restoreInstallerPassword($db);

        foreach ($this->settings as $key => $value) {
            $value === null ? $db->table('app_config')->where('key', $key)->delete() : $this->setConfigValue($key, $value);
        }

        foreach ($this->employeeIds as $personId) {
            $db->table('employees')->where('person_id', $personId)->delete();
            $db->table('people')->where('person_id', $personId)->delete();
        }

        $this->employeeIds = [];
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    private function check(string $id): CheckResult
    {
        foreach ($this->checks->run() as $result) {
            if ($result->id === $id) {
                return $result;
            }
        }

        $this->fail("No check {$id}.");
    }

    /**
     * @param array<string, mixed> $employee
     */
    private function account(string $username, array $employee = []): int
    {
        $personId            = $this->createEmployee(username: $username, employee: $employee);
        $this->employeeIds[] = $personId;

        return $personId;
    }

    private function switchNumberingOn(DocumentType $type): void
    {
        $this->accountingDb()->table('document_sequences')->insert(['company_id' => 1, 'doc_type' => $type->value, 'enabled' => 1, 'prefix' => 'N-', 'padding' => 4, 'next_number' => 1, 'prevent_duplicates' => 1]);
    }

    private function settleEverything(): void
    {
        foreach (['company' => 'Gulf Trading', 'address' => 'Riyadh', 'phone' => '0110000000', 'email' => 'owner@gulf.example', 'return_policy' => 'Exchange within 7 days', 'invoice_default_comments' => 'Thank you', 'quote_default_comments' => 'Valid for 15 days', 'currency_symbol' => 'SAR', 'dateformat' => 'd/m/Y', 'timezone' => 'Asia/Riyadh', 'tax_id' => '300000000000003'] as $key => $value) {
            $this->setConfigValue($key, $value);
        }

        foreach ([DocumentType::SalesInvoice, DocumentType::CreditNote, DocumentType::RefundInvoice, DocumentType::SalesQuote, DocumentType::Purchase] as $type) {
            $this->switchNumberingOn($type);
        }
    }

    public function testRun_GivesTheTwoChecksWithTheirReferences(): void
    {
        $results = $this->checks->run();

        $this->assertSame(['admin_default_password', 'first_run_settings'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['ADMIN-DEFAULT-PASSWORD', 'FIRST-RUN-SETTINGS'], array_map(static fn (CheckResult $result): string => $result->ref, $results));
    }

    public function testAdminDefaultPassword_FailsAndNamesTheAccountThatStillHasTheInstallerPassword(): void
    {
        $this->accountingDb()->table('employees')->where('person_id', 1)->update(['password' => $this->installerHash(), 'hash_version' => 2]);

        $result = $this->check('admin_default_password');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['the account admin still has the installer password'], $result->findings);
        $this->assertSame(1, $result->data['with_installer_password']);
    }

    public function testAdminDefaultPassword_FailsForAnAccountWhosePasswordWasSetAgainToTheInstallerOne(): void
    {
        $this->changeInstallerPassword($this->accountingDb());
        $this->account('cashier_again', ['password' => password_hash(DeploymentChecks::INSTALLER_PASSWORD, PASSWORD_DEFAULT)]);

        $result = $this->check('admin_default_password');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['the account cashier_again still has the installer password'], $result->findings);
    }

    public function testAdminDefaultPassword_FailsForAnAccountWithTheOldHashOfTheInstallerPassword(): void
    {
        $this->changeInstallerPassword($this->accountingDb());
        $this->account('cashier_old', ['password' => md5(DeploymentChecks::INSTALLER_PASSWORD), 'hash_version' => 1]);

        $result = $this->check('admin_default_password');

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertSame(['the account cashier_old still has the installer password'], $result->findings);
    }

    public function testAdminDefaultPassword_ListsEveryAccountThatStillHasIt(): void
    {
        $this->account('cashier_one', ['password' => password_hash(DeploymentChecks::INSTALLER_PASSWORD, PASSWORD_DEFAULT)]);
        $this->account('cashier_two', ['password' => password_hash(DeploymentChecks::INSTALLER_PASSWORD, PASSWORD_DEFAULT)]);

        $result = $this->check('admin_default_password');

        $this->assertSame(['the account admin still has the installer password', 'the account cashier_one still has the installer password', 'the account cashier_two still has the installer password'], $result->findings);
        $this->assertSame(3, $result->data['with_installer_password']);
    }

    public function testAdminDefaultPassword_PassesWhenEveryAccountChangedItsPassword(): void
    {
        $this->changeInstallerPassword($this->accountingDb());
        $this->account('cashier_changed');

        $result = $this->check('admin_default_password');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame(0, $result->data['with_installer_password']);
    }

    public function testAdminDefaultPassword_LeavesADeletedAccountAlone(): void
    {
        $this->changeInstallerPassword($this->accountingDb());
        $this->account('cashier_gone', ['password' => password_hash(DeploymentChecks::INSTALLER_PASSWORD, PASSWORD_DEFAULT), 'deleted' => 1]);

        $this->assertSame(CheckStatus::Pass, $this->check('admin_default_password')->status);
    }

    public function testAdminDefaultPassword_NeverPrintsThePasswordAndWritesNothing(): void
    {
        $before = $this->databaseFingerprint();

        $result = $this->check('admin_default_password');

        $this->assertDatabaseUnchanged($before);
        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertStringNotContainsString(DeploymentChecks::INSTALLER_PASSWORD, (string) json_encode($result->toArray()));
        $this->assertStringNotContainsString($this->installerHash(), (string) json_encode($result->toArray()));
    }

    public function testInstallerHash_VerifiesAgainstThePasswordTheCheckLooksFor(): void
    {
        $this->assertTrue(password_verify(DeploymentChecks::INSTALLER_PASSWORD, $this->installerHash()));
    }

    public function testFirstRunSettings_ReviewsEveryPlaceholderTheInstallerWrote(): void
    {
        $result = $this->check('first_run_settings');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertFalse($result->status->blocks());
        $this->assertSame(['company', 'address', 'phone', 'email', 'return_policy', 'invoice_default_comments', 'quote_default_comments', 'currency_symbol', 'dateformat', 'timezone'], $result->data['placeholders']);
        $this->assertContains('the company name (company) is still "Open Source Point of Sale"', $result->findings);
        $this->assertContains('the company address (address) is still "123 Nowhere street"', $result->findings);
        $this->assertContains('the return policy (return_policy) is still "Test"', $result->findings);
        $this->assertContains('the default comment of invoices (invoice_default_comments) is still "This is a default comment"', $result->findings);
        $this->assertContains('the currency symbol (currency_symbol) is still "$"', $result->findings);
        $this->assertContains('the date format (dateformat) is still "m/d/Y"', $result->findings);
        $this->assertTrue($result->data['tax_number_empty']);
        $this->assertSame(['sales_invoice', 'credit_note', 'refund_invoice', 'sales_quote', 'purchase'], $result->data['numbering_off']);
    }

    public function testFirstRunSettings_ListsOnlyTheSettingsThatAreStillThePlaceholders(): void
    {
        $this->settleEverything();
        $this->setConfigValue('company', 'Open Source Point of Sale');
        $this->setConfigValue('tax_id', '');

        $result = $this->check('first_run_settings');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['company'], $result->data['placeholders']);
        $this->assertTrue($result->data['tax_number_empty']);
        $this->assertSame([], $result->data['numbering_off']);
        $this->assertCount(2, $result->findings);
    }

    public function testFirstRunSettings_ReviewsANumberingThatIsSwitchedOffWhenTheTextsAreSet(): void
    {
        $this->settleEverything();
        $this->accountingDb()->table('document_sequences')->where('doc_type', 'sales_invoice')->update(['enabled' => 0]);

        $result = $this->check('first_run_settings');

        $this->assertSame(CheckStatus::Review, $result->status);
        $this->assertSame(['sales_invoice'], $result->data['numbering_off']);
        $this->assertStringContainsString('the first invoice prints 0', $result->findings[0]);
    }

    public function testFirstRunSettings_PassesOnceTheTextsTheTaxNumberAndTheNumberingAreSet(): void
    {
        $this->settleEverything();

        $result = $this->check('first_run_settings');

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->data['placeholders']);
        $this->assertSame([], $result->findings);
    }

    public function testFirstRunSettings_TreatsASettingThatIsNotThereAsNotAPlaceholder(): void
    {
        $this->settleEverything();
        $this->accountingDb()->table('app_config')->where('key', 'quote_default_comments')->delete();

        $this->assertSame(CheckStatus::Pass, $this->check('first_run_settings')->status);
    }

    public function testFirstRunSettings_WritesNothing(): void
    {
        $before = $this->databaseFingerprint();

        $this->check('first_run_settings');

        $this->assertDatabaseUnchanged($before);
    }
}
