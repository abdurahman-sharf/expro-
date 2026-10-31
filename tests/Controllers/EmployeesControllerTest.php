<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Models\Employee;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\EmployeeBranchService;
use App\Models\Module;
use App\Libraries\Roles\DefaultLocations;
use App\Libraries\Roles\RoleService;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\RoleFixtureTrait;

class EmployeesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    protected $priorDisallowGrantChange;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        // Reset the test database to a known clean schema on the first test in
        // this class so stale employees from prior runs (whose usernames are
        // unique-key bound and whose grant sets leak into these assertions)
        // cannot contaminate the assertions below.
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
        // Reset any stale transaction state left on the shared in-process DB
        // connection by a previous test (e.g. a failed transaction rollback in
        // strict mode sets transStatus=false and poisons save_employee here).
        $this->db->resetTransStatus();
        $this->priorDisallowGrantChange = getenv('DISALLOW_GRANT_CHANGE');
        putenv('DISALLOW_GRANT_CHANGE=false');
    }

    protected function tearDown(): void
    {
        if ($this->priorDisallowGrantChange === false) {
            putenv('DISALLOW_GRANT_CHANGE');
        } else {
            putenv('DISALLOW_GRANT_CHANGE=' . $this->priorDisallowGrantChange);
        }
        parent::tearDown();
    }

    protected function createNonAdminEmployee(): int
    {
        return $this->createEmployee(
            first_name:   'NonAdmin',
            last_name:    'User',
            email:        'nonadmin' . uniqid() . '@test.com',
            username:     'nonadmin_' . uniqid(),
            phone_number: '555-1234',
            grants: [
                ['permission_id' => 'employees', 'menu_group' => 'home'],
                ['permission_id' => 'customers', 'menu_group' => 'home'],
                ['permission_id' => 'sales', 'menu_group' => 'home'],
            ],
        );
    }

    protected function loginAsAdmin(): void
    {
        $this->withSession(['person_id' => 1, 'menu_group' => 'office']);
    }

    protected function loginAsNonAdmin(int $personId): void
    {
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);
    }

    public function testNonAdminCannotViewAdminAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);
        
        $response = $this->get('/employees/view/1');
        
        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testNonAdminCannotModifyAdminAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);
        
        $response = $this->post('/employees/save/1', [
            'first_name' => 'Hacked',
            'last_name' => 'Admin',
            'email' => 'hacked@evil.com',
            'username' => 'admin'
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('admin', strtolower($result['message']));
    }

    public function testNonAdminCannotDeleteAdminAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);
        
        $response = $this->post('/employees/delete', [
            'ids' => ['1']
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertStringContainsString('admin', strtolower($result['message']));
    }

    public function testNonAdminCannotGrantPermissionsTheyDontHave(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);

        $targetEmployeeId = $this->createEmployee(email: 'test@test.com', username: 'testuser');

        $response = $this->post('/employees/save/' . $targetEmployeeId, [
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => 'test@test.com',
            'username' => 'testuser',
            'language' => 'en:english',
            'grant_config' => 'config',
            'grant_giftcards' => 'giftcards'
        ]);

        $employeeModel = model(Employee::class);
        $hasConfigGrant = $employeeModel->has_grant('config', $targetEmployeeId);
        $hasGiftcardsGrant = $employeeModel->has_grant('giftcards', $targetEmployeeId);

        $this->assertFalse($hasConfigGrant);
        $this->assertFalse($hasGiftcardsGrant);
    }

    public function testAdminCanModifyAnyAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsAdmin();
        
        $response = $this->post('/employees/save/' . $nonAdminId, [
            'first_name' => 'Modified',
            'last_name' => 'User',
            'email' => 'modified@test.com',
            'username' => 'modified',
            'language' => 'en:english'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testAdminCanDeleteAnyAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsAdmin();
        
        $response = $this->post('/employees/delete', [
            'ids' => [(string)$nonAdminId]
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testUserCanModifyOwnAccount(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($nonAdminId);
        
        $response = $this->post('/employees/save/' . $nonAdminId, [
            'first_name' => 'Modified',
            'last_name' => 'OwnAccount',
            'email' => 'own@test.com',
            'username' => 'owned',
            'language' => 'en:english'
        ]);
        
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);
    }

    public function testPermissionDelegationRule(): void
    {
        $permissionsRequested = ['customers', 'employees', 'sales', 'config'];
        $userPermissions = ['customers', 'sales'];
        $isAdmin = false;
        
        $granted = [];
        foreach ($permissionsRequested as $perm) {
            if ($isAdmin || in_array($perm, $userPermissions)) {
                $granted[] = $perm;
            }
        }
        
        $this->assertEquals(['customers', 'sales'], $granted);
    }

    public function testAdminCanGrantAnyPermission(): void
    {
        $employeeId = $this->createNonAdminEmployee();
        $this->loginAsAdmin();

        putenv('DISALLOW_GRANT_CHANGE=false');

        $permissionsRequested = ['customers', 'employees', 'sales', 'config'];

        $postData = [
            'first_name' => 'NonAdmin',
            'last_name'  => 'User',
            'email'      => 'nonadmin@test.com',
            'username'   => 'grantany' . uniqid(),
            'language'   => 'en:english'
        ];
        foreach ($permissionsRequested as $perm) {
            $postData['grant_' . $perm] = $perm;
        }

        $response = $this->post('/employees/save/' . $employeeId, $postData);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);

        $employeeModel = model(Employee::class);
        foreach ($permissionsRequested as $perm) {
            $this->assertTrue($employeeModel->has_grant($perm, $employeeId));
        }
    }

    public function testGrantChangeRequestFailsWhenGrantChangeDisallowed(): void
    {
        // Target holds customers+sales but NOT employees, so requesting the
        // employees grant is a genuine change that DISALLOW_GRANT_CHANGE=true
        // must reject (leaving the grant set untouched).
        $unique = uniqid();
        $employeeId = $this->createEmployee(
            email:    "disallow{$unique}@test.com",
            username: "disallow{$unique}",
            grants: [
                ['permission_id' => 'customers', 'menu_group' => 'home'],
                ['permission_id' => 'sales', 'menu_group' => 'home'],
            ],
        );
        $this->loginAsAdmin();

        putenv('DISALLOW_GRANT_CHANGE=true');

        $response = $this->post('/employees/save/' . $employeeId, [
            'first_name'      => 'NonAdmin',
            'last_name'       => 'User',
            'email'           => "disallow{$unique}@test.com",
            'username'        => "disallow{$unique}",
            'language'        => 'en:english',
            'grant_employees' => 'employees'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);

        $employeeModel = model(Employee::class);
        $this->assertTrue($employeeModel->has_grant('customers', $employeeId));
        $this->assertTrue($employeeModel->has_grant('sales', $employeeId));
        $this->assertFalse($employeeModel->has_grant('employees', $employeeId));
    }

    public function testNewEmployeeCreationWithGrantsFailsWhenGrantChangeDisallowed(): void
    {
        $this->loginAsAdmin();

        putenv('DISALLOW_GRANT_CHANGE=true');

        $response = $this->post('/employees/save', [
            'first_name'      => 'Brand',
            'last_name'       => 'New',
            'email'           => 'brandnew@test.com',
            'username'        => 'brandnew',
            'password'        => 'password123',
            'grant_customers' => 'customers'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);

        $createdEmployee = $this->db->table('employees')->where('username', 'brandnew')->get()->getRow();
        $this->assertNull($createdEmployee);
    }

    public function testGrantChangeRequestSucceedsWhenGrantChangeAllowed(): void
    {
        $employeeId = $this->createNonAdminEmployee();
        $this->loginAsAdmin();

        putenv('DISALLOW_GRANT_CHANGE=false');

        $response = $this->post('/employees/save/' . $employeeId, [
            'first_name'      => 'NonAdmin',
            'last_name'       => 'User',
            'email'           => 'nonadmin@test.com',
            'username'        => 'grantsucc',
            'language'        => 'en:english',
            'grant_employees' => 'employees'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);

        $employeeModel = model(Employee::class);
        $this->assertTrue($employeeModel->has_grant('employees', $employeeId));
        $this->assertFalse($employeeModel->has_grant('customers', $employeeId));
        $this->assertFalse($employeeModel->has_grant('sales', $employeeId));
    }

    public function testNewEmployeeCreationWithGrantsSucceedsWhenGrantChangeAllowed(): void
    {
        $this->loginAsAdmin();

        putenv('DISALLOW_GRANT_CHANGE=false');

        $response = $this->post('/employees/save', [
            'first_name'      => 'Brand',
            'last_name'       => 'New2',
            'email'           => 'brandnew2@test.com',
            'phone_number'    => '555-1234',
            'address_1'       => '',
            'address_2'       => '',
            'city'            => '',
            'state'           => '',
            'zip'             => '',
            'country'         => '',
            'comments'        => '',
            'username'        => 'brandnew2',
            'password'        => 'password123',
            'language'        => 'en:english',
            'grant_customers' => 'customers'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);

        $createdEmployee = $this->db->table('employees')->where('username', 'brandnew2')->get()->getRow();
        $this->assertNotNull($createdEmployee);

        $employeeModel = model(Employee::class);
        $this->assertTrue($employeeModel->has_grant('customers', (int) $createdEmployee->person_id));
    }

    public function testGetView_IncludesCommentsAndPermissionControls(): void
    {
        $this->loginAsAdmin();

        $response = $this->get('/employees/view/-1');

        $response->assertStatus(200);
        $response->assertSeeElement('textarea[name=comments]');
        $response->assertSeeElement('#permission_select_all');
    }

    private function onlyTheDefaultBranch(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->table('employee_branches')->where('branch_id !=', 1)->delete();
        $this->db->table('stock_locations')->update(['branch_id' => 1]);
        $this->db->table('branches')->where('branch_id !=', 1)->delete();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function addBranch(string $code): int
    {
        $this->db->table('branches')->insert(['company_id' => 1, 'code' => $code, 'name' => "Branch {$code}"]);

        return (int) $this->db->insertID();
    }

    private function allowScopeChanges(int $personId): void
    {
        $this->db->table('grants')->where('person_id', $personId)->whereIn('permission_id', [BranchGuard::SCOPE_MANAGE, BranchGuard::ALL_BRANCHES])->delete();
        $this->db->table('grants')->insertBatch([
            ['permission_id' => BranchGuard::SCOPE_MANAGE, 'person_id' => $personId, 'menu_group' => '--'],
            ['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $personId, 'menu_group' => '--'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function formFor(string $username, array $extra = []): array
    {
        return $extra + [
            'first_name'   => 'Branch',
            'last_name'    => 'Worker',
            'email'        => $username . '@test.com',
            'phone_number' => '555-1234',
            'address_1'    => '',
            'address_2'    => '',
            'city'         => '',
            'state'        => '',
            'zip'          => '',
            'country'      => '',
            'comments'     => '',
            'username'     => $username,
            'language'     => 'en:english',
        ];
    }

    public function testGetView_KeepsTheAllBranchesGrantOffThePermissionList(): void
    {
        $this->loginAsAdmin();

        $response = $this->get('/employees/view/-1');

        $response->assertSee('grant_org_branches_manage');
        $response->assertSee('grant_org_scope_manage');
        $response->assertDontSee('grant_org_all_branches');
    }

    public function testGetView_HidesTheBranchesTabWhileThereIsOneBranch(): void
    {
        $this->onlyTheDefaultBranch();
        $this->allowScopeChanges(1);
        $this->loginAsAdmin();

        $this->get('/employees/view/-1')->assertDontSee('employee_branch_info');
    }

    public function testGetView_ShowsTheBranchesTabToSomeoneWhoSetsBranchesOnceThereAreSeveral(): void
    {
        $this->onlyTheDefaultBranch();
        $this->addBranch('B2');
        $this->allowScopeChanges(1);
        $this->loginAsAdmin();

        $response = $this->get('/employees/view/-1');

        $response->assertSee('employee_branch_info');
        $response->assertSee('name="branch_ids[]"');
        $response->assertSee('id="branch_scope_all"');
    }

    public function testGetView_HidesTheBranchesTabFromSomeoneWhoCannotSetBranches(): void
    {
        $this->onlyTheDefaultBranch();
        $this->addBranch('B2');
        $this->db->table('grants')->where('person_id', 1)->where('permission_id', BranchGuard::SCOPE_MANAGE)->delete();
        $this->loginAsAdmin();

        $this->get('/employees/view/-1')->assertDontSee('employee_branch_info');
    }

    public function testPostSave_PutsBackTheAllBranchesGrantTheFormRewrote(): void
    {
        $this->allowScopeChanges(1);
        $employeeId = $this->createEmployee(grants: [
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
        ]);
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $employeeId, $this->formFor('keepall' . uniqid(), ['grant_customers' => 'customers']))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertTrue(model(Employee::class)->has_grant(BranchGuard::ALL_BRANCHES, $employeeId));
    }

    public function testPostSave_GivesANewEmployeeTheStartingBranch(): void
    {
        $this->onlyTheDefaultBranch();
        $this->loginAsAdmin();

        $username = 'newbranch' . uniqid();
        $result   = json_decode($this->post('/employees/save', $this->formFor($username, ['password' => 'password123', 'grant_customers' => 'customers']))->getJSON(), true);

        $this->assertTrue($result['success'], (string) ($result['message'] ?? ''));
        $scope = (new EmployeeBranchService())->scopeOf((int) $result['id']);
        $this->assertSame([1], $scope['branch_ids']);
        $this->assertFalse($scope['all']);
    }

    public function testPostSave_AppliesTheBranchesTheFormAskedFor(): void
    {
        $this->onlyTheDefaultBranch();
        $second = $this->addBranch('B2');
        $this->allowScopeChanges(1);
        $employeeId = $this->createEmployee(grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $employeeId, $this->formFor('apply' . uniqid(), [
            'grant_customers'      => 'customers',
            'branch_scope_present' => '1',
            'branch_scope'         => 'selected',
            'branch_ids'           => [(string) $second],
            'default_branch_id'    => (string) $second,
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(['all' => false, 'branch_ids' => [$second], 'default_branch_id' => $second], (new EmployeeBranchService())->scopeOf($employeeId));
    }

    public function testPostSave_GivesEveryBranchWhenTheFormAsksForIt(): void
    {
        $this->onlyTheDefaultBranch();
        $this->addBranch('B2');
        $this->allowScopeChanges(1);
        $employeeId = $this->createEmployee(grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->loginAsAdmin();

        $this->post('/employees/save/' . $employeeId, $this->formFor('all' . uniqid(), ['grant_customers' => 'customers', 'branch_scope_present' => '1', 'branch_scope' => 'all']));

        $this->assertTrue((new EmployeeBranchService())->scopeOf($employeeId)['all']);
    }

    public function testPostSave_SavesNothingWhenTheRequestedBranchesAreNotValid(): void
    {
        $this->onlyTheDefaultBranch();
        $this->addBranch('B2');
        $this->allowScopeChanges(1);
        $employeeId = $this->createEmployee(first_name: 'Before', grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $employeeId, $this->formFor('invalid' . uniqid(), ['first_name' => 'After', 'grant_customers' => 'customers', 'branch_scope_present' => '1', 'branch_scope' => 'selected']))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame('Before', $this->db->table('people')->where('person_id', $employeeId)->get()->getRow()->first_name);
    }

    public function testPostSave_IgnoresABranchRequestFromSomeoneWhoCannotSetBranches(): void
    {
        $this->onlyTheDefaultBranch();
        $second = $this->addBranch('B2');
        $this->db->table('grants')->where('person_id', 1)->where('permission_id', BranchGuard::SCOPE_MANAGE)->delete();
        $employeeId = $this->createEmployee(grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->db->table('employee_branches')->insert(['person_id' => $employeeId, 'branch_id' => 1, 'is_default' => 1]);
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $employeeId, $this->formFor('ignored' . uniqid(), ['grant_customers' => 'customers', 'branch_scope_present' => '1', 'branch_scope' => 'selected', 'branch_ids' => [(string) $second]]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([1], (new EmployeeBranchService())->scopeOf($employeeId)['branch_ids']);
    }

    public function testPostSave_LeavesTheBranchesAloneWhenGrantChangeIsDisallowed(): void
    {
        $this->onlyTheDefaultBranch();
        $second = $this->addBranch('B2');
        $this->allowScopeChanges(1);
        $unique     = uniqid();
        $employeeId = $this->createEmployee(email: "locked{$unique}@test.com", username: "locked{$unique}", grants: [['permission_id' => 'customers', 'menu_group' => 'home']]);
        $this->db->table('employee_branches')->insert(['person_id' => $employeeId, 'branch_id' => 1, 'is_default' => 1]);
        $this->loginAsAdmin();

        putenv('DISALLOW_GRANT_CHANGE=true');

        $result = json_decode($this->post('/employees/save/' . $employeeId, $this->formFor("locked{$unique}", ['grant_customers' => 'customers', 'branch_scope_present' => '1', 'branch_scope' => 'selected', 'branch_ids' => [(string) $second]]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([1], (new EmployeeBranchService())->scopeOf($employeeId)['branch_ids']);
    }

    public function testPostSave_DoesNotCountTheAllBranchesGrantAsAChangeWhenGrantChangeIsDisallowed(): void
    {
        $unique     = uniqid();
        $employeeId = $this->createEmployee(email: "samegrants{$unique}@test.com", username: "samegrants{$unique}", grants: [
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
        ]);
        $this->loginAsAdmin();

        putenv('DISALLOW_GRANT_CHANGE=true');

        $result = json_decode($this->post('/employees/save/' . $employeeId, $this->formFor("samegrants{$unique}", ['grant_customers' => 'customers']))->getJSON(), true);

        $this->assertTrue($result['success']);
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

    public function testPostDelete_RefusesTheLastEmployeeWithEveryBranch(): void
    {
        $actor  = $this->createNonAdminEmployee();
        $holder = $this->createEmployee(grants: [['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']]);
        $this->loginAsNonAdmin($actor);

        $this->whileOnlyHolders([$holder], function () use ($holder): void {
            $result = json_decode($this->post('/employees/delete', ['ids' => [(string) $holder]])->getJSON(), true);

            $this->assertFalse($result['success']);
            $this->assertSame(lang('Branches.error_last_unrestricted'), $result['message']);
        });

        $this->assertSame(0, (int) db_connect()->table('employees')->where('person_id', $holder)->get()->getRow()->deleted);
        $this->assertTrue(model(Employee::class)->has_grant(BranchGuard::ALL_BRANCHES, $holder));
    }

    public function testPostDelete_DeletesAnEmployeeWithEveryBranchWhileAnotherKeepsIt(): void
    {
        $actor  = $this->createNonAdminEmployee();
        $first  = $this->createEmployee(grants: [['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']]);
        $second = $this->createEmployee(grants: [['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']]);
        $this->loginAsNonAdmin($actor);

        $this->whileOnlyHolders([$first, $second], function () use ($first): void {
            $result = json_decode($this->post('/employees/delete', ['ids' => [(string) $first]])->getJSON(), true);

            $this->assertTrue($result['success']);
        });

        $this->assertSame(1, (int) db_connect()->table('employees')->where('person_id', $first)->get()->getRow()->deleted);
    }

    private function sensitiveHolder(string $permissionId = AccountingGuard::PERIODS_CLOSE): int
    {
        return $this->createEmployee(grants: [
            ['permission_id' => 'employees', 'menu_group' => 'office'],
            ['permission_id' => 'accounting_periods', 'menu_group' => 'office'],
            ['permission_id' => $permissionId, 'menu_group' => '--'],
        ]);
    }

    /**
     * @return list<string>
     */
    private function auditedPermissions(int $targetId, string $action): array
    {
        $rows = $this->db->table('accounting_audit_log')->where('entity_type', 'employee_permission')->where('entity_id', $targetId)->where('action', $action)->orderBy('audit_id')->get()->getResult();

        return array_map(static fn (object $row): string => json_decode($row->details, true)['permission'], $rows);
    }

    public function testGetView_MarksTheSensitivePermissionsAndLeavesThemToAnAdministrator(): void
    {
        $this->loginAsAdmin();

        $response = $this->get('/employees/view/-1');

        $response->assertSee('grant_acc_periods_close');
        $response->assertSee('sensitive-permission');
        $response->assertDontSee('data-locked="1"');
    }

    public function testGetView_LocksTheSensitivePermissionsForSomeoneWhoIsNotAnAdministrator(): void
    {
        $editor = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($editor);

        $response = $this->get('/employees/view/-1');

        $response->assertSee('sensitive-permission');
        $response->assertSee('data-locked="1"');
    }

    public function testPostSave_LetsAnAdministratorGrantASensitivePermissionAndRecordsIt(): void
    {
        $target = $this->createNonAdminEmployee();
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('sens' . uniqid(), [
            'grant_employees'          => 'employees',
            'grant_accounting_periods' => 'accounting_periods',
            'grant_acc_periods_close'  => AccountingGuard::PERIODS_CLOSE,
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertTrue(model(Employee::class)->has_grant(AccountingGuard::PERIODS_CLOSE, $target));
        $this->assertFalse(model(Employee::class)->has_grant(AccountingGuard::PERIODS_REOPEN, $target));
        $this->assertSame([AccountingGuard::PERIODS_CLOSE], $this->auditedPermissions($target, 'permission_granted'));

        $entry = $this->db->table('accounting_audit_log')->where('entity_id', $target)->where('action', 'permission_granted')->get()->getRow();

        $this->assertSame('1', (string) $entry->employee_id);
    }

    public function testGetView_OffersThePermissionToEditJournalTextAmongTheSensitiveOnes(): void
    {
        $this->loginAsAdmin();

        $response = $this->get('/employees/view/-1');

        $response->assertSee('grant_acc_journals_edit_details');
        $this->assertContains(AccountingGuard::JOURNALS_EDIT, AccountingGuard::SENSITIVE);
    }

    public function testPostSave_LetsAnAdministratorGrantThePermissionToEditJournalTextAndRecordsIt(): void
    {
        $target = $this->createNonAdminEmployee();
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('jtext' . uniqid(), [
            'grant_employees'                 => 'employees',
            'grant_accounting_journals'       => 'accounting_journals',
            'grant_acc_journals_edit_details' => AccountingGuard::JOURNALS_EDIT,
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertTrue(model(Employee::class)->has_grant(AccountingGuard::JOURNALS_EDIT, $target));
        $this->assertSame([AccountingGuard::JOURNALS_EDIT], $this->auditedPermissions($target, 'permission_granted'));
    }

    public function testPostSave_IgnoresThePermissionToEditJournalTextTickedByAnEditorWhoIsNotAnAdministrator(): void
    {
        $editor = $this->sensitiveHolder();
        $target = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($editor);

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('nojtext' . uniqid(), [
            'grant_employees'                 => 'employees',
            'grant_accounting_journals'       => 'accounting_journals',
            'grant_acc_journals_edit_details' => AccountingGuard::JOURNALS_EDIT,
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertFalse(model(Employee::class)->has_grant(AccountingGuard::JOURNALS_EDIT, $target));
        $this->assertSame([], $this->auditedPermissions($target, 'permission_granted'));
    }

    public function testPostSave_RecordsTheRemovalOfASensitivePermissionByAnAdministrator(): void
    {
        $target = $this->sensitiveHolder(AccountingGuard::PERIODS_REOPEN);
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('unsens' . uniqid(), [
            'grant_employees'          => 'employees',
            'grant_accounting_periods' => 'accounting_periods',
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertFalse(model(Employee::class)->has_grant(AccountingGuard::PERIODS_REOPEN, $target));
        $this->assertSame([AccountingGuard::PERIODS_REOPEN], $this->auditedPermissions($target, 'permission_revoked'));
    }

    public function testPostSave_RecordsNothingWhenTheSensitivePermissionsDoNotChange(): void
    {
        $target = $this->sensitiveHolder();
        $this->loginAsAdmin();

        $this->post('/employees/save/' . $target, $this->formFor('same' . uniqid(), [
            'grant_employees'          => 'employees',
            'grant_accounting_periods' => 'accounting_periods',
            'grant_acc_periods_close'  => AccountingGuard::PERIODS_CLOSE,
        ]));

        $this->assertSame([], $this->auditedPermissions($target, 'permission_granted'));
        $this->assertSame([], $this->auditedPermissions($target, 'permission_revoked'));
    }

    public function testPostSave_IgnoresASensitivePermissionTickedByAnEditorWhoIsNotAnAdministrator(): void
    {
        $editor = $this->sensitiveHolder();
        $target = $this->createNonAdminEmployee();
        $this->loginAsNonAdmin($editor);

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('nogrant' . uniqid(), [
            'grant_employees'          => 'employees',
            'grant_accounting_periods' => 'accounting_periods',
            'grant_acc_periods_close'  => AccountingGuard::PERIODS_CLOSE,
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertFalse(model(Employee::class)->has_grant(AccountingGuard::PERIODS_CLOSE, $target));
        $this->assertSame([], $this->auditedPermissions($target, 'permission_granted'));
    }

    public function testPostSave_KeepsTheSensitivePermissionsOfAnEmployeeEditedByANonAdministrator(): void
    {
        $editor = $this->createNonAdminEmployee();
        $target = $this->sensitiveHolder(AccountingGuard::OPENING_MANAGE);
        $this->loginAsNonAdmin($editor);

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('keepsens' . uniqid(), ['grant_employees' => 'employees']))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertTrue(model(Employee::class)->has_grant(AccountingGuard::OPENING_MANAGE, $target));
        $this->assertSame([], $this->auditedPermissions($target, 'permission_revoked'));
    }

    public function testPostSave_KeepsTheSensitivePermissionsWhenGrantChangeIsDisallowed(): void
    {
        $editor = $this->createNonAdminEmployee();
        $target = $this->sensitiveHolder(AccountingGuard::OPENING_MANAGE);
        $this->loginAsNonAdmin($editor);
        putenv('DISALLOW_GRANT_CHANGE=true');

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('lockedsens' . uniqid(), ['grant_employees' => 'employees']))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertTrue(model(Employee::class)->has_grant(AccountingGuard::OPENING_MANAGE, $target));
    }

    public function testPostSave_RecordsASensitivePermissionGivenToANewEmployee(): void
    {
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/-1', $this->formFor('newsens' . uniqid(), [
            'grant_employees'          => 'employees',
            'grant_accounting_periods' => 'accounting_periods',
            'grant_acc_opening_manage' => AccountingGuard::OPENING_MANAGE,
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([AccountingGuard::OPENING_MANAGE], $this->auditedPermissions((int) $result['id'], 'permission_granted'));
    }

    public function testPostDelete_RecordsTheSensitivePermissionsTheDeletedEmployeeHeld(): void
    {
        $target = $this->sensitiveHolder(AccountingGuard::PERIODS_CLOSE);
        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/delete', ['ids' => [(string) $target]])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([AccountingGuard::PERIODS_CLOSE], $this->auditedPermissions($target, 'permission_revoked'));
    }

    /**
     * @return list<array{string, string}> The permission, and the module whose screen it belongs to.
     */
    private function moneyPermissions(): array
    {
        return [
            [AccountingGuard::TREASURIES_MANAGE, 'accounting_treasuries'],
            [AccountingGuard::PAYABLES_MANAGE, 'accounting_payables'],
            [AccountingGuard::INTERBRANCH_SETTLE, 'accounting_treasuries'],
            [AccountingGuard::YEAR_END_CLOSE, 'accounting_periods'],
        ];
    }

    public function testGetView_MarksTheTreasuriesAndPayablesPermissionsAsSensitiveAndLocksThemForANonAdministrator(): void
    {
        $this->loginAsNonAdmin($this->createNonAdminEmployee());

        $html = $this->get('/employees/view/-1')->getBody();

        foreach ($this->moneyPermissions() as [$permissionId]) {
            $this->assertMatchesRegularExpression('/<input[^>]*id="grant_' . $permissionId . '"[^>]*sensitive-permission[^>]*>/', $html, $permissionId);
            $this->assertMatchesRegularExpression('/<input[^>]*id="grant_' . $permissionId . '"[^>]*data-locked="1"[^>]*>/', $html, $permissionId);
        }

        $this->loginAsAdmin();

        $html = $this->get('/employees/view/-1')->getBody();

        foreach ($this->moneyPermissions() as [$permissionId]) {
            $this->assertMatchesRegularExpression('/<input[^>]*id="grant_' . $permissionId . '"[^>]*sensitive-permission[^>]*>/', $html, $permissionId);
            $this->assertDoesNotMatchRegularExpression('/<input[^>]*id="grant_' . $permissionId . '"[^>]*data-locked="1"[^>]*>/', $html, $permissionId);
        }
    }

    public function testPostSave_LetsAnAdministratorGrantAndRemoveTheTreasuriesAndPayablesPermissionsAndRecordsBoth(): void
    {
        $target = $this->createNonAdminEmployee();
        $this->loginAsAdmin();

        foreach ($this->moneyPermissions() as [$permissionId, $moduleId]) {
            $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('moneyon' . uniqid(), [
                'grant_employees'        => 'employees',
                'grant_' . $moduleId     => $moduleId,
                'grant_' . $permissionId => $permissionId,
            ]))->getJSON(), true);

            $this->assertTrue($result['success'], $permissionId);
            $this->assertTrue(model(Employee::class)->has_grant($permissionId, $target), $permissionId);
            $this->assertSame([$permissionId], $this->auditedPermissions($target, 'permission_granted'));

            $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('moneyoff' . uniqid(), ['grant_employees' => 'employees']))->getJSON(), true);

            $this->assertTrue($result['success'], $permissionId);
            $this->assertFalse(model(Employee::class)->has_grant($permissionId, $target), $permissionId);
            $this->assertSame([$permissionId], $this->auditedPermissions($target, 'permission_revoked'));

            $this->db->table('accounting_audit_log')->where('entity_type', 'employee_permission')->where('entity_id', $target)->delete();
        }
    }

    public function testPostSave_IgnoresATreasuriesOrPayablesPermissionTickedByAnEditorWhoIsNotAnAdministrator(): void
    {
        foreach ($this->moneyPermissions() as [$permissionId, $moduleId]) {
            $editor = $this->createEmployee(grants: [
                ['permission_id' => 'employees', 'menu_group' => 'office'],
                ['permission_id' => $moduleId, 'menu_group' => 'office'],
                ['permission_id' => $permissionId, 'menu_group' => '--'],
            ]);
            $target = $this->createNonAdminEmployee();
            $this->loginAsNonAdmin($editor);

            $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('nomoney' . uniqid(), [
                'grant_employees'        => 'employees',
                'grant_' . $moduleId     => $moduleId,
                'grant_' . $permissionId => $permissionId,
            ]))->getJSON(), true);

            $this->assertTrue($result['success'], $permissionId);
            $this->assertFalse(model(Employee::class)->has_grant($permissionId, $target), $permissionId);
            $this->assertSame([], $this->auditedPermissions($target, 'permission_granted'), $permissionId);
        }
    }

    public function testPostSave_KeepsTheTreasuriesAndPayablesPermissionsOfAnEmployeeEditedByANonAdministrator(): void
    {
        $editor = $this->createNonAdminEmployee();
        $target = $this->createEmployee(grants: [
            ['permission_id' => 'employees', 'menu_group' => 'office'],
            ['permission_id' => AccountingGuard::TREASURIES_MANAGE, 'menu_group' => '--'],
            ['permission_id' => AccountingGuard::PAYABLES_MANAGE, 'menu_group' => '--'],
            ['permission_id' => AccountingGuard::INTERBRANCH_SETTLE, 'menu_group' => '--'],
            ['permission_id' => AccountingGuard::YEAR_END_CLOSE, 'menu_group' => '--'],
        ]);
        $this->loginAsNonAdmin($editor);

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('keepmoney' . uniqid(), ['grant_employees' => 'employees']))->getJSON(), true);

        $this->assertTrue($result['success']);

        foreach ($this->moneyPermissions() as [$permissionId]) {
            $this->assertTrue(model(Employee::class)->has_grant($permissionId, $target), $permissionId);
        }

        $this->assertSame([], $this->auditedPermissions($target, 'permission_revoked'));
        $this->assertSame([], $this->auditedPermissions($target, 'permission_granted'));
    }

    private function rolesAdministratorLoggedIn(): int
    {
        $administrator = $this->rolesAdministrator();

        $this->loginAsNonAdmin($administrator);

        return $administrator;
    }

    public function testGetView_ShowsTheRolesAndTheDefaultLocationOnATabAndLetsAManagerOfRolesChooseThem(): void
    {
        $role = $this->createRole(['sales'], 'own', true, 'Cashiers ' . uniqid());
        $this->rolesAdministratorLoggedIn();

        $response = $this->get('/employees/view/-1');

        $response->assertSee('employee_roles_info');
        $response->assertSee('name="role_ids[]"');
        $response->assertSee('id="role_' . $role . '"');
        $response->assertSee('name="record_scope"');
        $response->assertSee('name="default_location_id"');
        $response->assertSee('name="roles_present"');
    }

    public function testGetView_ShowsNoRoleChoiceToAnEmployeeWhoMayNotManageRolesButStillTheDefaultLocation(): void
    {
        $this->createRole(['sales'], 'all', true, 'Hidden ' . uniqid());
        $this->loginAsNonAdmin($this->sensitiveHolder());

        $response = $this->get('/employees/view/-1');

        $response->assertDontSee('name="role_ids[]"');
        $response->assertDontSee('name="roles_present"');
        $response->assertSee('name="default_location_id"');
    }

    public function testGetView_KeepsWhatARoleGivesOffTheDirectGrantsOfTheFormAndNamesTheRole(): void
    {
        $role     = $this->createRole(['items'], 'all', true, 'Stockists ' . uniqid());
        $employee = $this->employeeWithRoles([$role], ['employees']);

        $this->loginAsAdmin();

        $html = $this->get('/employees/view/' . $employee)->getBody();

        $this->assertStringContainsString('checked', $this->checkboxTag($html, 'grant_employees'), 'A direct grant stays ticked');
        $this->assertStringNotContainsString('checked', $this->checkboxTag($html, 'grant_items'), 'A grant that a role gives is not copied into the form');
        $this->assertStringContainsString(esc(lang('Roles.via_role', [(new RoleService())->find($role)->name])), $html);
    }

    private function checkboxTag(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('/<input[^>]*id="' . preg_quote($id, '/') . '"[^>]*>/', $html, $found), "The form has no input {$id}");

        return $found[0];
    }

    public function testPostSave_DoesNotCopyWhatARoleGivesIntoTheDirectGrantsOfTheEmployee(): void
    {
        $role     = $this->createRole(['items'], 'all', true, 'Stockists ' . uniqid());
        $employee = $this->employeeWithRoles([$role], ['employees']);

        $this->loginAsAdmin();

        $result = json_decode($this->post('/employees/save/' . $employee, $this->formFor('nocopy' . uniqid(), ['grant_employees' => 'employees']))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame(['employees'], array_column(model(Employee::class)->getDirectGrants($employee), 'permission_id'));
        $this->assertTrue(model(Employee::class)->has_grant('items', $employee), 'The role still gives it');
    }

    public function testPostSave_GivesTheRolesAndTheRecordScopeAChosenByAManagerOfRolesAndAuditsThem(): void
    {
        $role   = $this->createRole(['sales'], 'all', true, 'Cashiers ' . uniqid());
        $target = $this->createNonAdminEmployee();
        $actor  = $this->rolesAdministratorLoggedIn();

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('roleful' . uniqid(), [
            'grant_employees' => 'employees',
            'roles_present'   => '1',
            'role_ids'        => [(string) $role],
            'record_scope'    => 'own',
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([$role], (new RoleService())->roleIdsOf($target));
        $this->assertSame('own', (new RoleService())->recordScopeOf($target));
        $this->assertTrue(model(Employee::class)->has_grant('sales', $target));

        $entry = $this->db->table('accounting_audit_log')->where(['entity_type' => 'employee', 'entity_id' => $target, 'action' => 'employee_roles_changed'])->get()->getRow();

        $this->assertSame((string) $actor, (string) $entry->employee_id);
    }

    public function testPostSave_RemovesTheRolesOfAnEmployeeWhenTheManagerOfRolesTicksNone(): void
    {
        $role   = $this->createRole(['sales'], 'all', true, 'Cashiers ' . uniqid());
        $target = $this->employeeWithRoles([$role], ['employees']);

        $this->rolesAdministratorLoggedIn();

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('unrole' . uniqid(), ['grant_employees' => 'employees', 'roles_present' => '1', 'record_scope' => 'all']))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([], (new RoleService())->roleIdsOf($target));
    }

    public function testPostSave_IgnoresTheRolesTickedByAnEditorWhoMayNotManageRoles(): void
    {
        $role   = $this->createRole(['sales'], 'all', true, 'Cashiers ' . uniqid());
        $target = $this->createNonAdminEmployee();

        $this->loginAsNonAdmin($this->sensitiveHolder());

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('norole' . uniqid(), [
            'grant_employees' => 'employees',
            'roles_present'   => '1',
            'role_ids'        => [(string) $role],
            'record_scope'    => 'own',
        ]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame([], (new RoleService())->roleIdsOf($target));
        $this->assertSame('all', (new RoleService())->recordScopeOf($target));
    }

    public function testPostSave_IgnoresTheRolesWhenGrantsMayNotBeChanged(): void
    {
        $role   = $this->createRole(['sales'], 'all', true, 'Cashiers ' . uniqid());
        $target = $this->createNonAdminEmployee();

        $this->rolesAdministratorLoggedIn();
        putenv('DISALLOW_GRANT_CHANGE=true');

        try {
            $this->post('/employees/save/' . $target, $this->formFor('frozen' . uniqid(), ['grant_employees' => 'employees', 'grant_customers' => 'customers', 'grant_sales' => 'sales', 'roles_present' => '1', 'role_ids' => [(string) $role]]));
        } finally {
            putenv('DISALLOW_GRANT_CHANGE=false');
        }

        $this->assertSame([], (new RoleService())->roleIdsOf($target));
    }

    public function testPostSave_RefusesARoleWhosePermissionsTheEditorWhoManagesRolesDoesNotHold(): void
    {
        $role    = $this->createRole(['sales', 'items'], 'all', true, 'Wide ' . uniqid());
        $target  = $this->createNonAdminEmployee();
        $manager = $this->rolesManager(['employees', 'sales']);

        $this->loginAsNonAdmin($manager);

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('wide' . uniqid(), ['grant_employees' => 'employees', 'roles_present' => '1', 'role_ids' => [(string) $role]]))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Roles.error_cannot_grant', ['items']), $result['message']);
        $this->assertSame([], (new RoleService())->roleIdsOf($target));
    }

    public function testPostSave_RefusesARoleThatDoesNotExistAndNamesTheError(): void
    {
        $target = $this->createNonAdminEmployee();

        $this->rolesAdministratorLoggedIn();

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('ghost' . uniqid(), ['grant_employees' => 'employees', 'roles_present' => '1', 'role_ids' => ['999999']]))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Roles.error_not_found'), $result['message']);
    }

    public function testPostSave_SetsTheDefaultStockLocationOfTheEmployeeForWhoeverEditsEmployees(): void
    {
        $target = $this->createNonAdminEmployee();

        $this->db->table('stock_locations')->insert(['location_name' => 'Default depot ' . uniqid()]);

        $location = (int) $this->db->insertID();

        $this->loginAsNonAdmin($this->sensitiveHolder());

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('depot' . uniqid(), ['grant_employees' => 'employees', 'default_location_present' => '1', 'default_location_id' => (string) $location]))->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertSame($location, (new DefaultLocations())->of($target));

        $this->post('/employees/save/' . $target, $this->formFor('depot2' . uniqid(), ['grant_employees' => 'employees', 'default_location_present' => '1', 'default_location_id' => '0']));

        $this->assertNull((new DefaultLocations())->of($target));
    }

    public function testPostSave_RefusesALocationThatDoesNotExistAsTheDefaultOfAnEmployee(): void
    {
        $target = $this->createNonAdminEmployee();

        $this->loginAsNonAdmin($this->sensitiveHolder());

        $result = json_decode($this->post('/employees/save/' . $target, $this->formFor('nodepot' . uniqid(), ['grant_employees' => 'employees', 'default_location_present' => '1', 'default_location_id' => '999999']))->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Roles.error_location_not_found'), $result['message']);
    }

    public function testPostDelete_ReleasesTheRolesOfTheEmployeesThatAreDeleted(): void
    {
        $role   = $this->createRole(['sales'], 'all', true, 'Cashiers ' . uniqid());
        $target = $this->employeeWithRoles([$role], ['employees']);

        $this->loginAsAdmin();

        $this->assertTrue(json_decode($this->post('/employees/delete', ['ids' => [(string) $target]])->getJSON(), true)['success']);
        $this->assertSame([], (new RoleService())->roleIdsOf($target));
    }

    public function testPostSave_AcceptsTheSameDirectGrantsWhileGrantsMayNotBeChangedForAnEmployeeWhoHoldsARole(): void
    {
        $role     = $this->createRole(['items', 'customers'], 'all', true, 'Stockists ' . uniqid());
        $employee = $this->employeeWithRoles([$role], ['employees']);

        $this->loginAsAdmin();
        putenv('DISALLOW_GRANT_CHANGE=true');

        try {
            $result = json_decode($this->post('/employees/save/' . $employee, $this->formFor('frozenrole' . uniqid(), ['grant_employees' => 'employees']))->getJSON(), true);
        } finally {
            putenv('DISALLOW_GRANT_CHANGE=false');
        }

        $this->assertTrue($result['success'], 'What a role gives is not a change of the grants of the employee');
    }
}
