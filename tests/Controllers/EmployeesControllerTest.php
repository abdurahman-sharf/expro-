<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Models\Employee;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\EmployeeBranchService;
use App\Models\Module;
use Tests\Support\EmployeeFixtureTrait;

class EmployeesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use EmployeeFixtureTrait;

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
}
