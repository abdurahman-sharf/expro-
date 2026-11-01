<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\PermissionCatalog;
use App\Libraries\Roles\RoleGrants;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;

/**
 * @internal
 */
final class PermissionCatalogTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private PermissionCatalog $catalog;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->catalog = new PermissionCatalog();
    }

    /**
     * @return array<string, array{module_id: string, label: string, description: string, selectable: bool, permissions: list<array{permission_id: string, label: string, sensitive: bool}>}>
     */
    private function modulesById(): array
    {
        $byId = [];

        foreach ($this->catalog->modules() as $module) {
            $byId[$module['module_id']] = $module;
        }

        return $byId;
    }

    /**
     * @return array<string, array{permission_id: string, label: string, sensitive: bool}>
     */
    private function permissionsById(): array
    {
        $byId = [];

        foreach ($this->catalog->modules() as $module) {
            foreach ($module['permissions'] as $permission) {
                $byId[$permission['permission_id']] = $permission;
            }
        }

        return $byId;
    }

    public function testModules_ListsEveryModuleInTheOrderOfTheMenuWithTheLabelsOfTheEmployeeForm(): void
    {
        $stored  = Database::connect()->table('modules')->select('module_id')->orderBy('sort', 'ASC')->get()->getResultArray();
        $modules = $this->catalog->modules();

        $this->assertSame(array_column($stored, 'module_id'), array_column($modules, 'module_id'));

        $sales = $this->modulesById()['sales'];

        $this->assertSame(lang('Module.sales'), $sales['label']);
        $this->assertSame(lang('Module.sales_desc'), $sales['description']);
    }

    public function testModules_GroupsASubpermissionUnderItsModuleWithItsOwnLabel(): void
    {
        $sales = $this->modulesById()['sales'];
        $ids   = array_column($sales['permissions'], 'permission_id');

        $this->assertContains('sales_delete', $ids);
        $this->assertContains('sales_stock', $ids);
        $this->assertNotContains('sales', $ids, 'The module is not one of its own permissions');
        $this->assertSame($ids, array_values(array_unique($ids)));

        $sorted = $ids;

        sort($sorted);

        $this->assertSame($sorted, $ids, 'The permissions of a module keep to the order of their names');

        $permissions = $this->permissionsById();

        $this->assertSame(lang('Sales.delete'), $permissions['sales_delete']['label']);
        $this->assertSame(lang('Sales.stock'), $permissions['sales_stock']['label']);
    }

    public function testModules_NamesAPermissionWhoseLanguageKeyIsMissingAfterItsId(): void
    {
        $db = Database::connect();

        $db->table('permissions')->insert(['permission_id' => 'sales_zzz_unlabelled_check', 'module_id' => 'sales']);

        try {
            $this->assertSame('Zzz Unlabelled Check', $this->permissionsById()['sales_zzz_unlabelled_check']['label']);
        } finally {
            $db->table('permissions')->where('permission_id', 'sales_zzz_unlabelled_check')->delete();
        }

        $this->assertArrayNotHasKey('sales_zzz_unlabelled_check', $this->permissionsById());
    }

    public function testModules_MarksEverySensitivePermissionAndNoOtherOne(): void
    {
        $permissions = $this->permissionsById();

        foreach (AccountingGuard::SENSITIVE as $sensitive) {
            if (isset($permissions[$sensitive])) {
                $this->assertTrue($permissions[$sensitive]['sensitive'], $sensitive);
            }
        }

        $this->assertArrayHasKey(AccountingGuard::ROLES_MANAGE, $permissions);
        $this->assertTrue($permissions[AccountingGuard::ROLES_MANAGE]['sensitive']);
        $this->assertFalse($permissions['sales_delete']['sensitive']);
        $this->assertFalse($permissions['sales_stock']['sensitive']);

        $marked = array_keys(array_filter($permissions, static fn (array $permission): bool => $permission['sensitive']));

        $this->assertSame([], array_values(array_diff($marked, AccountingGuard::SENSITIVE)));
    }

    public function testModules_NeverListsTheBranchScopePermissions(): void
    {
        $permissions = $this->permissionsById();

        $this->assertArrayNotHasKey(BranchGuard::ALL_BRANCHES, $permissions);
        $this->assertArrayNotHasKey(BranchGuard::SCOPE_MANAGE, $permissions);
    }

    public function testModules_OffersAModuleForSelectionUnlessItsIdIsAForbiddenPermission(): void
    {
        $db = Database::connect();

        $db->table('modules')->insert(['module_id' => AccountingGuard::TREASURIES_MANAGE, 'name_lang_key' => 'module_check', 'desc_lang_key' => 'module_check_desc', 'sort' => 9990]);

        try {
            $modules = $this->modulesById();

            foreach ($modules as $moduleId => $module) {
                $this->assertSame(! in_array($moduleId, RoleGrants::forbidden(), true), $module['selectable'], $moduleId);
            }

            $this->assertTrue($modules['sales']['selectable']);
            $this->assertFalse($modules[AccountingGuard::TREASURIES_MANAGE]['selectable'], 'A module that is a sensitive permission cannot be given to a role');
        } finally {
            $db->table('modules')->where('module_id', AccountingGuard::TREASURIES_MANAGE)->delete();
        }
    }
}
