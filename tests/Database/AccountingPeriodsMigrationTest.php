<?php

namespace Tests\Database;

use App\Database\Migrations\AccountingPeriods;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingPeriodsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const MODULES = ['accounting_settings', 'accounting_periods'];

    private const SUB_PERMISSIONS = [
        'acc_settings_manage',
        'acc_periods_manage',
        'acc_periods_close',
        'acc_periods_reopen',
        'acc_opening_manage',
    ];

    private const SENSITIVE = ['acc_settings_manage', 'acc_periods_close', 'acc_periods_reopen', 'acc_opening_manage'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function tableExists(string $table): bool
    {
        return $this->accountingDb()->tableExists($this->accountingDb()->prefixTable($table), false);
    }

    public function testUp_CreatesALockRowForTheCompanyAndEveryBranch(): void
    {
        $this->assertSame(1, $this->accountingDb()->table('accounting_period_locks')->where('company_id', 1)->where('branch_id', 0)->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_period_locks')->where('company_id', 1)->where('branch_id', 1)->countAllResults());
    }

    public function testUp_AddsTheReopeningColumnsToClosedPeriods(): void
    {
        foreach (['reopened_by', 'reopened_at', 'reopen_reason'] as $column) {
            $this->assertTrue($this->hasColumn('accounting_closed_periods', $column), $column);
        }
    }

    public function testUp_AddsADescriptionToFiscalYears(): void
    {
        $this->assertTrue($this->hasColumn('accounting_fiscal_years', 'description'));
    }

    public function testUp_RefusesTwoFiscalYearsStartingOnTheSameDayInOneCompany(): void
    {
        $this->reinstallChart();
        $db = $this->accountingDb();

        try {
            $db->table('accounting_fiscal_years')->insert(['company_id' => 1, 'name' => 'Duplicate', 'start_date' => '2030-01-01', 'end_date' => '2030-06-30']);
            $this->fail('A second year with the same start should have been refused.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }

    }

    public function testUp_CreatesTheOpeningDraftTableAndTheAutoYearSetting(): void
    {
        $this->assertTrue($this->tableExists('accounting_opening_drafts'));
        $this->assertSame('1', (string) $this->accountingDb()->table('app_config')->where('key', 'accounting_auto_fiscal_year')->get()->getRow()->value);
    }

    public function testUp_RegistersTheModulesAndPermissions(): void
    {
        $db = $this->accountingDb();

        foreach (self::MODULES as $moduleId) {
            $this->assertSame(1, $db->table('modules')->where('module_id', $moduleId)->countAllResults(), $moduleId);
        }

        foreach ([...self::MODULES, ...self::SUB_PERMISSIONS] as $permissionId) {
            $this->assertSame(1, $db->table('permissions')->where('permission_id', $permissionId)->countAllResults(), $permissionId);
        }
    }

    public function testUp_GivesHoldersOfTheConfigModuleTheScreensButNotTheSensitivePermissions(): void
    {
        $db     = $this->accountingDb();
        $holder = $this->employeeWithGrants(['config']);
        $nobody = $this->employeeWithGrants(['customers']);

        $migration = new AccountingPeriods();
        $migration->down();
        $migration->up();

        foreach ([...self::MODULES, 'acc_periods_manage'] as $permissionId) {
            $this->assertSame(1, $db->table('grants')->where('person_id', $holder)->where('permission_id', $permissionId)->countAllResults(), $permissionId);
        }

        foreach (self::SENSITIVE as $permissionId) {
            $this->assertSame(0, $db->table('grants')->where('permission_id', $permissionId)->countAllResults(), $permissionId);
        }

        $this->assertSame(0, $db->table('grants')->where('person_id', $nobody)->whereIn('permission_id', [...self::MODULES, ...self::SUB_PERMISSIONS])->countAllResults());
    }

    public function testUp_KeepsTheSensitivePermissionsAnAdministratorGrantedBeforeItRunsAgain(): void
    {
        $db       = $this->accountingDb();
        $manager  = $this->employeeWithGrants(['config', 'acc_periods_close']);

        (new AccountingPeriods())->up();

        $this->assertSame(1, $db->table('grants')->where('person_id', $manager)->where('permission_id', 'acc_periods_close')->countAllResults());
        $this->assertSame(0, $db->table('grants')->where('person_id', $manager)->where('permission_id', 'acc_periods_reopen')->countAllResults());
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $counts = [
            'accounting_period_locks' => $this->countRows('accounting_period_locks'),
            'grants'                  => $this->countRows('grants'),
            'permissions'             => $this->countRows('permissions'),
            'modules'                 => $this->countRows('modules'),
        ];

        (new AccountingPeriods())->up();

        foreach ($counts as $table => $count) {
            $this->assertSame($count, $this->countRows($table), $table);
        }
    }

    public function testDown_ThenUpKeepsTheClosedPeriodsAndFiscalYears(): void
    {
        $this->reinstallChart();
        $db = $this->accountingDb();
        $db->table('accounting_closed_periods')->insert(['company_id' => 1, 'branch_id' => null, 'date_from' => '2026-01-01', 'date_to' => '2026-03-31', 'note' => 'Q1']);

        $migration = new AccountingPeriods();

        try {
            $migration->down();

            $this->assertFalse($this->tableExists('accounting_period_locks'));
            $this->assertFalse($this->hasColumn('accounting_closed_periods', 'reopen_reason'));
            $this->assertFalse($this->hasColumn('accounting_fiscal_years', 'description'));
            $this->assertSame(0, $db->table('modules')->whereIn('module_id', self::MODULES)->countAllResults());
            $this->assertSame(1, $db->table('accounting_closed_periods')->where('note', 'Q1')->countAllResults());
            $this->assertSame(1, $db->table('accounting_fiscal_years')->where('name', '2026')->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->tableExists('accounting_period_locks'));
        $this->assertSame(1, $db->table('accounting_closed_periods')->where('note', 'Q1')->countAllResults());
        $this->assertSame(1, $db->table('accounting_period_locks')->where('company_id', 1)->where('branch_id', 0)->countAllResults());

        $db->table('accounting_closed_periods')->where('note', 'Q1')->delete();
    }
}
