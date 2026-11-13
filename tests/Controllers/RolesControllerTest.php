<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class RolesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        RoleGrants::flush();
    }

    private function loginAs(int $personId): void
    {
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
    }

    private function manager(array $extraGrants = ADMIN_MODULES): int
    {
        $personId = $this->rolesManager($extraGrants);

        $this->loginAs($personId);

        return $personId;
    }

    private function reader(): int
    {
        $personId = $this->createEmployee(grants: [['permission_id' => 'roles', 'menu_group' => 'home']]);

        $this->loginAs($personId);

        return $personId;
    }

    /**
     * @param mixed $response
     *
     * @return array<string, mixed>
     */
    private function jsonOf($response): array
    {
        return json_decode($response->getJSON(), true);
    }

    private function roleNamed(string $name): ?object
    {
        return $this->roleDb()->table('roles')->where('name', $name)->get()->getRow();
    }

    public function testGetIndex_SendsAnEmployeeWithoutTheRolesModuleToTheNoAccessPage(): void
    {
        $this->loginAs($this->createEmployee());

        $response = $this->get('/roles');

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testGetIndex_OffersTheNewRoleButtonOnlyToAManagerOfRoles(): void
    {
        $this->manager();

        $page = $this->get('/roles');

        $page->assertStatus(200);
        $page->assertSee('data-href="roles/view"');

        $this->reader();

        $page = $this->get('/roles');

        $page->assertStatus(200);
        $page->assertDontSee('data-href="roles/view"');
    }

    public function testGetSearch_ListsTheRolesWithTheirPermissionsAndEmployeesAndFiltersByName(): void
    {
        $name = 'Searchable ' . uniqid();
        $role = $this->createRole(['sales', 'items'], 'own', true, $name, 'Counts things');

        $this->employeeWithRoles([$role]);
        $this->manager();

        $rows = $this->jsonOf($this->get('/roles/search?search=' . rawurlencode($name)))['rows'];

        $this->assertCount(1, $rows);
        $this->assertSame($role, $rows[0]['role_id']);
        $this->assertStringContainsString($name, $rows[0]['name']);
        $this->assertStringContainsString('Counts things', $rows[0]['name']);
        $this->assertSame(2, $rows[0]['permission_count']);
        $this->assertSame(1, $rows[0]['employee_count']);
        $this->assertSame(lang('Roles.scope_own'), $rows[0]['record_scope']);
        $this->assertSame(0, $this->jsonOf($this->get('/roles/search?search=nomatch' . uniqid()))['total']);
    }

    public function testGetSearch_ShowsNoEditOrDeleteActionToAnEmployeeWhoMayNotManageRoles(): void
    {
        $name = 'Plain ' . uniqid();

        $this->createRole(['sales'], 'all', true, $name);
        $this->reader();

        $rows = $this->jsonOf($this->get('/roles/search?search=' . rawurlencode($name)))['rows'];

        $this->assertSame('', $rows[0]['edit']);

        $this->manager();

        $rows = $this->jsonOf($this->get('/roles/search?search=' . rawurlencode($name)))['rows'];

        $this->assertStringContainsString('roles/view/', $rows[0]['edit']);
        $this->assertStringContainsString('role-action', $rows[0]['edit']);
    }

    public function testGetRow_ReturnsTheRowOfARoleAndIsNotFoundForOneThatDoesNotExist(): void
    {
        $role = $this->createRole(['sales']);

        $this->manager();

        $this->assertSame($role, $this->jsonOf($this->get('/roles/row/' . $role))['role_id']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/roles/row/999999');
    }

    public function testGetView_StartsANewRoleWithNothingChosenAndTheSensitivePermissionsLocked(): void
    {
        $this->manager();

        $html = $this->get('/roles/view')->getBody();

        $this->assertStringContainsString('name="permissions[]"', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="permissions\[\]"[^>]*checked/', $html, 'No permission is chosen for a new role');
        $this->assertMatchesRegularExpression('/id="record_scope_all"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="perm_acc_roles_manage"[^>]*name=/', $html, 'A sensitive permission cannot be chosen');
        $this->assertMatchesRegularExpression('/id="perm_acc_roles_manage"[^>]*disabled/', $html);
        $this->assertStringContainsString(lang('Roles.sensitive_permission'), $html);
        $this->assertStringNotContainsString('perm_org_all_branches', $html, 'The branch scope is not offered to a role');
        $this->assertStringContainsString(lang('Module.sales'), $html);
    }

    public function testGetView_LocksThePermissionsThatMoveMoneyAndDropsThemFromARoleThatStillHoldsThem(): void
    {
        $role = $this->createRole(['sales']);

        $this->roleDb()->table('role_permissions')->insert(['role_id' => $role, 'permission_id' => AccountingGuard::DEBIT_NOTES_MANAGE]);
        RoleGrants::flush();
        $this->manager();

        $html = $this->get('/roles/view/' . $role)->getBody();

        $this->assertMatchesRegularExpression('/id="perm_acc_debit_notes_manage"[^>]*disabled/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="perm_acc_debit_notes_manage"[^>]*name=/', $html);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*type="hidden"[^>]*value="acc_debit_notes_manage"/', $html, 'The save would be refused if the old row were sent back');
        $this->assertMatchesRegularExpression('/id="perm_acc_bank_reconcile"[^>]*name="permissions\[\]"/', $html, 'A permission that moves nothing stays choosable');
        $this->assertStringContainsString(lang('Roles.refused_permission'), $html);
    }

    public function testGetView_ShowsWhatTheRoleHoldsAndItsScope(): void
    {
        $role = $this->createRole(['sales', 'items'], 'own', true, 'Held ' . uniqid());

        $this->manager();

        $html = $this->get('/roles/view/' . $role)->getBody();

        $this->assertMatchesRegularExpression('/id="perm_sales"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/id="perm_items"[^>]*checked/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="perm_customers"[^>]*checked/', $html);
        $this->assertMatchesRegularExpression('/id="record_scope_own"[^>]*checked/', $html);
    }

    public function testGetView_KeepsAPermissionTheManagerDoesNotHoldInTheFormSoSavingDoesNotDropIt(): void
    {
        $role = $this->createRole(['sales', 'items'], 'all', true, 'Partly ' . uniqid());

        $this->manager(['sales']);

        $html = $this->get('/roles/view/' . $role)->getBody();

        $this->assertMatchesRegularExpression('/<input type="hidden" name="permissions\[\]" value="items"/', $html);
        $this->assertMatchesRegularExpression('/id="perm_items"[^>]*disabled/', $html);
        $this->assertMatchesRegularExpression('/id="perm_sales"[^>]*name="permissions\[\]"/', $html);
    }

    public function testGetView_IsNotFoundForAnEmployeeWhoMayNotManageRolesAndForARoleThatDoesNotExist(): void
    {
        $role = $this->createRole();

        $this->reader();

        foreach (['/roles/view', '/roles/view/' . $role] as $url) {
            try {
                $this->get($url);
                $this->fail("{$url} was shown to an employee who may not manage roles.");
            } catch (PageNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->manager();

        $this->expectException(PageNotFoundException::class);

        $this->get('/roles/view/999999');
    }

    public function testPostSave_CreatesARoleAndAuditsIt(): void
    {
        $manager = $this->manager();
        $name    = 'Created ' . uniqid();

        $result = $this->jsonOf($this->post('/roles/save', ['name' => $name, 'description' => 'By the screen', 'active' => '1', 'record_scope' => 'own', 'permissions' => ['sales', 'items']]));

        $this->assertTrue($result['success']);

        $role = $this->roleNamed($name);

        $this->assertSame($result['id'], (int) $role->role_id);
        $this->assertSame('own', $role->record_scope);
        $this->assertSame(['items', 'sales'], (new RoleService())->permissionsOf((int) $role->role_id));

        $entry = $this->roleDb()->table('accounting_audit_log')->where(['entity_type' => 'role', 'entity_id' => $role->role_id, 'action' => 'role_created'])->get()->getRow();

        $this->assertSame((string) $manager, (string) $entry->employee_id);
    }

    public function testPostSave_UpdatesARoleAndStoresTheSubmittedSetAsSent(): void
    {
        $role = $this->createRole(['sales', 'items'], 'all', true, 'Before ' . uniqid());
        $this->manager();

        $result = $this->jsonOf($this->post('/roles/save/' . $role, ['name' => 'After ' . uniqid(), 'active' => '0', 'record_scope' => 'all', 'permissions' => ['sales_delete']]));

        $this->assertTrue($result['success']);
        $this->assertSame(['sales_delete'], (new RoleService())->permissionsOf($role), 'Nothing is added for the permissions a choice usually needs');
        $this->assertSame('0', (string) (new RoleService())->find($role)->active);
    }

    public function testPostSave_RefusesASensitivePermissionAndNamesIt(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/roles/save', ['name' => 'Sensitive ' . uniqid(), 'permissions' => ['sales', AccountingGuard::PERIODS_CLOSE]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Roles.error_sensitive_permission', [AccountingGuard::PERIODS_CLOSE]), $result['message']);
        $this->assertStringContainsString(AccountingGuard::PERIODS_CLOSE, $result['message']);
    }

    public function testPostSave_RejectsAPermissionThatMovesMoneyAndNamesIt(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/roles/save', ['name' => 'Cheques ' . uniqid(), 'permissions' => ['sales', AccountingGuard::CHEQUES_MANAGE]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Roles.error_refused_permission', [AccountingGuard::CHEQUES_MANAGE]), $result['message']);
        $this->assertStringContainsString(AccountingGuard::CHEQUES_MANAGE, $result['message']);
    }

    public function testPostSave_RefusesTheBranchScopeEvenWhenItIsPosted(): void
    {
        $this->manager();

        $result = $this->jsonOf($this->post('/roles/save', ['name' => 'Branchy ' . uniqid(), 'permissions' => ['org_all_branches']]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Roles.error_branch_permission', ['org_all_branches']), $result['message']);
    }

    public function testPostSave_RefusesAnEmptyNameADuplicateNameAndAScopeThatIsNotKnown(): void
    {
        $this->manager();

        $taken = 'Taken ' . uniqid();

        $this->createRole([], 'all', true, $taken);

        $this->assertSame(lang('Roles.error_invalid_name'), $this->jsonOf($this->post('/roles/save', ['name' => '  ']))['message']);
        $this->assertSame(lang('Roles.error_name_taken', [$taken]), $this->jsonOf($this->post('/roles/save', ['name' => $taken]))['message']);
        $this->assertSame(lang('Roles.error_invalid_scope'), $this->jsonOf($this->post('/roles/save', ['name' => 'Scoped ' . uniqid(), 'record_scope' => 'branch']))['message']);
    }

    public function testPostSave_RefusesAnEmployeeWhoMayNotManageRolesAndChangesNothing(): void
    {
        $role = $this->createRole(['sales'], 'all', true, 'Untouched ' . uniqid());

        $this->reader();

        $created = $this->jsonOf($this->post('/roles/save', ['name' => 'Sneaked ' . uniqid(), 'permissions' => ['sales']]));
        $changed = $this->jsonOf($this->post('/roles/save/' . $role, ['name' => 'Renamed ' . uniqid(), 'permissions' => ['items']]));

        $this->assertFalse($created['success']);
        $this->assertSame(lang('Roles.error_permission_denied'), $created['message']);
        $this->assertFalse($changed['success']);
        $this->assertSame(['sales'], (new RoleService())->permissionsOf($role));
    }

    public function testPostSave_RefusesAPermissionTheManagerDoesNotHold(): void
    {
        $this->manager(['sales']);

        $result = $this->jsonOf($this->post('/roles/save', ['name' => 'Wider ' . uniqid(), 'permissions' => ['sales', 'items']]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Roles.error_cannot_grant', ['items']), $result['message']);
    }

    public function testPostDeleteRole_DeletesARoleNobodyHoldsAndRefusesOneEmployeesHold(): void
    {
        $free = $this->createRole(['sales'], 'all', true, 'Free ' . uniqid());
        $held = $this->createRole(['sales'], 'all', true, 'Held ' . uniqid());

        $this->employeeWithRoles([$held]);
        $this->manager();

        $this->assertTrue($this->jsonOf($this->post('/roles/delete/' . $free))['success']);
        $this->assertNull((new RoleService())->find($free));

        $refused = $this->jsonOf($this->post('/roles/delete/' . $held));

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Roles.error_in_use'), $refused['message']);
        $this->assertNotNull((new RoleService())->find($held));
    }

    public function testPostDeleteRole_RefusesAnEmployeeWhoMayNotManageRoles(): void
    {
        $role = $this->createRole();

        $this->reader();

        $result = $this->jsonOf($this->post('/roles/delete/' . $role));

        $this->assertFalse($result['success']);
        $this->assertNotNull((new RoleService())->find($role));
    }

    public function testRolesScreens_AreNotReachableWithAGrantThatOnlyARoleGives(): void
    {
        $role     = $this->createRole(['roles', 'sales']);
        $employee = $this->employeeWithRoles([$role]);

        $this->loginAs($employee);

        $this->get('/roles')->assertStatus(200);

        $result = $this->jsonOf($this->post('/roles/save', ['name' => 'By role ' . uniqid()]));

        $this->assertFalse($result['success'], 'The permission to manage roles is never given through a role');
    }

    public function testGetSearch_OffersNoDeleteActionForARoleThatEmployeesHold(): void
    {
        $held = 'Held ' . uniqid();
        $role = $this->createRole(['sales'], 'all', true, $held);

        $this->employeeWithRoles([$role]);
        $this->manager();

        $rows = $this->jsonOf($this->get('/roles/search?search=' . rawurlencode($held)))['rows'];

        $this->assertStringContainsString('roles/view/', $rows[0]['edit']);
        $this->assertStringNotContainsString('role-action', $rows[0]['edit']);
    }
}
