<?php

namespace Tests\Database;

use App\Database\Migrations\AccountingModules;
use App\Models\Employee;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingModulesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const MODULES = ['accounting_chart' => 120, 'accounting_journals' => 121, 'accounting_cost_centers' => 122];

    private const SUB_PERMISSIONS = [
        'acc_chart_manage'        => 'accounting_chart',
        'acc_journals_all'        => 'accounting_journals',
        'acc_journals_draft'      => 'accounting_journals',
        'acc_journals_post'       => 'accounting_journals',
        'acc_journals_reverse'    => 'accounting_journals',
        'acc_journals_audit'      => 'accounting_journals',
        'acc_cost_centers_manage' => 'accounting_cost_centers',
    ];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    public function testUp_RegistersTheThreeModulesInOrderAfterTheExistingOnes(): void
    {
        $rows = $this->accountingDb()->table('modules')->like('module_id', 'accounting_', 'after')->orderBy('sort')->get()->getResult();

        $this->assertSame(array_keys(self::MODULES), array_column($rows, 'module_id'));
        $this->assertSame(array_values(self::MODULES), array_map('intval', array_column($rows, 'sort')));

        $clashes = $this->accountingDb()->table('modules')
            ->whereIn('sort', array_values(self::MODULES))
            ->notLike('module_id', 'accounting_', 'after')
            ->countAllResults();

        $this->assertSame(0, $clashes);
    }

    public function testUp_RegistersEachModuleAsItsOwnBasePermission(): void
    {
        foreach (array_keys(self::MODULES) as $moduleId) {
            $permission = $this->accountingDb()->table('permissions')->where('permission_id', $moduleId)->get()->getRow();

            $this->assertNotNull($permission, $moduleId);
            $this->assertSame($moduleId, $permission->module_id);
        }
    }

    public function testUp_RegistersEverySubPermissionUnderItsModule(): void
    {
        foreach (self::SUB_PERMISSIONS as $permissionId => $moduleId) {
            $permission = $this->accountingDb()->table('permissions')->where('permission_id', $permissionId)->get()->getRow();

            $this->assertNotNull($permission, $permissionId);
            $this->assertSame($moduleId, $permission->module_id, $permissionId);
        }
    }

    public function testUp_GrantsNothingToAnyone(): void
    {
        $granted = $this->accountingDb()->table('grants')
            ->whereIn('permission_id', [...array_keys(self::MODULES), ...array_keys(self::SUB_PERMISSIONS)])
            ->countAllResults();

        $this->assertSame(0, $granted);
    }

    public function testUp_LetsTheModuleGrantAloneOpenTheModule(): void
    {
        $employee = $this->employeeWithGrants(['accounting_journals']);

        $this->assertTrue(model(Employee::class)->has_module_grant('accounting_journals', $employee));
    }

    public function testUp_KeepsSubPermissionsAwayFromTheModulePrefix(): void
    {
        foreach (self::SUB_PERMISSIONS as $permissionId => $moduleId) {
            $this->assertStringStartsNotWith($moduleId, $permissionId);
        }
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $before = $this->countRows('permissions');

        (new AccountingModules())->up();

        $this->assertSame($before, $this->countRows('permissions'));
    }

    public function testDown_RemovesTheModulesPermissionsAndGrants(): void
    {
        $employee  = $this->employeeWithGrants($this->allAccountingPermissions());
        $migration = new AccountingModules();

        try {
            $migration->down();

            $this->assertSame(0, $this->accountingDb()->table('modules')->like('module_id', 'accounting_', 'after')->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('permissions')->like('module_id', 'accounting_', 'after')->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('grants')->where('person_id', $employee)->whereIn('permission_id', $this->allAccountingPermissions())->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertSame(3, $this->accountingDb()->table('modules')->like('module_id', 'accounting_', 'after')->countAllResults());
    }
}
