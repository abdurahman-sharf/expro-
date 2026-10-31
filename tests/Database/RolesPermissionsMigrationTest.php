<?php

namespace Tests\Database;

use App\Database\Migrations\RolesPermissions;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class RolesPermissionsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $migration = new RolesPermissions();
        $migration->down();
        $migration->up();
    }

    private function grantsOf(string $permissionId): int
    {
        return $this->accountingDb()->table('grants')->where('permission_id', $permissionId)->countAllResults();
    }

    public function testUp_RegistersTheRolesModuleAndThePermissionToManageRolesUnderIt(): void
    {
        $module     = $this->accountingDb()->table('modules')->where('module_id', 'roles')->get()->getRow();
        $permission = $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::ROLES_MANAGE)->get()->getRow();

        $this->assertNotNull($module);
        $this->assertSame('86', (string) $module->sort, 'The roles sit next to the employees in the menu');
        $this->assertSame('roles', $permission->module_id);
        $this->assertSame(1, $this->accountingDb()->table('permissions')->where(['permission_id' => 'roles', 'module_id' => 'roles'])->countAllResults());
    }

    public function testUp_GrantsNothingToAnyone(): void
    {
        $migration = new RolesPermissions();
        $holder    = $this->employeeWithGrants([AccountingGuard::ROLES_MANAGE, 'roles', AccountingGuard::JOURNALS_POST]);

        try {
            $migration->down();
            $this->assertSame(0, $this->grantsOf(AccountingGuard::ROLES_MANAGE));

            $migration->up();
            $this->assertSame(0, $this->grantsOf(AccountingGuard::ROLES_MANAGE));
            $this->assertSame(0, $this->grantsOf('roles'));
            $this->assertSame(1, $this->accountingDb()->table('grants')->where('person_id', $holder)->where('permission_id', AccountingGuard::JOURNALS_POST)->countAllResults());
        } finally {
            $migration->up();
        }
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $permissions = $this->countRows('permissions');
        $modules     = $this->countRows('modules');

        (new RolesPermissions())->up();

        $this->assertSame($permissions, $this->countRows('permissions'));
        $this->assertSame($modules, $this->countRows('modules'));
    }

    public function testDown_RemovesTheModuleThePermissionAndTheirGrantsAndUpBringsThemBack(): void
    {
        $holder    = $this->employeeWithGrants([AccountingGuard::ROLES_MANAGE, 'roles']);
        $migration = new RolesPermissions();

        try {
            $migration->down();

            $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::ROLES_MANAGE)->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', 'roles')->countAllResults());
            $this->assertSame(0, $this->accountingDb()->table('grants')->where('person_id', $holder)->where('permission_id', AccountingGuard::ROLES_MANAGE)->countAllResults());
        } finally {
            $migration->up();
        }

        $this->assertSame(1, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::ROLES_MANAGE)->countAllResults());
    }
}
