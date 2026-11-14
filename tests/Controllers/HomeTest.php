<?php

namespace Tests\Controllers;

use CodeIgniter\Database\Config;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use App\Libraries\Organization\BranchGuard;
use App\Models\Employee;
use Config\Database;
use RuntimeException;

/**
 * Test suite for Home controller password validation
 *
 * Tests the critical fix for password minimum length validation bypass
 * Issue: Code was checking hashed password length (always 60 chars) instead of actual password
 * Fix: Validate raw password length BEFORE hashing
 */
class HomeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    /**
     * Test password validation rejects passwords shorter than 8 characters
     *
     * @return void
     */
    public function testPasswordMinLength_Rejects7Characters(): void
    {
        $this->resetSession();

        // Attempt to change password to 7 characters
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin',
            'current_password' => 'pointofsale',
            'password' => '1234567' // 7 characters
        ]);

        // Assert failure response
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success'], 'Password with 7 chars should be rejected');
        $this->assertEquals(-1, $result['id']);

        // Verify password was not changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pointofsale', $admin->password),
            'Password should not have been changed');
    }

    /**
     * Test password validation accepts passwords with exactly 8 characters
     *
     * @return void
     */
    public function testPasswordMinLength_Accepts8Characters(): void
    {
        $this->resetSession();

        // Change password to exactly 8 characters
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin',
            'current_password' => 'pointofsale',
            'password' => 'pa$$w0rd' // Exactly 8 characters including special chars
        ]);

        // Assert success response
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success'], 'Password with 8 chars should be accepted');
        $this->assertEquals(1, $result['id']);

        // Verify password was changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pa$$w0rd', $admin->password),
            'Password with 8 chars should be accepted');

        // Restore original password
        $employee->change_password([
            'username' => 'admin',
            'password' => password_hash('pointofsale', PASSWORD_DEFAULT),
            'hash_version' => 2
        ], 1);
    }

    /**
     * Test password validation rejects empty password
     *
     * @return void
     */
    public function testPasswordMinLength_RejectsEmptyString(): void
    {
        $this->resetSession();

        // Attempt to set empty password
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin',
            'current_password' => 'pointofsale',
            'password' => '' // Empty string
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success'], 'Empty password should be rejected');
        $this->assertEquals(-1, $result['id']);
    }

    /**
     * Password validation is a raw strlen() check with no whitespace
     * handling, so a password consisting entirely of spaces still counts
     * toward the length minimum.
     *
     * @return void
     */
    public function testPasswordMinLength_WhitespaceOnlyPasswordCountsTowardLength(): void
    {
        $this->resetSession();

        try {
            $response = $this->post('/home/save', [
                'employee_id' => 1,
                'username' => 'admin',
                'current_password' => 'pointofsale',
                'password' => '        ' // 8 spaces: exactly meets the byte-length minimum
            ]);

            $response->assertStatus(200);
            $result = json_decode($response->getJSON(), true);
            $this->assertTrue($result['success'], 'strlen()-based validation accepts 8 spaces as meeting the minimum length');
        } finally {
            // Restore original password
            $employee = model(Employee::class);
            $employee->change_password([
                'username' => 'admin',
                'password' => password_hash('pointofsale', PASSWORD_DEFAULT),
                'hash_version' => 2
            ], 1);
        }
    }

    /**
     * Test password validation accepts passwords with special characters
     * as long as they meet minimum length
     *
     * @return void
     */
    public function testPasswordMinLength_AcceptsSpecialCharacters(): void
    {
        $this->resetSession();

        $specialPassword = 'Str0ng!@#$'; // 11 characters with special chars

        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin',
            'current_password' => 'pointofsale',
            'password' => $specialPassword
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success'], 'Password with special chars should be accepted');
        $this->assertEquals(1, $result['id']);

        // Verify password works
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify($specialPassword, $admin->password));

        // Restore original password
        $employee->change_password([
            'username' => 'admin',
            'password' => password_hash('pointofsale', PASSWORD_DEFAULT),
            'hash_version' => 2
        ], 1);
    }

    /**
     * Regression test: Verify previous vulnerable behavior is fixed
     *
     * Before fix: 1-character passwords like "a" were accepted because
     * code checked len(hashed_password) which is always 60 for bcrypt
     * After fix: Raw password is validated before hashing
     *
     * @return void
     */
    public function testPasswordMinLength_RejectsPreviousBehavior(): void
    {
        $this->resetSession();

        // Attempt the previously vulnerable case: single character password
        $response = $this->post('/home/save', [
            'employee_id' => 1,
            'username' => 'admin',
            'current_password' => 'pointofsale',
            'password' => 'a' // Previously allowed due to bug
        ]);

        // This should now fail
        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success'], 'Single character password should be rejected (CVE fix)');
        $this->assertEquals(-1, $result['id']);

        // Verify password was NOT changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pointofsale', $admin->password),
            'Single character password should be rejected (CVE fix)');
    }

    /**
     * Helper method to reset session
     *
     * @return void
     */
    protected function resetSession(): void
    {
        $this->withSession(['person_id' => 1]); // Admin user
    }

    public function testGetSearchIndex_ListsModulesReportsAndSettingsForAdmin(): void
    {
        $this->resetSession();

        $response = $this->get('/home/searchIndex');

        $response->assertStatus(200);
        $types = array_unique(array_column(json_decode($response->getJSON(), true), 'type'));
        sort($types);
        $this->assertSame(['module', 'report', 'setting'], $types);
    }

    public function testGetSearchIndex_ListsTheExpensesReportsOnlyForAnEmployeeOfEveryBranch(): void
    {
        $reportUrls = function (int $personId): string {
            $this->loginAs($personId);

            return implode(' ', array_column(array_filter(json_decode($this->get('/home/searchIndex')->getJSON(), true), static fn (array $entry): bool => $entry['type'] === 'report'), 'url'));
        };

        $grants   = [['permission_id' => 'home', 'menu_group' => 'home'], ['permission_id' => 'reports', 'menu_group' => 'home'], ['permission_id' => 'reports_expenses_categories', 'menu_group' => 'home'], ['permission_id' => 'reports_sales', 'menu_group' => 'home']];
        $inBranch = $this->createNonAdminEmployee(['grants' => $grants]);

        Config::connect()->table('employee_branches')->insert(['person_id' => $inBranch, 'branch_id' => 1, 'is_default' => 1]);

        $urls = $reportUrls($inBranch);

        $this->assertStringContainsString('reports/summary_sales', $urls);
        $this->assertStringNotContainsString('expenses_categories', $urls);

        $everyBranch = $this->createNonAdminEmployee(['grants' => [...$grants, ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--']]]);

        $this->assertStringContainsString('reports/summary_expenses_categories', $reportUrls($everyBranch));
    }

    private function accountingSwitch(): string
    {
        $row = Database::connect()->table('app_config')->where('key', 'accounting_enabled')->get()->getRow();

        return $row === null ? '0' : (string) $row->value;
    }

    private function turnAccounting(string $enabled): void
    {
        Database::connect()->table('app_config')->replace(['key' => 'accounting_enabled', 'value' => $enabled]);
    }

    /**
     * @param list<string> $permissionIds
     *
     * @return list<string>
     */
    private function searchIndexUrls(array $permissionIds, string $accounting): array
    {
        $before     = $this->accountingSwitch();
        $employeeId = $this->createNonAdminEmployee([
            'grants' => array_map(static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'], ['home', 'reports', ...$permissionIds]),
        ]);

        $this->loginAs($employeeId);
        $this->turnAccounting($accounting);

        try {
            $response = $this->get('/home/searchIndex');

            $response->assertStatus(200);

            return array_column(json_decode($response->getJSON(), true), 'url');
        } finally {
            $this->turnAccounting($before);
        }
    }

    public function testGetSearchIndex_OffersTheInventoryValuationToWhoHoldsTheFinancialReportsModuleWhileAccountingIsOn(): void
    {
        $urls = $this->searchIndexUrls(['reports_inventory', 'accounting_reports'], '1');

        $this->assertNotEmpty(array_filter($urls, static fn (string $url): bool => str_ends_with($url, 'accounting_reports/inventory_valuation')));
    }

    public function testGetSearchIndex_DoesNotOfferTheInventoryValuationWhileAccountingIsOff(): void
    {
        $urls = $this->searchIndexUrls(['reports_inventory', 'accounting_reports'], '0');

        $this->assertSame([], array_values(array_filter($urls, static fn (string $url): bool => str_contains($url, 'inventory_valuation'))));
        $this->assertNotEmpty(array_filter($urls, static fn (string $url): bool => str_ends_with($url, 'reports/inventory_summary')), 'With accounting off the search is as it was');
    }

    public function testGetSearchIndex_DoesNotOfferTheInventoryValuationToWhoLacksTheFinancialReportsModule(): void
    {
        $urls = $this->searchIndexUrls(['reports_inventory', 'accounting_journals'], '1');

        $this->assertSame([], array_values(array_filter($urls, static fn (string $url): bool => str_contains($url, 'inventory_valuation'))));
    }

    public function testGetSearchIndex_OffersTheOperationalReportsToWhoHoldsTheFinancialReportsModuleAndThePermissionOfEachWhileAccountingIsOn(): void
    {
        $urls = $this->searchIndexUrls(['accounting_reports', 'reports_sales', 'reports_payments'], '1');

        foreach (['accounting_reports/revenue?group=day', 'accounting_reports/net_position', 'accounting_reports/invoice_returns', 'accounting_reports/payments?group=customer', 'accounting_reports/advance_payments'] as $path) {
            $this->assertNotEmpty(array_filter($urls, static fn (string $url): bool => str_ends_with($url, $path)), $path);
        }

        $this->assertSame([], array_values(array_filter($urls, static fn (string $url): bool => str_contains($url, 'accounting_reports/item_sales') || str_contains($url, 'accounting_reports/profit'))));
    }

    public function testGetSearchIndex_DoesNotOfferTheOperationalReportsWhileAccountingIsOffOrWithoutTheFinancialReportsModule(): void
    {
        $off     = $this->searchIndexUrls(['accounting_reports', 'reports_sales'], '0');
        $without = $this->searchIndexUrls(['reports_sales', 'accounting_journals'], '1');

        $this->assertSame([], array_values(array_filter($off, static fn (string $url): bool => str_contains($url, 'accounting_reports/revenue'))));
        $this->assertSame([], array_values(array_filter($without, static fn (string $url): bool => str_contains($url, 'accounting_reports/revenue'))));
    }

    public function testGetSearchIndex_OffersTheReportsOfTheFamiliesOnlyWithTheModuleThePermissionAndAccountingOn(): void
    {
        $offered = static fn (array $urls, string $report): bool => array_filter($urls, static fn (string $url): bool => str_ends_with($url, 'accounting_reports/' . $report)) !== [];

        $urls = $this->searchIndexUrls(['reports_suppliers', 'accounting_reports'], '1');

        $this->assertTrue($offered($urls, 'supplier_balances'));
        $this->assertFalse($offered($urls, 'stock_aging'), 'No permission for the stock reports');
        $this->assertFalse($offered($this->searchIndexUrls(['reports_suppliers', 'accounting_reports'], '0'), 'supplier_balances'));
        $this->assertFalse($offered($this->searchIndexUrls(['reports_suppliers', 'accounting_journals'], '1'), 'supplier_balances'));
    }

    /**
     * Create a non-admin employee for testing
     *
     * @param array $overrides Optional overrides for username, email, password, etc.
     * @return int The person_id of the created employee
     */
    protected function createNonAdminEmployee(array $overrides = []): int
    {
        $uniqueSuffix = uniqid();

        $personData = [
            'first_name'   => $overrides['first_name'] ?? 'NonAdmin',
            'last_name'    => $overrides['last_name'] ?? 'User',
            'email'        => $overrides['email'] ?? "nonadmin{$uniqueSuffix}@test.com",
            'phone_number' => $overrides['phone_number'] ?? '555-1234'
        ];

        $employeeData = [
            'username'      => $overrides['username'] ?? "nonadmin{$uniqueSuffix}",
            'password'      => password_hash($overrides['password'] ?? 'password123', PASSWORD_DEFAULT),
            'hash_version'  => 2,
            'language_code' => 'en',
            'language'      => 'english'
        ];

        $grantsData = $overrides['grants'] ?? [
            ['permission_id' => 'home', 'menu_group' => 'home'],
            ['permission_id' => 'customers', 'menu_group' => 'home'],
            ['permission_id' => 'sales', 'menu_group' => 'home']
        ];

        $employeeModel = model(Employee::class);
        $saved = $employeeModel->save_employee($personData, $employeeData, $grantsData, NEW_ENTRY);

        if (!$saved || empty($personData['person_id'])) {
            throw new RuntimeException('Failed to create non-admin employee for testing');
        }

        return (int) $personData['person_id'];
    }

    /**
     * Login as a specific user
     *
     * @param int $personId
     * @return void
     */
    protected function loginAs(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    // ========== BOLA Authorization Tests ==========

    /**
     * Test non-admin cannot view admin password change form
     * BOLA vulnerability fix.
     *
     * @return void
     */
    public function testNonAdminCannotViewAdminPasswordForm(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);

        $response = $this->get('/home/changePassword/1');

        $response->assertStatus(403);
    }

    /**
     * Test non-admin cannot change admin password
     * BOLA vulnerability fix.
     *
     * @return void
     */
    public function testNonAdminCannotChangeAdminPassword(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);

        $response = $this->post('/home/save/1', [
            'username' => 'admin',
            'current_password' => 'pointofsale',
            'password' => 'hacked123'
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);

        // Verify admin password was NOT changed
        $employee = model(Employee::class);
        $admin = $employee->get_info(1);
        $this->assertTrue(password_verify('pointofsale', $admin->password),
            'Admin password should not have been changed by non-admin');
    }

    /**
     * Test user can view their own password change form
     *
     * @return void
     */
    public function testUserCanViewOwnPasswordForm(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);

        $response = $this->get('/home/changePassword/' . $nonAdminId);

        $response->assertStatus(200);
        $response->assertSee('nonadmin'); // Username should be visible
    }

    /**
     * Test user can change their own password
     *
     * @return void
     */
    public function testUserCanChangeOwnPassword(): void
    {
        $nonAdminId = $this->createNonAdminEmployee(['username' => 'nonadminchangeown']);
        $this->loginAs($nonAdminId);

        $response = $this->post('/home/save/' . $nonAdminId, [
            'username' => 'nonadminchangeown',
            'current_password' => 'password123',
            'password' => 'newpassword123'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);

        // Verify password was changed
        $employee = model(Employee::class);
        $user = $employee->get_info($nonAdminId);
        $this->assertTrue(password_verify('newpassword123', $user->password));
    }

    /**
     * Test admin can view any user's password form
     *
     * @return void
     */
    public function testAdminCanViewAnyPasswordForm(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->resetSession(); // Login as admin

        $response = $this->get('/home/changePassword/' . $nonAdminId);

        $response->assertStatus(200);
        $response->assertSee('nonadmin');
    }

    /**
     * Test admin can change any user's password
     *
     * @return void
     */
    public function testAdminCanChangeAnyPassword(): void
    {
        $nonAdminId = $this->createNonAdminEmployee(['username' => 'nonadminadminchange']);
        $this->resetSession(); // Login as admin

        $response = $this->post('/home/save/' . $nonAdminId, [
            'username' => 'nonadminadminchange',
            'current_password' => 'password123',
            'password' => 'adminset123'
        ]);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertTrue($result['success']);

        // Verify password was changed
        $employee = model(Employee::class);
        $user = $employee->get_info($nonAdminId);
        $this->assertTrue(password_verify('adminset123', $user->password));
    }

    /**
     * Test default employee_id parameter uses current user
     *
     * @return void
     */
    public function testDefaultEmployeeIdUsesCurrentUser(): void
    {
        $nonAdminId = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId);

        // Calling without employee_id should use current user
        $response = $this->get('/home/changePassword');

        $response->assertStatus(200);
        $response->assertSee('nonadmin');
    }

    /**
     * Test non-admin cannot view another non-admin's password form
     * IDOR vulnerability fix.
     *
     * @return void
     */
    public function testNonAdminCannotViewOtherNonAdminPasswordForm(): void
    {
        $nonAdminId1 = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId1);

        $otherUserId = $this->createNonAdminEmployee([
            'username' => 'otheruser',
            'email' => 'other@test.com',
            'password' => 'password456'
        ]);

        $response = $this->get('/home/changePassword/' . $otherUserId);

        $response->assertStatus(403);
    }

    /**
     * Test non-admin cannot change another non-admin's password
     * IDOR vulnerability fix.
     *
     * @return void
     */
    public function testNonAdminCannotChangeOtherNonAdminPassword(): void
    {
        $nonAdminId1 = $this->createNonAdminEmployee();
        $this->loginAs($nonAdminId1);

        $victimId = $this->createNonAdminEmployee([
            'username' => 'victimuser',
            'email' => 'victim@test.com',
            'password' => 'victimpass123'
        ]);

        $response = $this->post('/home/save/' . $victimId, [
            'username' => 'victimuser',
            'current_password' => 'victimpass123',
            'password' => 'hacked123456'
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);

        // Verify victim's password was NOT changed
        $employeeModel = model(Employee::class);
        $victim = $employeeModel->get_info($victimId);
        $this->assertTrue(password_verify('victimpass123', $victim->password),
            'Non-admin should not be able to change another non-admin password');
    }

    /**
     * Regression test: Home::__construct() previously
     * read the raw (single-decoded) URI segment to decide whether to skip
     * Secure_Controller's module-grant check for 'logout'. A route whose
     * double-decoded method name resolves to 'logout' must still be treated
     * as logout consistently - using the router's fully-resolved method name
     * removes any single-vs-double-decode mismatch as an attack surface.
     *
     * @return void
     */
    public function testLogoutStillBypassesGrantCheckAfterFix(): void
    {
        $nonAdminId = $this->createNonAdminEmployee([
            'username' => 'logoutnograntuser',
            'email' => 'logoutnograntuser@test.com',
            'grants' => []
        ]);
        $this->loginAs($nonAdminId);

        $response = $this->get('/home/logout');

        $response->assertRedirectTo('login');
    }

    /**
     * A non-'logout' Home method must still enforce Secure_Controller's
     * module-grant check (i.e. parent::__construct() is not skipped) for a
     * logged-in employee with no 'home' grant.
     *
     * @return void
     */
    public function testNonLogoutMethodStillEnforcesModuleGrantCheck(): void
    {
        $nonAdminId = $this->createNonAdminEmployee([
            'username' => 'nogranthome',
            'email' => 'nogranthome@test.com',
            'grants' => []
        ]);
        $this->loginAs($nonAdminId);

        $response = $this->get('/home/changePassword/' . $nonAdminId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    private function withSecondBranch(callable $test): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->db->table('employee_branches')->where('branch_id !=', 1)->delete();
        $this->db->table('branches')->where('branch_id !=', 1)->delete();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->db->table('branches')->insert(['company_id' => 1, 'code' => 'B2', 'name' => 'Second branch']);

        $branchId = (int) $this->db->insertID();

        try {
            $test($branchId);
        } finally {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');
            $this->db->table('employee_branches')->where('branch_id', $branchId)->delete();
            $this->db->table('branches')->where('branch_id', $branchId)->delete();
            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
            service('session')->remove('current_branch_id');
        }
    }

    private function withEveryBranch(int $personId): void
    {
        $this->db->table('grants')->where('person_id', $personId)->where('permission_id', BranchGuard::ALL_BRANCHES)->delete();
        $this->db->table('grants')->insert(['permission_id' => BranchGuard::ALL_BRANCHES, 'person_id' => $personId, 'menu_group' => '--']);
    }

    public function testPostSwitchBranch_RemembersABranchTheEmployeeMayUse(): void
    {
        $this->withSecondBranch(function (int $branchId): void {
            $this->withEveryBranch(1);
            $this->resetSession();

            $result = json_decode($this->post('/home/switchBranch', ['branch_id' => (string) $branchId])->getJSON(), true);

            $this->assertTrue($result['success']);
            $this->assertSame((string) $branchId, service('session')->get('current_branch_id'));
        });
    }

    public function testPostSwitchBranch_AcceptsEveryBranchAsNoChoice(): void
    {
        $this->withSecondBranch(function (int $branchId): void {
            $this->withEveryBranch(1);
            $this->resetSession();
            $this->post('/home/switchBranch', ['branch_id' => (string) $branchId]);

            $result = json_decode($this->post('/home/switchBranch', ['branch_id' => ''])->getJSON(), true);

            $this->assertTrue($result['success']);
            $this->assertSame('all', service('session')->get('current_branch_id'));
        });
    }

    public function testPostSwitchBranch_RefusesABranchOutsideTheEmployeesScope(): void
    {
        $this->withSecondBranch(function (int $branchId): void {
            $employeeId = $this->createNonAdminEmployee();
            $this->db->table('employee_branches')->insert(['person_id' => $employeeId, 'branch_id' => 1, 'is_default' => 1]);
            $this->loginAs($employeeId);

            $result = json_decode($this->post('/home/switchBranch', ['branch_id' => (string) $branchId])->getJSON(), true);

            $this->assertFalse($result['success']);
            $this->assertNull(service('session')->get('current_branch_id'));
        });
    }

    public function testGetIndex_ShowsTheBranchSwitcherOnlyToSomeoneWhoCanUseSeveralBranches(): void
    {
        $this->withSecondBranch(function (): void {
            $this->withEveryBranch(1);
            $this->resetSession();

            $this->get('/home')->assertSee('id="branch_switcher"');

            $employeeId = $this->createNonAdminEmployee();
            $this->db->table('employee_branches')->insert(['person_id' => $employeeId, 'branch_id' => 1, 'is_default' => 1]);
            $this->loginAs($employeeId);

            $this->get('/home')->assertDontSee('id="branch_switcher"');
        });
    }
    public function testPostSave_RejectsAPasswordEqualToTheUsername(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'accountant-one']);
        $this->withSession(['person_id' => $personId]);

        $result = json_decode($this->post('/home/save', [
            'username'         => 'accountant-one',
            'current_password' => 'password123',
            'password'         => 'accountant-one',
        ])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Employees.password_same_as_username'), $result['message']);
        $this->assertTrue(password_verify('password123', model(Employee::class)->get_info($personId)->password));
    }

    public function testPostSave_RejectsThePasswordTheInstallerGives(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'installer-user']);
        $this->withSession(['person_id' => $personId]);

        $result = json_decode($this->post('/home/save', [
            'username'         => 'installer-user',
            'current_password' => 'password123',
            'password'         => 'pointofsale',
        ])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Employees.password_is_default'), $result['message']);
    }
    public function testGetMustChangePassword_ShowsTheFormToTheEmployeeWhoIsSignedIn(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'forced-form']);
        $this->withSession(['person_id' => $personId]);

        $result = $this->get('/home/mustChangePassword');

        $result->assertStatus(200);
        $result->assertSee(lang('Employees.must_change_password'));
        $result->assertSeeElement('input[name=current_password]');
        $result->assertSeeElement('input[name=password]');
        $result->assertSeeElement('input[name=repeat_password]');
        $result->assertSee('value="forced-form"');
    }

    public function testPostSave_TakesTheMustChangeFlagAwayWhenTheEmployeeChangesTheirOwnPassword(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'forced-own']);
        $this->withSession(['person_id' => $personId, Employee::MUST_CHANGE_KEY => true]);

        $result = json_decode($this->post('/home/save', [
            'username'         => 'forced-own',
            'current_password' => 'password123',
            'password'         => 'a-better-password',
        ])->getJSON(), true);

        $this->assertTrue($result['success']);
        $this->assertNull(session()->get(Employee::MUST_CHANGE_KEY));
    }

    public function testPostSave_KeepsTheMustChangeFlagWhenThePasswordIsRefused(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'forced-bad']);
        $this->withSession(['person_id' => $personId, Employee::MUST_CHANGE_KEY => true]);

        $result = json_decode($this->post('/home/save', [
            'username'         => 'forced-bad',
            'current_password' => 'password123',
            'password'         => 'short',
        ])->getJSON(), true);

        $this->assertFalse($result['success']);
        $this->assertTrue(session()->get(Employee::MUST_CHANGE_KEY));
    }
    public function testPostSave_AuditsTheChangeOfTheOwnPassword(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'audit-own']);
        $this->withSession(['person_id' => $personId]);

        $this->post('/home/save', ['username' => 'audit-own', 'current_password' => 'password123', 'password' => 'a-better-password']);

        $entry = Database::connect()->table('accounting_audit_log')->where(['action' => 'employee_password_changed', 'entity_id' => $personId])->get()->getRow();
        $this->assertSame((string) $personId, (string) $entry->employee_id);
    }

    public function testPostSave_AuditsNothingWhenThePasswordIsRefused(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'audit-refused']);
        $this->withSession(['person_id' => $personId]);

        $this->post('/home/save', ['username' => 'audit-refused', 'current_password' => 'password123', 'password' => 'short']);

        $this->assertSame(0, Database::connect()->table('accounting_audit_log')->where('entity_id', $personId)->whereIn('action', ['employee_password_changed', 'employee_password_reset'])->countAllResults());
    }

    public function testGetLogout_AuditsTheSignOut(): void
    {
        $personId = $this->createNonAdminEmployee(['username' => 'audit-logout']);
        $this->withSession(['person_id' => $personId]);

        $this->get('/home/logout');

        $this->assertSame(1, Database::connect()->table('accounting_audit_log')->where(['action' => 'employee_logout', 'employee_id' => $personId])->countAllResults());
    }
}
