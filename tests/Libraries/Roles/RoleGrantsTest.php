<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use App\Libraries\Roles\RoleTablesUnavailableException;
use App\Models\Employee;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\FailingTableConnection;
use Tests\Support\RoleFixtureTrait;

/**
 * @internal
 */
final class RoleGrantsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
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

    public function testIdsFor_ListsThePermissionsOfEveryActiveRoleOfTheEmployeeOnce(): void
    {
        $first    = $this->createRole(['sales', 'customers', 'items']);
        $second   = $this->createRole(['customers', 'receivings']);
        $employee = $this->employeeWithRoles([$first, $second]);

        $this->assertSame(['customers', 'items', 'receivings', 'sales'], (new RoleGrants())->idsFor($employee));
    }

    public function testIdsFor_IsEmptyForAnEmployeeWithoutRoles(): void
    {
        $this->assertSame([], (new RoleGrants())->idsFor($this->createEmployee()));
    }

    public function testIdsFor_IgnoresAnInactiveRole(): void
    {
        $active   = $this->createRole(['sales']);
        $inactive = $this->createRole(['items'], active: false);
        $employee = $this->employeeWithRoles([$active, $inactive]);

        $this->assertSame(['sales'], (new RoleGrants())->idsFor($employee));
    }

    public function testIdsFor_IgnoresTheRolesOfADeletedEmployee(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);

        $this->roleDb()->table('employees')->where('person_id', $employee)->update(['deleted' => 1]);
        RoleGrants::flush();

        $this->assertSame([], (new RoleGrants())->idsFor($employee));
    }

    #[DataProvider('provideIdsFor_IgnoresAForbiddenPermissionWrittenBehindTheServiceBack')]
    public function testIdsFor_IgnoresAForbiddenPermissionWrittenBehindTheServiceBack(string $permissionId): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);

        $this->roleDb()->table('role_permissions')->insert(['role_id' => $role, 'permission_id' => $permissionId]);
        RoleGrants::flush();

        $this->assertSame(['sales'], (new RoleGrants())->idsFor($employee));
        $this->assertFalse((new RoleGrants())->has($employee, $permissionId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideIdsFor_IgnoresAForbiddenPermissionWrittenBehindTheServiceBack(): iterable
    {
        $cases = [];

        foreach (RoleGrants::forbidden() as $permissionId) {
            $cases[$permissionId] = [$permissionId];
        }

        return $cases;
    }

    public function testForbidden_CoversEverySensitivePermissionAndTheBranchScope(): void
    {
        $forbidden = RoleGrants::forbidden();

        foreach (AccountingGuard::SENSITIVE as $permissionId) {
            $this->assertContains($permissionId, $forbidden, $permissionId);
        }

        $this->assertContains(BranchGuard::ALL_BRANCHES, $forbidden);
        $this->assertContains(BranchGuard::SCOPE_MANAGE, $forbidden);
        $this->assertNotContains('sales', $forbidden);
    }

    public function testForbidden_CoversEveryAccountingPermissionThatMovesMoneyOrChangesTheSettings(): void
    {
        $forbidden = RoleGrants::forbidden();

        foreach (RoleGrants::REFUSED as $permissionId) {
            $this->assertContains($permissionId, $forbidden, $permissionId);
            $this->assertStringStartsWith('acc_', $permissionId);
        }

        foreach ([AccountingGuard::CHEQUES_MANAGE, AccountingGuard::DEBIT_NOTES_MANAGE, AccountingGuard::JOURNALS_POST, AccountingGuard::JOURNALS_REVERSE] as $permissionId) {
            $this->assertContains($permissionId, $forbidden, $permissionId);
        }

        foreach (RoleGrants::ALLOWED_ACCOUNTING as $permissionId) {
            $this->assertNotContains($permissionId, $forbidden, $permissionId);
        }
    }

    public function testForbidden_ClassifiesEveryAccountingPermissionOfTheCatalogSoANewOneIsNeverHandedToARoleUnnoticed(): void
    {
        $known = [...AccountingGuard::SENSITIVE, ...RoleGrants::REFUSED, ...RoleGrants::ALLOWED_ACCOUNTING];
        $ids   = array_filter(array_column($this->roleDb()->table('permissions')->select('permission_id')->get()->getResultArray(), 'permission_id'), static fn (string $id): bool => str_starts_with($id, 'acc_'));

        $this->assertNotSame([], $ids);

        foreach ($ids as $permissionId) {
            $this->assertContains($permissionId, $known, $permissionId . ' is neither sensitive, refused to roles nor allowed to them');
        }
    }

    public function testIdsFor_ReadsOncePerEmployeeUntilSomethingIsFlushed(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);
        $grants   = new RoleGrants();

        $this->assertSame(['sales'], $grants->idsFor($employee));

        $this->roleDb()->table('role_permissions')->insert(['role_id' => $role, 'permission_id' => 'items']);

        $this->assertSame(['sales'], $grants->idsFor($employee), 'The second read of a request is the first one');

        RoleGrants::flush();

        $this->assertSame(['items', 'sales'], $grants->idsFor($employee));
    }

    public function testIdsFor_ReadsTheRolesAnEmployeeIsGivenThroughTheService(): void
    {
        $grants   = new RoleGrants();
        $employee = $this->createEmployee();

        $this->assertSame([], $grants->idsFor($employee));

        (new RoleService())->assign($employee, [$this->createRole(['sales'])], null);

        $this->assertSame(['sales'], $grants->idsFor($employee), 'Giving a role flushes what every reader of the request has read');
    }

    public function testHas_ChecksTheExactPermissionId(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales_delete'])]);
        $grants   = new RoleGrants();

        $this->assertTrue($grants->has($employee, 'sales_delete'));
        $this->assertFalse($grants->has($employee, 'sales'));
        $this->assertFalse($grants->has($employee, 'sales_delete_all'));
    }

    public function testHasGrant_StaysAnAnswerOfTheEmployeeModelForRoleHolders(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales_delete'])]);
        $model    = model(Employee::class);

        $this->assertTrue($model->has_grant('sales_delete', $employee));
        $this->assertFalse($model->has_grant('sales_delete', $employee, true), 'Direct only does not see the role');
    }

    public function testIdsFor_AsksAgainWhetherTheMigrationRanOnceTheGrantsWereFlushed(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);
        $grants   = new RoleGrants();

        $this->assertSame(['sales'], $grants->idsFor($employee));

        $this->withoutRoleTables(function () use ($grants, $employee): void {
            $this->assertSame([], $grants->idsFor($employee));
        });

        $this->assertSame(['sales'], $grants->idsFor($employee));
    }

    public function testIdsFor_IsEmptyBeforeTheMigrationHasRunEvenWhenTheTablesAreThere(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);

        $this->withoutTheMigrationRecord(function () use ($employee): void {
            $this->assertSame([], (new RoleGrants())->idsFor($employee));
            $this->assertFalse(model(Employee::class)->has_grant('sales', $employee));
        });

        $this->assertSame(['sales'], (new RoleGrants())->idsFor($employee));
    }

    public function testIdsFor_RefusesWhenTheMigrationIsRecordedButATableOfRolesIsGone(): void
    {
        $db       = $this->roleDb();
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);
        $table    = $db->prefixTable('employee_roles');

        RoleGrants::flush();
        $db->query("RENAME TABLE `{$table}` TO `{$table}_away`");

        try {
            (new RoleGrants())->idsFor($employee);
            $this->fail('Roles that could not be read were taken for roles that give nothing.');
        } catch (RoleTablesUnavailableException $exception) {
            $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
        } finally {
            $db->query("RENAME TABLE `{$table}_away` TO `{$table}`");
            RoleGrants::flush();
        }
    }

    private function failingConnection(?string $table = null, bool $throws = false): FailingTableConnection
    {
        $db               = new FailingTableConnection(config('Database')->tests);
        $db->failingTable = $table;
        $db->throws       = $throws;

        return $db;
    }

    public function testIdsFor_RefusesWhenTheRolesOfTheEmployeeCannotBeRead(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);

        foreach ([false, true] as $throws) {
            foreach (['employee_roles', 'roles', 'role_permissions', 'employees'] as $table) {
                try {
                    (new RoleGrants($this->failingConnection($table, $throws)))->idsFor($employee);
                    $this->fail("Roles that could not be read ({$table}) were taken for roles that give nothing.");
                } catch (RoleTablesUnavailableException $exception) {
                    $this->assertStringContainsString("The permissions that the roles of the employee {$employee} give could not be read: ", $exception->getMessage());
                    $this->assertSame($throws, $exception->getPrevious() instanceof DatabaseException);
                }
            }
        }
    }

    public function testHas_RefusesWhenThePermissionsCannotBeReadInsteadOfAnswering(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);

        foreach ([false, true] as $throws) {
            try {
                (new RoleGrants($this->failingConnection('role_permissions', $throws)))->has($employee, 'sales');
                $this->fail('Permissions that could not be read were taken for permissions that are not given.');
            } catch (RoleTablesUnavailableException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testIdsFor_DoesNotRememberAFailureAndReadsOnceItWorks(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);

        foreach ([false, true] as $throws) {
            $connection = $this->failingConnection('role_permissions', $throws);
            $grants     = new RoleGrants($connection);

            try {
                $grants->idsFor($employee);
                $this->fail('Roles that could not be read were taken for roles that give nothing.');
            } catch (RoleTablesUnavailableException) {
                $connection->failingTable = null;
            }

            $this->assertSame(['sales'], $grants->idsFor($employee));
        }
    }

    public function testIdsFor_ReadsThroughAConnectionThatDoesNotFail(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales', 'items'])]);

        $this->assertSame(['items', 'sales'], (new RoleGrants($this->failingConnection()))->idsFor($employee));
    }

    public function testIdsFor_RefusesOnAProductionConnectionWhoseTableOfPermissionsCannotBeRead(): void
    {
        $employee = $this->employeeWithRoles([$this->createRole(['sales'])]);

        $this->withUnreadableTable('role_permissions', function () use ($employee): void {
            try {
                (new RoleGrants())->idsFor($employee);
                $this->fail('Roles that could not be read were taken for roles that give nothing.');
            } catch (RoleTablesUnavailableException $exception) {
                $this->assertNull($exception->getPrevious());
                $this->assertStringContainsString($this->roleDb()->prefixTable('role_permissions'), $exception->getMessage(), 'The message carries what the driver said');
            }
        });
    }
}
