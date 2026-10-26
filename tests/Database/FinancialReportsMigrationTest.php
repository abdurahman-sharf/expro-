<?php

namespace Tests\Database;

use App\Database\Migrations\FinancialReports;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class FinancialReportsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const MODULE_ID = 'accounting_reports';

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new FinancialReports())->up();

        parent::tearDown();
    }

    private function countRows(string $table): int
    {
        return $this->accountingDb()->table($table)->countAllResults();
    }

    public function testUp_RegistersTheScreenAndItsPermissionAfterTheOtherAccountingScreens(): void
    {
        $module = $this->accountingDb()->table('modules')->where('module_id', self::MODULE_ID)->get()->getRow();

        $this->assertNotNull($module);
        $this->assertSame(128, (int) $module->sort);
        $this->assertSame('module_accounting_reports', $module->name_lang_key);
        $this->assertSame('module_accounting_reports_desc', $module->desc_lang_key);

        $permission = $this->accountingDb()->table('permissions')->where('permission_id', self::MODULE_ID)->get()->getRow();

        $this->assertNotNull($permission);
        $this->assertSame(self::MODULE_ID, $permission->module_id);
        $this->assertSame(0, $this->accountingDb()->table('modules')->where('sort', 128)->where('module_id !=', self::MODULE_ID)->countAllResults());
    }

    public function testUp_GrantsNothingToAnyone(): void
    {
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', self::MODULE_ID)->countAllResults());
    }

    public function testUp_NamesTheModuleInEveryLanguageOfTheApplication(): void
    {
        foreach (glob(APPPATH . 'Language/*/Module.php') as $file) {
            $lines = require $file;

            $this->assertArrayHasKey('accounting_reports', $lines, $file);
            $this->assertArrayHasKey('accounting_reports_desc', $lines, $file);
        }
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        $permissions = $this->countRows('permissions');
        $modules     = $this->countRows('modules');
        $journals    = $this->countRows('accounting_journals');

        (new FinancialReports())->up();
        (new FinancialReports())->up();

        $this->assertSame($permissions, $this->countRows('permissions'));
        $this->assertSame($modules, $this->countRows('modules'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testDown_TakesTheScreenAndItsGrantsAway(): void
    {
        $employee  = $this->employeeWithGrants([self::MODULE_ID]);
        $migration = new FinancialReports();

        try {
            $migration->down();

            $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', self::MODULE_ID)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', self::MODULE_ID)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('grants')->where('person_id', $employee)->where('permission_id', self::MODULE_ID)->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertSame(1, $this->accountingDb()->table('modules')->where('module_id', self::MODULE_ID)->countAllResults());
    }
}
