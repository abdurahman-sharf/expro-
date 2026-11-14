<?php

namespace Tests\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use App\Models\Employee;
use App\Models\Module;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\RoleFixtureTrait;

class EmployeeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    private const MODULES_THAT_NEED_A_SECOND_GRANT = ['items', 'receivings', 'recurring_expenses', 'recurring_invoices', 'reports', 'sales'];
    private const PERMISSIONS_OF_ANOTHER_MODULE_THAT_BEGIN_WITH_A_MODULE_ID = ['expenses_categories' => 'expenses'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace    = null;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testIsAdminReturnsTrueForPersonId1(): void
    {
        $employeeModel = model(Employee::class);

        $result = $employeeModel->isAdmin(1);

        $this->assertTrue($result);
    }

    public function testIsAdminReturnsTrueForEmployeeWithAllPermissions(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['has_grant'])
            ->getMock();

        $employeeModel->method('has_grant')
            ->willReturn(true);

        $result = $employeeModel->isAdmin(2);

        $this->assertTrue($result);
    }

    public function testIsAdminReturnsFalseWhenMissingPermissions(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['has_grant'])
            ->getMock();

        $employeeModel->method('has_grant')
            ->willReturnCallback(function($permissionId, $personId) {
                return $permissionId !== 'config';
            });

        $result = $employeeModel->isAdmin(3);

        $this->assertFalse($result);
    }

    public function testCanModifyEmployeeReturnsTrueForOwnAccount(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $employeeModel->method('isAdmin')
            ->willReturn(false);

        $result = $employeeModel->canModifyEmployee(1, 1);

        $this->assertTrue($result);
    }

    public function testCanModifyEmployeeReturnsTrueForOwnAdminAccount(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $employeeModel->method('isAdmin')
            ->willReturn(true);

        $result = $employeeModel->canModifyEmployee(1, 1);

        $this->assertTrue($result);
    }

    public function testCanModifyEmployeeReturnsFalseWhenNonAdminModifiesAdmin(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $employeeModel->method('isAdmin')
            ->willReturnCallback(function($personId) {
                return $personId === 1;
            });

        $result = $employeeModel->canModifyEmployee(1, 2);

        $this->assertFalse($result);
    }

    public function testCanModifyEmployeeReturnsTrueWhenAdminModifiesNonAdmin(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $employeeModel->method('isAdmin')
            ->willReturnCallback(function($personId) {
                return $personId === 1;
            });

        $result = $employeeModel->canModifyEmployee(2, 1);

        $this->assertTrue($result);
    }

    public function testCanModifyEmployeeReturnsTrueWhenNonAdminModifiesNonAdmin(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $employeeModel->method('isAdmin')
            ->willReturn(false);

        $result = $employeeModel->canModifyEmployee(2, 3);

        $this->assertTrue($result);
    }

    public function testCanModifyEmployeeReturnsFalseForNonAdminEditingAdmin(): void
    {
        $employeeModel = $this->getMockBuilder(Employee::class)
            ->onlyMethods(['isAdmin'])
            ->getMock();

        $employeeModel->method('isAdmin')
            ->willReturnCallback(function($personId) {
                return $personId === 1;
            });

        $result = $employeeModel->canModifyEmployee(1, 2);

        $this->assertFalse($result);
    }

    public function testHasGrantReturnsTrueForActualGrant(): void
    {
        $employeeModel = model(Employee::class);

        $result = $employeeModel->has_grant('employees', 1);

        $this->assertTrue($result);
    }

    public function testHasGrantReturnsFalseForMissingGrant(): void
    {
        $employeeModel = model(Employee::class);

        $result = $employeeModel->has_grant('nonexistent_permission', 1);

        $this->assertFalse($result);
    }

    public function testExistingEmployeeKeepsOriginalGrantsWhenGrantChangeDisallowed(): void
    {
        $employeeId = $this->createEmployee(
            first_name: 'Grant',
            last_name:  'Tester',
            grants: [
                ['permission_id' => 'customers', 'menu_group' => 'home']
            ],
        );

        $originalDisallowGrantChange = getenv('DISALLOW_GRANT_CHANGE');
        putenv('DISALLOW_GRANT_CHANGE=true');

        try {
            $employeeModel = model(Employee::class);
            $personData = ['first_name' => 'Grant', 'last_name' => 'Tester', 'email' => "granttester_upd_{$employeeId}@test.com"];
            $employeeData = ['username' => "granttester_upd_{$employeeId}", 'language_code' => 'en', 'language' => 'english'];
            $newGrantsData = [['permission_id' => 'sales', 'menu_group' => 'home']];

            $saveEmployeeResult = $employeeModel->save_employee($personData, $employeeData, $newGrantsData, $employeeId);
            $this->assertTrue($saveEmployeeResult);

            $this->assertTrue($employeeModel->has_grant('customers', $employeeId));
            $this->assertFalse($employeeModel->has_grant('sales', $employeeId));
        } finally {
            $originalDisallowGrantChange === false
                ? putenv('DISALLOW_GRANT_CHANGE')
                : putenv("DISALLOW_GRANT_CHANGE={$originalDisallowGrantChange}");
        }
    }

    public function testNewEmployeeCreationWithGrantsRejectedWhenGrantChangeDisallowed(): void
    {
        $originalDisallowGrantChange = getenv('DISALLOW_GRANT_CHANGE');
        putenv('DISALLOW_GRANT_CHANGE=true');

        try {
            $result = $this->createEmployeeExpectingFailure(
                first_name: 'Rejected',
                last_name:  'Tester',
                grants: [
                    ['permission_id' => 'customers', 'menu_group' => 'home']
                ],
            );

            $this->assertFalse($result);
        } finally {
            $originalDisallowGrantChange === false
                ? putenv('DISALLOW_GRANT_CHANGE')
                : putenv("DISALLOW_GRANT_CHANGE={$originalDisallowGrantChange}");
        }
    }

    public function testExistingEmployeeGrantsUpdateWhenGrantChangeAllowed(): void
    {
        $employeeId = $this->createEmployee(
            first_name: 'Grant',
            last_name:  'Tester',
            grants: [
                ['permission_id' => 'customers', 'menu_group' => 'home']
            ],
        );

        $employeeModel = model(Employee::class);
        $personData = ['first_name' => 'Grant', 'last_name' => 'Tester', 'email' => "granttester_upd_{$employeeId}@test.com"];
        $employeeData = ['username' => "granttester_upd_{$employeeId}", 'language_code' => 'en', 'language' => 'english'];
        $newGrantsData = [['permission_id' => 'sales', 'menu_group' => 'home']];

        $employeeModel->save_employee($personData, $employeeData, $newGrantsData, $employeeId);

        $this->assertFalse($employeeModel->has_grant('customers', $employeeId));
        $this->assertTrue($employeeModel->has_grant('sales', $employeeId));
    }

    public function testNewEmployeeCreationWithGrantsSucceedsWhenGrantChangeAllowed(): void
    {
        $result = $this->createEmployeeExpectingFailure(
            first_name: 'Granted',
            last_name:  'Tester',
            grants: [
                ['permission_id' => 'customers', 'menu_group' => 'home']
            ],
        );

        $this->assertTrue((bool) $result);
    }

    private function employeeModelActingAs(int $personId): Employee
    {
        $model = $this->getMockBuilder(Employee::class)->onlyMethods(['get_logged_in_employee_info'])->getMock();
        $model->method('get_logged_in_employee_info')->willReturn((object) ['person_id' => $personId]);

        return $model;
    }

    private function everyBranchHolder(): int
    {
        return $this->createEmployee(grants: [['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']]);
    }

    /**
     * Runs the action while only the given employees have every branch.
     *
     * @param list<int> $personIds
     */
    private function whileOnlyHolders(array $personIds, callable $action): void
    {
        $db   = db_connect();
        $rows = $db->table('grants')->where('permission_id', BranchGuard::ALL_BRANCHES)->whereNotIn('person_id', $personIds)->get()->getResultArray();

        $db->table('grants')->where('permission_id', BranchGuard::ALL_BRANCHES)->whereNotIn('person_id', $personIds)->delete();

        try {
            $action();
        } finally {
            if ($rows !== []) {
                $db->table('grants')->insertBatch($rows);
            }
        }
    }

    private function isDeleted(int $personId): bool
    {
        return (int) db_connect()->table('employees')->where('person_id', $personId)->get()->getRow()->deleted === 1;
    }

    public function testDeleteList_RefusesTheLastEmployeeWithEveryBranch(): void
    {
        $holder = $this->everyBranchHolder();

        $this->whileOnlyHolders([$holder], function () use ($holder): void {
            $this->assertFalse($this->employeeModelActingAs(1)->delete_list([$holder]));
        });

        $this->assertFalse($this->isDeleted($holder));
        $this->assertTrue(model(Employee::class)->has_grant(BranchGuard::ALL_BRANCHES, $holder));
    }

    public function testDeleteList_DeletesOneOfTwoEmployeesWithEveryBranch(): void
    {
        $first  = $this->everyBranchHolder();
        $second = $this->everyBranchHolder();

        $this->whileOnlyHolders([$first, $second], function () use ($first): void {
            $this->assertTrue($this->employeeModelActingAs(1)->delete_list([$first]));
        });

        $this->assertTrue($this->isDeleted($first));
        $this->assertFalse($this->isDeleted($second));
        $this->assertTrue(model(Employee::class)->has_grant(BranchGuard::ALL_BRANCHES, $second));
    }

    public function testDeleteList_RefusesAListThatHoldsEveryEmployeeWithEveryBranch(): void
    {
        $first  = $this->everyBranchHolder();
        $second = $this->everyBranchHolder();

        $this->whileOnlyHolders([$first, $second], function () use ($first, $second): void {
            $this->assertFalse($this->employeeModelActingAs(1)->delete_list([(string) $first, (string) $second]));
        });

        $this->assertFalse($this->isDeleted($first));
        $this->assertFalse($this->isDeleted($second));
    }

    public function testDeleteList_DeletesAnEmployeeWithoutEveryBranchWhoseColleagueHoldsTheLastOne(): void
    {
        $holder  = $this->everyBranchHolder();
        $limited = $this->createEmployee();

        $this->whileOnlyHolders([$holder], function () use ($limited): void {
            $this->assertTrue($this->employeeModelActingAs(1)->delete_list([$limited]));
        });

        $this->assertTrue($this->isDeleted($limited));
        $this->assertFalse($this->isDeleted($holder));
    }

    public function testDeleteList_IgnoresDeletedEmployeesWhenCountingWhoKeepsEveryBranch(): void
    {
        $holder  = $this->everyBranchHolder();
        $deleted = $this->everyBranchHolder();
        db_connect()->table('employees')->where('person_id', $deleted)->update(['deleted' => 1]);

        $this->whileOnlyHolders([$holder, $deleted], function () use ($holder): void {
            $this->assertFalse($this->employeeModelActingAs(1)->delete_list([$holder]));
        });

        $this->assertFalse($this->isDeleted($holder));
    }

    public function testDelete_RefusesTheLastEmployeeWithEveryBranch(): void
    {
        $holder = $this->everyBranchHolder();

        $this->whileOnlyHolders([$holder], function () use ($holder): void {
            $this->assertFalse($this->employeeModelActingAs(1)->delete($holder));
        });

        $this->assertFalse($this->isDeleted($holder));
        $this->assertTrue(model(Employee::class)->has_grant(BranchGuard::ALL_BRANCHES, $holder));
    }

    public function testDelete_DeletesOneOfTwoEmployeesWithEveryBranch(): void
    {
        $first  = $this->everyBranchHolder();
        $second = $this->everyBranchHolder();

        $this->whileOnlyHolders([$first, $second], function () use ($first): void {
            $this->assertTrue($this->employeeModelActingAs(1)->delete($first));
        });

        $this->assertTrue($this->isDeleted($first));
    }

    public function testHasGrant_AcceptsAPermissionHeldOnlyThroughARole(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales_delete'])]);
        $model    = model(Employee::class);

        $this->assertTrue($model->has_grant('sales_delete', $employee));
        $this->assertFalse($model->hasDirectGrant('sales_delete', $employee));
    }

    public function testHasGrant_RejectsAPermissionNeitherTheGrantsNorTheRolesHold(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales_delete'])], ['customers']);
        $model    = model(Employee::class);

        $this->assertFalse($model->has_grant('items', $employee));
        $this->assertFalse($model->has_grant('items', null));
        $this->assertTrue($model->has_grant(null, null));
    }

    public function testHasGrant_AnswersFromTheDirectGrantsAloneForAnEmployeeWithoutRolesOnEveryPermission(): void
    {
        $permissions = array_column(db_connect()->table('permissions')->select('permission_id')->orderBy('permission_id', 'ASC')->get()->getResultArray(), 'permission_id');
        $held        = [];

        foreach ($permissions as $index => $permissionId) {
            if ($index % 3 === 0) {
                $held[] = $permissionId;
            }
        }

        $employee = $this->createEmployee(grants: array_map(static fn (string $id): array => ['permission_id' => $id, 'menu_group' => 'home'], $held));
        $model    = model(Employee::class);

        foreach ($permissions as $permissionId) {
            $this->assertSame(in_array($permissionId, $held, true), $model->has_grant($permissionId, $employee), $permissionId);
            $this->assertSame(in_array($permissionId, $held, true), $model->hasDirectGrant($permissionId, $employee), $permissionId);
        }

        $this->assertEqualsCanonicalizing($held, array_column($model->get_employee_grants($employee), 'permission_id'));
        $this->assertEqualsCanonicalizing($held, array_column($model->getDirectGrants($employee), 'permission_id'));
    }

    public function testHasModuleGrant_AnswersAsItAlwaysDidForAnEmployeeWithoutRolesOnEveryModule(): void
    {
        $modules  = array_column(db_connect()->table('modules')->select('module_id')->get()->getResultArray(), 'module_id');
        $employee = $this->createEmployee(grants: [
            ['permission_id' => 'sales', 'menu_group' => 'home'],
            ['permission_id' => 'sales_delete', 'menu_group' => 'home'],
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => 'items_stock', 'menu_group' => 'home'],
            ['permission_id' => 'taxes', 'menu_group' => 'home'],
        ]);
        $model = model(Employee::class);

        foreach ($modules as $moduleId) {
            $count    = db_connect()->table('grants')->like('permission_id', $moduleId, 'after')->where('person_id', $employee)->countAllResults();
            $expected = $count !== 1 ? $count !== 0 : $model->has_subpermissions($moduleId);

            $this->assertSame($expected, $model->has_module_grant($moduleId, $employee), $moduleId);
        }
    }

    public function testHasModuleGrant_KeepsTheCustomersModuleOpenToAnEmployeeWhoHoldsOnlyTheModuleWhenThePortalPermissionIsRegistered(): void
    {
        $model    = model(Employee::class);
        $employee = $this->createEmployee(grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);

        $this->assertSame(1, db_connect()->table('permissions')->where('permission_id', 'portal_manage')->countAllResults());
        $this->assertTrue($model->has_module_grant('customers', $employee));
        $this->assertTrue($model->has_subpermissions('customers'), 'No permission of the portal begins with the id of the customers module, so a holder of the module alone keeps it');
    }

    public function testHasModuleGrant_DoesNotOpenTheCustomersModuleToTheHolderOfThePortalPermissionAlone(): void
    {
        $model    = model(Employee::class);
        $employee = $this->createEmployee(grants: [['permission_id' => 'portal_manage', 'menu_group' => 'home']]);

        $this->assertFalse($model->has_module_grant('customers', $employee));
    }

    public function testHasModuleGrant_CountsTheGrantsTheRolesGive(): void
    {
        $model    = model(Employee::class);
        $employee = $this->employeeWithRoles([$this->createRole(['customers'])]);

        $this->assertTrue($model->has_module_grant('customers', $employee));
        $this->assertFalse($model->has_module_grant('suppliers', $employee));

        $both = $this->employeeWithRoles([$this->createRole(['sales'])], ['sales_delete']);

        $this->assertTrue($model->has_module_grant('sales', $both), 'The module of a role and a sub-permission of the employee are two grants');
    }

    public function testGetEmployeeGrants_ListsTheOwnGrantsFirstAndThenTheGrantsOfTheRolesOnceEach(): void
    {
        $first    = $this->createRole(['sales', 'customers']);
        $second   = $this->createRole(['customers', 'items']);
        $employee = $this->employeeWithRoles([$first, $second], ['sales']);
        $grants   = model(Employee::class)->get_employee_grants($employee);

        $this->assertSame(['sales', 'customers', 'items'], array_column($grants, 'permission_id'));
        $this->assertSame('home', $grants[0]['menu_group']);
    }

    public function testIsAdmin_RejectsAnEmployeeWhoseRolesGiveEveryAdministratorModule(): void
    {
        $model    = model(Employee::class);
        $employee = $this->employeeWithRoles([$this->createRole(ADMIN_MODULES)]);

        $this->assertTrue($model->has_grant('config', $employee));
        $this->assertFalse($model->isAdmin($employee), 'A role never makes anyone an administrator');

        $direct = $this->createEmployee(grants: array_map(static fn (string $id): array => ['permission_id' => $id, 'menu_group' => 'home'], ADMIN_MODULES));

        $this->assertTrue($model->isAdmin($direct));
    }

    public function testDelete_ReleasesTheRolesOfTheEmployee(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);

        $this->assertTrue($this->employeeModelActingAs(1)->delete($employee));
        $this->assertSame([], (new RoleService())->roleIdsOf($employee));
    }

    public function testDeleteList_ReleasesTheRolesAndTheRecordScopeOfEveryEmployee(): void
    {
        $role   = $this->createRole(['sales']);
        $first  = $this->employeeWithRoles([$role]);
        $second = $this->employeeWithRoles([$role]);

        (new RoleService())->setRecordScope($second, 'own', null);

        $this->assertTrue($this->employeeModelActingAs(1)->delete_list([$first, $second]));
        $this->assertSame([], (new RoleService())->roleIdsOf($first));
        $this->assertSame([], (new RoleService())->roleIdsOf($second));
        $this->assertSame('all', (new RoleService())->recordScopeOf($second));
    }

    public function testGetAllowedModules_ListsTheModulesTheRolesGiveAndKeepsTheOwnOnes(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['customers', 'taxes'])], ['items']);
        $modules  = array_column((new Module())->getAllowedModules($employee)->getResultArray(), 'module_id');

        sort($modules);

        $this->assertSame(['customers', 'items', 'taxes'], $modules);
    }

    public function testGetAllowedModules_ListsOnlyTheOwnModulesOfAnEmployeeWithoutRoles(): void
    {
        $employee = $this->createEmployee(grants: [['permission_id' => 'items', 'menu_group' => 'home'], ['permission_id' => 'sales_delete', 'menu_group' => 'home']]);
        $modules  = array_column((new Module())->getAllowedModules($employee)->getResultArray(), 'module_id');

        $this->assertSame(['items'], $modules);
    }

    /**
     * @return array<string, list<string>>
     */
    private function subPermissionsByModule(): array
    {
        $byModule = [];

        foreach (db_connect()->table('permissions')->select('permission_id, module_id')->orderBy('permission_id')->get()->getResultArray() as $permission) {
            if ($permission['permission_id'] !== $permission['module_id'] && str_starts_with(strtolower($permission['permission_id']), strtolower($permission['module_id']) . '_')) {
                $byModule[$permission['module_id']][] = $permission['permission_id'];
            }
        }

        return $byModule;
    }

    private function withTemporaryPermission(string $permissionId, string $moduleId, callable $body): void
    {
        $db = db_connect();
        $db->table('permissions')->insert(['permission_id' => $permissionId, 'module_id' => $moduleId]);

        try {
            $body();
        } finally {
            $db->table('permissions')->where('permission_id', $permissionId)->delete();
        }
    }

    /**
     * @param list<string> $permissionIds
     *
     * @return list<array{permission_id: string, menu_group: string}>
     */
    private function homeGrants(array $permissionIds): array
    {
        return array_map(static fn (string $id): array => ['permission_id' => $id, 'menu_group' => 'home'], $permissionIds);
    }

    public function testHasModuleGrant_OpensTheExpensesModuleToAnEmployeeWhoHoldsOnlyTheModule(): void
    {
        $employee = $this->createEmployee(grants: $this->homeGrants(['expenses']));

        $this->assertSame(1, db_connect()->table('permissions')->where('permission_id', 'expenses_categories')->countAllResults());
        $this->assertTrue(model(Employee::class)->has_module_grant('expenses', $employee), 'The permission of the categories begins with the id of the expenses module but belongs to another module');
        $this->assertTrue(model(Employee::class)->has_subpermissions('expenses'));
    }

    public function testHasModuleGrant_RejectsTheHolderOfTwoPermissionsOfAnotherModuleWhoseIdBeginsWithTheModuleId(): void
    {
        $this->withTemporaryPermission('expenses_categories_manage', 'expenses_categories', function (): void {
            $employee = $this->createEmployee(grants: $this->homeGrants(['expenses_categories', 'expenses_categories_manage']));

            $this->assertFalse(model(Employee::class)->has_module_grant('expenses', $employee));
            $this->assertTrue(model(Employee::class)->has_module_grant('expenses_categories', $employee), 'The module of the categories opens on its own two grants');
        });
    }

    public function testHasModuleGrant_KeepsAModuleOpenWhenAPermissionOfAnotherModuleBeginsWithItsId(): void
    {
        $this->withTemporaryPermission('expenses_categories_manage', 'expenses_categories', function (): void {
            $employee = $this->createEmployee(grants: $this->homeGrants(['expenses']));

            $this->assertTrue(model(Employee::class)->has_module_grant('expenses', $employee));
        });
    }

    public function testHasModuleGrant_RejectsTheHolderOfTwoPermissionsOfAnotherModuleThroughTheRolesToo(): void
    {
        $this->withTemporaryPermission('expenses_categories_manage', 'expenses_categories', function (): void {
            $holder = $this->employeeWithRoles([$this->createRole(['expenses_categories', 'expenses_categories_manage'])]);
            $alone  = $this->employeeWithRoles([$this->createRole(['expenses'])]);

            $this->assertFalse(model(Employee::class)->has_module_grant('expenses', $holder));
            $this->assertTrue(model(Employee::class)->has_module_grant('expenses', $alone));
        });
    }

    public function testHasModuleGrant_RejectsTheHolderOfTheModuleWhoHoldsOnlyAPermissionOfAnotherModuleWhoseIdBeginsWithIt(): void
    {
        $this->withTemporaryPermission('sales_audit_trail', 'customers', function (): void {
            $model     = model(Employee::class);
            $direct    = $this->createEmployee(grants: $this->homeGrants(['sales', 'sales_audit_trail']));
            $viaRole   = $this->employeeWithRoles([$this->createRole(['sales', 'sales_audit_trail'])]);
            $withStock = $this->createEmployee(grants: $this->homeGrants(['sales', 'sales_stock', 'sales_audit_trail']));

            $this->assertFalse($model->has_module_grant('sales', $direct), 'The permission of the customers module is not a sub-permission of sales');
            $this->assertFalse($model->has_module_grant('sales', $viaRole), 'A role is counted by the same rule');
            $this->assertTrue($model->has_module_grant('sales', $withStock));
        });
    }

    public function testHasModuleGrant_CountsTheSubPermissionsOfTheModuleItself(): void
    {
        $model = model(Employee::class);

        $this->assertTrue($model->has_module_grant('sales', $this->createEmployee(grants: $this->homeGrants(['sales', 'sales_stock']))));
        $this->assertTrue($model->has_module_grant('sales', $this->createEmployee(grants: $this->homeGrants(['sales', 'sales_delete']))));
        $this->assertFalse($model->has_module_grant('sales', $this->createEmployee(grants: $this->homeGrants(['sales']))), 'A module with sub-permissions needs one of them beside its own grant');
        $this->assertFalse($model->has_module_grant('sales', $this->createEmployee(grants: $this->homeGrants(['sales_delete']))));
        $this->assertFalse($model->has_module_grant('sales', $this->createEmployee()));
    }

    public function testHasModuleGrant_RejectsTheHolderOfTwoSubPermissionsOfAModuleWhoDoesNotHoldTheModule(): void
    {
        $model    = model(Employee::class);
        $rejected = [];
        $checked  = 0;

        foreach ($this->subPermissionsByModule() as $moduleId => $subPermissions) {
            if (count($subPermissions) < 2) {
                continue;
            }

            $checked++;
            $holder = $this->createEmployee(grants: $this->homeGrants(array_slice($subPermissions, 0, 2)));

            if ($model->has_module_grant($moduleId, $holder)) {
                $rejected[] = $moduleId;
            }

            $withModule = $this->createEmployee(grants: $this->homeGrants([$moduleId, ...array_slice($subPermissions, 0, 2)]));

            $this->assertTrue($model->has_module_grant($moduleId, $withModule), $moduleId);
        }

        $this->assertGreaterThanOrEqual(5, $checked);
        $this->assertSame([], $rejected, 'Two sub-permissions do not make a module: the grant of the module itself is required');
    }

    public function testHasModuleGrant_RejectsTheHolderOfTwoSubPermissionsOfAModuleThroughTheRolesToo(): void
    {
        $model = model(Employee::class);

        $this->assertFalse($model->has_module_grant('sales', $this->employeeWithRoles([$this->createRole(['sales_delete', 'sales_change_price'])])));
        $this->assertFalse($model->has_module_grant('sales', $this->employeeWithRoles([$this->createRole(['sales_delete'])], ['sales_change_price'])));
        $this->assertTrue($model->has_module_grant('sales', $this->employeeWithRoles([$this->createRole(['sales_delete'])], ['sales'])));
    }

    public function testHasModuleGrant_AnswersTheEmptyModuleIdForTheHolderOfTwoGrantsOfAnyKind(): void
    {
        $model = model(Employee::class);

        $this->assertTrue($model->has_module_grant('', $this->createEmployee(grants: $this->homeGrants(['taxes', 'items']))));
        $this->assertTrue($model->has_module_grant('', $this->employeeWithRoles([$this->createRole(['taxes'])], ['items'])));
        $this->assertFalse($model->has_module_grant('', $this->createEmployee(grants: $this->homeGrants(['taxes']))));
        $this->assertFalse($model->has_module_grant('', $this->createEmployee()));
    }

    public function testHasModuleGrant_ReadsTheNameOfAStockLocationWhateverItsCase(): void
    {
        $this->withTemporaryPermission('sales_Warehouse', 'sales', function (): void {
            $model = model(Employee::class);

            $this->assertTrue($model->has_module_grant('sales', $this->createEmployee(grants: $this->homeGrants(['sales', 'sales_Warehouse']))));
            $this->assertTrue($model->has_module_grant('sales', $this->employeeWithRoles([$this->createRole(['sales', 'sales_Warehouse'])])));
        });
    }

    public function testCatalog_RejectsAPermissionWhoseIdBeginsWithTheIdOfAnotherModule(): void
    {
        $db          = db_connect();
        $moduleIds   = array_column($db->table('modules')->select('module_id')->get()->getResultArray(), 'module_id');
        $permissions = $db->table('permissions')->select('permission_id, module_id')->get()->getResultArray();
        $offenders   = [];

        foreach ($permissions as $permission) {
            foreach ($moduleIds as $moduleId) {
                $beginsWithIt = $permission['permission_id'] !== $moduleId && str_starts_with(strtolower($permission['permission_id']), strtolower($moduleId));

                if ($beginsWithIt && $permission['module_id'] !== $moduleId && (self::PERMISSIONS_OF_ANOTHER_MODULE_THAT_BEGIN_WITH_A_MODULE_ID[$permission['permission_id']] ?? null) !== $moduleId) {
                    $offenders[] = $permission['permission_id'] . ' (module ' . $permission['module_id'] . ') begins with the id of the module ' . $moduleId;
                }
            }
        }

        $this->assertSame([], $offenders, 'The id of a module and of its permissions must not begin with the id of another module: name them like so_*, stk_* or acc_*');
    }

    public function testCatalog_RequiresEveryModuleThatNeedsASecondGrantToBeOnTheList(): void
    {
        $needing = $this->subPermissionsByModule();
        $unlisted = array_diff(array_keys($needing), self::MODULES_THAT_NEED_A_SECOND_GRANT);

        $this->assertSame([], array_map(static fn (string $moduleId): string => $moduleId . ': ' . implode(', ', $needing[$moduleId]), array_values($unlisted)), 'A permission that begins with the id of its own module and an underscore makes the module open only for a holder of its own grant and one of them: name the permission another way, or put the module on the list on purpose');
        $this->assertSame([], array_values(array_diff(self::MODULES_THAT_NEED_A_SECOND_GRANT, array_keys($needing))), 'A module on the list no longer has such a permission');
    }

    public function testHasModuleGrant_OpensEveryModuleToTheHolderOfItsOwnGrantExceptTheOnesThatNeedASecondGrant(): void
    {
        $db       = db_connect();
        $model    = model(Employee::class);
        $moduleIds = array_column($db->table('modules')->select('module_id')->get()->getResultArray(), 'module_id');
        $failures = [];

        $this->assertGreaterThan(40, count($moduleIds));

        foreach ($moduleIds as $moduleId) {
            $holder   = $this->createEmployee(grants: $this->homeGrants([$moduleId]));
            $expected = ! in_array($moduleId, self::MODULES_THAT_NEED_A_SECOND_GRANT, true);

            if ($model->has_module_grant($moduleId, $holder) !== $expected) {
                $failures[] = $moduleId;
            }
        }

        $this->assertSame([], $failures);
    }

    public function testHasModuleGrant_OpensNoModuleToTheHolderOfTheGrantsOfAnotherModule(): void
    {
        $db         = db_connect();
        $model      = model(Employee::class);
        $permissions = $db->table('permissions')->select('permission_id, module_id')->get()->getResultArray();
        $failures   = [];

        foreach (array_keys(self::PERMISSIONS_OF_ANOTHER_MODULE_THAT_BEGIN_WITH_A_MODULE_ID) as $permissionId) {
            $moduleId = self::PERMISSIONS_OF_ANOTHER_MODULE_THAT_BEGIN_WITH_A_MODULE_ID[$permissionId];
            $holder   = $this->createEmployee(grants: $this->homeGrants([$permissionId]));

            if ($model->has_module_grant($moduleId, $holder)) {
                $failures[] = $permissionId . ' opens ' . $moduleId;
            }
        }

        foreach ($permissions as $permission) {
            if ($permission['permission_id'] === $permission['module_id']) {
                continue;
            }

            $holder = $this->createEmployee(grants: $this->homeGrants([$permission['permission_id']]));

            foreach ($permissions as $other) {
                if ($other['permission_id'] === $other['module_id'] && $other['module_id'] !== $permission['module_id'] && $model->has_module_grant($other['module_id'], $holder)) {
                    $failures[] = $permission['permission_id'] . ' opens ' . $other['module_id'];
                }
            }
        }

        $this->assertSame([], $failures);
    }
}
