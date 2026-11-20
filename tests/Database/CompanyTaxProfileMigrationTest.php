<?php

namespace Tests\Database;

use App\Database\Migrations\CompanyTaxProfile;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

class CompanyTaxProfileMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private string $savedTaxId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->savedTaxId = (string) (Database::connect()->table('app_config')->where('key', 'tax_id')->get()->getRow()->value ?? '');
    }

    protected function tearDown(): void
    {
        $db = Database::connect();

        $db->table('app_config')->replace(['key' => 'tax_id', 'value' => $this->savedTaxId]);
        (new CompanyTaxProfile())->down();
        (new CompanyTaxProfile())->up();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        $db = Database::connect();

        return $db->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$db->prefixTable($table)])->getRow()->found > 0;
    }

    private function rerun(string $taxId): object
    {
        $db = Database::connect();

        (new CompanyTaxProfile())->down();
        $db->table('app_config')->replace(['key' => 'tax_id', 'value' => $taxId]);
        (new CompanyTaxProfile())->up();

        return $db->table('company_tax_profile')->where('company_id', 1)->get()->getRow();
    }

    public function testUp_CopiesTheTaxIdAndLeavesTheModeOff(): void
    {
        $row = $this->rerun('399999999900003');

        $this->assertSame('399999999900003', $row->vat_number);
        $this->assertSame('0', (string) $row->ksa_mode);
        $this->assertNull($row->ksa_mode_effective_at);
    }

    public function testUp_LeavesRegistrationUnknown(): void
    {
        $this->assertNull($this->rerun('399999999900003')->vat_registered);
    }

    public function testUp_KeepsAnEmptyNumberEmpty(): void
    {
        $this->assertNull($this->rerun('')->vat_number);
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        $this->rerun('12345');

        Database::connect()->table('company_tax_profile')->where('company_id', 1)->update(['vat_registered' => 0]);

        (new CompanyTaxProfile())->up();

        $row = Database::connect()->table('company_tax_profile')->where('company_id', 1)->get()->getRow();

        $this->assertSame('0', (string) $row->vat_registered);
        $this->assertSame('12345', $row->vat_number);
    }

    public function testUp_RegistersTheOnboardPermissionWithoutGrantingIt(): void
    {
        $db = Database::connect();

        $this->assertSame(1, $db->table('permissions')->where(['permission_id' => AccountingGuard::EINVOICING_ONBOARD, 'module_id' => 'accounting_einvoicing'])->countAllResults());
        $this->assertSame(0, $db->table('grants')->where('permission_id', AccountingGuard::EINVOICING_ONBOARD)->countAllResults());
    }

    public function testDown_KeepsTheNumberInTheSettings(): void
    {
        $this->rerun('399999999900003');

        (new CompanyTaxProfile())->down();

        $this->assertFalse($this->hasTable('company_tax_profile'));
        $this->assertSame(0, Database::connect()->table('permissions')->where('permission_id', AccountingGuard::EINVOICING_ONBOARD)->countAllResults());
        $this->assertSame('399999999900003', (string) Database::connect()->table('app_config')->where('key', 'tax_id')->get()->getRow()->value);
    }
}
