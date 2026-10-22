<?php

namespace Tests\Models;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use App\Libraries\Organization\BranchGuard;
use App\Models\Employee;
use Tests\Support\EmployeeFixtureTrait;

class EmployeeTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;

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
}
