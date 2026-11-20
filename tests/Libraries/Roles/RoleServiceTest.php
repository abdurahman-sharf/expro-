<?php

namespace Tests\Libraries\Roles;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Roles\RecordScope;
use App\Libraries\Roles\RoleError;
use App\Libraries\Roles\RoleException;
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
final class RoleServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use EmployeeFixtureTrait;
    use RoleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private RoleService $roles;

    public static function setUpBeforeClass(): void
    {
        Database::seeder('tests')->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        RoleGrants::flush();

        $this->roles = new RoleService();
    }

    private function rolesOverAFailingTable(?string $table, bool $throws): RoleService
    {
        $db               = new FailingTableConnection(config('Database')->tests);
        $db->failingTable = $table;
        $db->throws       = $throws;

        return new RoleService($db);
    }

    private function refusal(callable $action): RoleException
    {
        try {
            $action();
        } catch (RoleException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    private function unavailable(callable $action, string $case = ''): RoleTablesUnavailableException
    {
        try {
            $action();
        } catch (RoleTablesUnavailableException $exception) {
            return $exception;
        }

        $this->fail('A read that failed was not refused with the named exception: ' . $case);
    }

    /**
     * @return list<object>
     */
    private function auditOf(string $entityType, int $entityId, string $action): array
    {
        return $this->roleDb()->table('accounting_audit_log')->where(['entity_type' => $entityType, 'entity_id' => $entityId, 'action' => $action])->orderBy('audit_id', 'ASC')->get()->getResult();
    }

    public function testCreate_StoresTheRoleWithItsPermissionsAndWritesTheAuditEntry(): void
    {
        $manager = $this->rolesManager(['sales', 'customers']);
        $roleId  = $this->roles->create(['name' => '  Cashier  ', 'description' => 'The tills', 'record_scope' => 'own', 'permissions' => ['sales', 'customers', 'sales']], $manager);
        $role    = $this->roles->find($roleId);

        $this->assertSame('Cashier', $role->name);
        $this->assertSame('The tills', $role->description);
        $this->assertSame('1', (string) $role->active);
        $this->assertSame('own', $role->record_scope);
        $this->assertSame(['customers', 'sales'], $this->roles->permissionsOf($roleId));

        $audit = $this->auditOf('role', $roleId, 'role_created');

        $this->assertCount(1, $audit);
        $this->assertSame((string) $manager, (string) $audit[0]->employee_id);
        $this->assertSame(['customers', 'sales'], json_decode($audit[0]->details, true)['permissions']);
    }

    public function testCreate_StartsWithNoPermissionAndTheScopeAll(): void
    {
        $roleId = $this->roles->create(['name' => 'Empty ' . uniqid()], null);

        $this->assertSame([], $this->roles->permissionsOf($roleId));
        $this->assertSame('all', $this->roles->find($roleId)->record_scope);
    }

    #[DataProvider('sensitivePermissions')]
    public function testCreate_RejectsASensitivePermissionWithANamedError(string $permissionId): void
    {
        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'Sensitive ' . uniqid(), 'permissions' => ['sales', $permissionId]], null));

        $this->assertSame(RoleError::SensitivePermission, $refusal->error);
        $this->assertSame($permissionId, $refusal->context['permission']);
        $this->assertSame(0, $this->roleDb()->table('roles')->like('name', 'Sensitive ', 'after')->countAllResults(), 'A refused role is not left behind');
    }

    #[DataProvider('sensitivePermissions')]
    public function testUpdate_RejectsASensitivePermissionWithANamedErrorAndKeepsWhatTheRoleHad(string $permissionId): void
    {
        $roleId = $this->createRole(['sales']);

        $refusal = $this->refusal(fn () => $this->roles->update($roleId, ['name' => 'Renamed ' . uniqid(), 'permissions' => ['sales', $permissionId]], null));

        $this->assertSame(RoleError::SensitivePermission, $refusal->error);
        $this->assertSame(['sales'], $this->roles->permissionsOf($roleId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function sensitivePermissions(): iterable
    {
        $cases = [];

        foreach (AccountingGuard::SENSITIVE as $permissionId) {
            $cases[$permissionId] = [$permissionId];
        }

        return $cases;
    }

    #[DataProvider('refusedPermissions')]
    public function testCreate_RejectsAPermissionThatMovesMoneyOrChangesTheSettingsWithANamedError(string $permissionId): void
    {
        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'Refused ' . uniqid(), 'permissions' => ['sales', $permissionId]], null));

        $this->assertSame(RoleError::RefusedPermission, $refusal->error);
        $this->assertSame($permissionId, $refusal->context['permission']);
        $this->assertSame(0, $this->roleDb()->table('roles')->like('name', 'Refused ', 'after')->countAllResults(), 'A refused role is not left behind');
    }

    #[DataProvider('refusedPermissions')]
    public function testUpdate_RejectsAPermissionThatMovesMoneyOrChangesTheSettingsAndKeepsWhatTheRoleHad(string $permissionId): void
    {
        $roleId = $this->createRole(['sales']);

        $refusal = $this->refusal(fn () => $this->roles->update($roleId, ['name' => 'Renamed ' . uniqid(), 'permissions' => ['sales', $permissionId]], null));

        $this->assertSame(RoleError::RefusedPermission, $refusal->error);
        $this->assertSame(['sales'], $this->roles->permissionsOf($roleId));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedPermissions(): iterable
    {
        $cases = [];

        foreach (RoleGrants::REFUSED as $permissionId) {
            $cases[$permissionId] = [$permissionId];
        }

        return $cases;
    }

    public function testCreate_AcceptsTheAccountingPermissionsThatOnlyReadOrPrepare(): void
    {
        $roleId = $this->createRole(RoleGrants::ALLOWED_ACCOUNTING);

        $this->assertEqualsCanonicalizing(RoleGrants::ALLOWED_ACCOUNTING, $this->roles->permissionsOf($roleId));
    }

    public function testCreate_RejectsThePermissionToManageRolesSoARoleCannotHandItOut(): void
    {
        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'Self ' . uniqid(), 'permissions' => [AccountingGuard::ROLES_MANAGE]], null));

        $this->assertSame(RoleError::SensitivePermission, $refusal->error);
        $this->assertSame(AccountingGuard::ROLES_MANAGE, $refusal->context['permission']);
    }

    #[DataProvider('provideCreate_RejectsABranchScopeSoARoleNeverGrantsBranchAccess')]
    public function testCreate_RejectsABranchScopeSoARoleNeverGrantsBranchAccess(string $permissionId): void
    {
        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'Branch ' . uniqid(), 'permissions' => [$permissionId]], null));

        $this->assertSame(RoleError::BranchPermission, $refusal->error);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideCreate_RejectsABranchScopeSoARoleNeverGrantsBranchAccess(): iterable
    {
        return ['the all-branches grant' => [BranchGuard::ALL_BRANCHES], 'the permission to set branches' => [BranchGuard::SCOPE_MANAGE]];
    }

    public function testCreate_RejectsAPermissionThatDoesNotExist(): void
    {
        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'Unknown ' . uniqid(), 'permissions' => ['no_such_permission']], null));

        $this->assertSame(RoleError::UnknownPermission, $refusal->error);
        $this->assertSame('no_such_permission', $refusal->context['permission']);
    }

    /**
     * @param array<string, mixed> $input
     */
    #[DataProvider('provideCreate_RejectsARoleThatIsNotValid')]
    public function testCreate_RejectsARoleThatIsNotValid(array $input, RoleError $error): void
    {
        $this->assertSame($error, $this->refusal(fn () => $this->roles->create($input, null))->error);
    }

    /**
     * @return array<string, array{array<string, mixed>, RoleError}>
     */
    public static function provideCreate_RejectsARoleThatIsNotValid(): iterable
    {
        return [
            'an empty name'                   => [['name' => '   '], RoleError::InvalidName],
            'no name'                         => [[], RoleError::InvalidName],
            'a name that is a list'           => [['name' => ['x']], RoleError::InvalidName],
            'a name over 100'                 => [['name' => str_repeat('n', 101)], RoleError::InvalidName],
            'a scope that is unknown'         => [['name' => 'Scoped', 'record_scope' => 'branch'], RoleError::InvalidScope],
            'a scope that is a list'          => [['name' => 'Scoped', 'record_scope' => ['own']], RoleError::InvalidScope],
            'a description over 255'          => [['name' => 'Described', 'description' => str_repeat('d', 256)], RoleError::InvalidDescription],
            'a description that is a list'    => [['name' => 'Described', 'description' => ['d']], RoleError::InvalidDescription],
            'permissions that are not a list' => [['name' => 'Listed', 'permissions' => 'sales'], RoleError::UnknownPermission],
            'a permission that is not text'   => [['name' => 'Listed', 'permissions' => [1]], RoleError::UnknownPermission],
        ];
    }

    public function testCreate_RejectsANameAnotherRoleUsesWhateverTheCase(): void
    {
        $this->roles->create(['name' => 'Accountant Taken'], null);

        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'ACCOUNTANT taken'], null));

        $this->assertSame(RoleError::NameTaken, $refusal->error);
    }

    public function testUpdate_AcceptsTheOwnNameOfTheRoleAgain(): void
    {
        $roleId = $this->roles->create(['name' => 'Keeps its name'], null);

        $this->roles->update($roleId, ['name' => 'Keeps its name', 'description' => 'Now described'], null);

        $this->assertSame('Now described', $this->roles->find($roleId)->description);
    }

    public function testCreate_RequiresThePermissionToManageRoles(): void
    {
        $plain = $this->createEmployee(grants: [['permission_id' => 'employees', 'menu_group' => 'home'], ['permission_id' => 'roles', 'menu_group' => 'home']]);

        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'Not allowed ' . uniqid()], $plain));

        $this->assertSame(RoleError::PermissionDenied, $refusal->error);
    }

    public function testCreate_AcceptsTheBooksActingWithoutAnEmployee(): void
    {
        $this->assertGreaterThan(0, $this->roles->create(['name' => 'By the console ' . uniqid()], null));
    }

    public function testUpdate_RequiresThePermissionToManageRoles(): void
    {
        $roleId = $this->createRole(['sales']);
        $plain  = $this->createEmployee();

        $this->assertSame(RoleError::PermissionDenied, $this->refusal(fn () => $this->roles->update($roleId, ['name' => 'Changed ' . uniqid()], $plain))->error);
    }

    public function testDelete_RequiresThePermissionToManageRoles(): void
    {
        $roleId = $this->createRole();
        $plain  = $this->createEmployee();

        $this->assertSame(RoleError::PermissionDenied, $this->refusal(fn () => $this->roles->delete($roleId, $plain))->error);
        $this->assertNotNull($this->roles->find($roleId));
    }

    public function testAssign_RequiresThePermissionToManageRoles(): void
    {
        $roleId   = $this->createRole(['sales']);
        $plain    = $this->createEmployee();
        $employee = $this->createEmployee();

        $this->assertSame(RoleError::PermissionDenied, $this->refusal(fn () => $this->roles->assign($employee, [$roleId], $plain))->error);
        $this->assertSame([], $this->roles->roleIdsOf($employee));
    }

    public function testCreate_RejectsAPermissionTheActorDoesNotHoldUnlessTheActorIsAnAdministrator(): void
    {
        $manager = $this->rolesManager(['sales']);

        $refusal = $this->refusal(fn () => $this->roles->create(['name' => 'Wider ' . uniqid(), 'permissions' => ['sales', 'items']], $manager));

        $this->assertSame(RoleError::CannotGrant, $refusal->error);
        $this->assertSame('items', $refusal->context['permission']);

        $this->assertGreaterThan(0, $this->roles->create(['name' => 'As wide ' . uniqid(), 'permissions' => ['sales']], $manager));
        $this->assertGreaterThan(0, $this->roles->create(['name' => 'By an administrator ' . uniqid(), 'permissions' => ['sales', 'items']], $this->rolesAdministrator()));
    }

    public function testCreate_AcceptsAPermissionTheActorHoldsOnlyThroughARole(): void
    {
        $manager = $this->rolesManager();

        $this->roles->assign($manager, [$this->createRole(['items'])], null);

        $this->assertGreaterThan(0, $this->roles->create(['name' => 'Through a role ' . uniqid(), 'permissions' => ['items']], $manager));
    }

    public function testUpdate_AllowsAnActorToKeepAPermissionTheyDoNotHoldButNotToAddOne(): void
    {
        $roleId  = $this->createRole(['sales', 'items']);
        $manager = $this->rolesManager(['sales']);

        $this->roles->update($roleId, ['name' => 'Renamed ' . uniqid(), 'permissions' => ['sales', 'items']], $manager);
        $this->roles->update($roleId, ['name' => 'Renamed again ' . uniqid(), 'permissions' => ['sales']], $manager);

        $this->assertSame(['sales'], $this->roles->permissionsOf($roleId));
        $this->assertSame(RoleError::CannotGrant, $this->refusal(fn () => $this->roles->update($roleId, ['name' => 'Renamed three ' . uniqid(), 'permissions' => ['sales', 'items']], $manager))->error);
    }

    public function testUpdate_ChangesTheFieldsAndAuditsOnlyWhatChanged(): void
    {
        $manager = $this->rolesManager(['sales', 'items', 'customers']);
        $roleId  = $this->roles->create(['name' => 'Before ' . uniqid(), 'permissions' => ['sales', 'items']], $manager);
        $newName = 'After ' . uniqid();

        $this->roles->update($roleId, ['name' => $newName, 'description' => 'More', 'active' => false, 'record_scope' => 'own', 'permissions' => ['items', 'customers']], $manager);

        $role = $this->roles->find($roleId);

        $this->assertSame($newName, $role->name);
        $this->assertSame('0', (string) $role->active);
        $this->assertSame('own', $role->record_scope);
        $this->assertSame(['customers', 'items'], $this->roles->permissionsOf($roleId));

        $updated = $this->auditOf('role', $roleId, 'role_updated');

        $this->assertCount(1, $updated);
        $this->assertSame($newName, json_decode($updated[0]->details, true)['after']['name']);

        $permissions = $this->auditOf('role', $roleId, 'role_permissions_changed');

        $this->assertCount(1, $permissions);
        $this->assertSame(['customers'], json_decode($permissions[0]->details, true)['added']);
        $this->assertSame(['sales'], json_decode($permissions[0]->details, true)['removed']);
    }

    public function testUpdate_WritesNoEntryWhenNothingChanges(): void
    {
        $name   = 'Same ' . uniqid();
        $roleId = $this->roles->create(['name' => $name, 'permissions' => ['sales']], null);

        $this->roles->update($roleId, ['name' => $name, 'permissions' => ['sales']], null);

        $this->assertSame([], $this->auditOf('role', $roleId, 'role_updated'));
        $this->assertSame([], $this->auditOf('role', $roleId, 'role_permissions_changed'));
    }

    public function testUpdate_KeepsThePermissionsWhenTheInputNamesNone(): void
    {
        $roleId = $this->createRole(['sales']);

        $this->roles->update($roleId, ['name' => 'Only renamed ' . uniqid()], null);

        $this->assertSame(['sales'], $this->roles->permissionsOf($roleId));
    }

    public function testUpdate_RejectsARoleThatDoesNotExist(): void
    {
        $this->assertSame(RoleError::NotFound, $this->refusal(fn () => $this->roles->update(999999, ['name' => 'Ghost'], null))->error);
    }

    public function testUpdate_StoresTheSubmittedSetWithoutAddingTheOptionsItImpliesAsTheReferenceSystemDoes(): void
    {
        $roleId = $this->createRole(['sales_delete', 'sales']);

        $this->assertSame(['sales', 'sales_delete'], $this->roles->permissionsOf($roleId));

        $this->roles->update($roleId, ['name' => 'Delete only ' . uniqid(), 'permissions' => ['sales_delete']], null);

        $employee = $this->employeeWithRoles([$roleId]);
        $model    = model(Employee::class);

        $this->assertSame(['sales_delete'], $this->roles->permissionsOf($roleId));
        $this->assertTrue($model->has_grant('sales_delete', $employee));
        $this->assertFalse($model->has_grant('sales', $employee), 'Each check rests on the explicit grant');
    }

    public function testDelete_RemovesARoleNobodyHoldsAndAudits(): void
    {
        $roleId = $this->createRole(['sales']);

        $this->roles->delete($roleId, null);

        $this->assertNull($this->roles->find($roleId));
        $this->assertSame(0, $this->roleDb()->table('role_permissions')->where('role_id', $roleId)->countAllResults());
        $this->assertCount(1, $this->auditOf('role', $roleId, 'role_deleted'));
    }

    public function testDelete_RejectsARoleEmployeesHold(): void
    {
        $roleId = $this->createRole(['sales']);

        $this->employeeWithRoles([$roleId]);

        $this->assertSame(RoleError::InUse, $this->refusal(fn () => $this->roles->delete($roleId, null))->error);
        $this->assertNotNull($this->roles->find($roleId));
    }

    public function testDelete_RemovesARoleOnlyDeletedEmployeesHeld(): void
    {
        $roleId   = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$roleId]);

        $this->roleDb()->table('employees')->where('person_id', $employee)->update(['deleted' => 1]);

        $this->roles->delete($roleId, null);

        $this->assertNull($this->roles->find($roleId));
    }

    public function testHolders_RefusesWhenTheHoldersCannotBeCounted(): void
    {
        $roleId = $this->createRole(['sales']);

        $this->employeeWithRoles([$roleId]);

        foreach ([false, true] as $throws) {
            $exception = $this->refusal(fn () => $this->rolesOverAFailingTable('employee_roles', $throws)->holders($roleId));

            $this->assertSame(RoleError::ReadFailed, $exception->error);

            if ($throws) {
                $this->assertInstanceOf(DatabaseException::class, $exception->getPrevious());
            }
        }
    }

    public function testDelete_RefusesAndKeepsTheRoleAndItsHoldersWhenTheHoldersCannotBeCounted(): void
    {
        $roleId   = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$roleId]);

        foreach ([false, true] as $throws) {
            $this->assertSame(RoleError::ReadFailed, $this->refusal(fn () => $this->rolesOverAFailingTable('employee_roles', $throws)->delete($roleId, null))->error);
        }

        $this->assertNotNull($this->roles->find($roleId));
        $this->assertSame([$roleId], $this->roles->roleIdsOf($employee));
        $this->assertSame([], $this->auditOf('role', $roleId, 'role_deleted'));
    }

    public function testDelete_KeepsTheOwnRecordsScopeOfItsHoldersWhenTheHoldersCannotBeCountedOnTheRealDriver(): void
    {
        $roleId   = $this->createRole([], 'own');
        $employee = $this->employeeWithRoles([$roleId]);

        $this->withUnreadableTable('employee_roles', function () use ($roleId): void {
            $exception = $this->refusal(fn () => $this->roles->delete($roleId, null));

            $this->assertSame(RoleError::ReadFailed, $exception->error);
            $this->assertNull($exception->getPrevious(), 'The connection answered false, as one without debugging does, and did not throw');
        });

        $this->assertNotNull($this->roles->find($roleId));
        $this->assertTrue((new RecordScope())->ownOnly($employee));
    }

    public function testAssign_GivesTheUnionOfTheirOwnGrantsAndTheGrantsOfEveryRoleWithoutCopyingAny(): void
    {
        $first    = $this->createRole(['sales', 'customers']);
        $second   = $this->createRole(['customers', 'receivings']);
        $employee = $this->createEmployee(grants: [['permission_id' => 'items', 'menu_group' => 'home']]);
        $model    = model(Employee::class);

        $this->roles->assign($employee, [$first, $second], null);

        foreach (['items', 'sales', 'customers', 'receivings'] as $permissionId) {
            $this->assertTrue($model->has_grant($permissionId, $employee), $permissionId);
        }

        $this->assertFalse($model->has_grant('suppliers', $employee));
        $this->assertSame(['items'], array_column($model->getDirectGrants($employee), 'permission_id'), 'Nothing was copied into the grants of the employee');
        $this->assertSame([$first, $second], $this->roles->roleIdsOf($employee));
    }

    public function testAssign_FollowsTheRoleWhenItChanges(): void
    {
        $roleId   = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$roleId]);
        $model    = model(Employee::class);

        $this->assertTrue($model->has_grant('sales', $employee));
        $this->assertFalse($model->has_grant('suppliers', $employee));

        $this->roles->update($roleId, ['name' => 'Moved ' . uniqid(), 'permissions' => ['suppliers']], null);

        $this->assertFalse($model->has_grant('sales', $employee));
        $this->assertTrue($model->has_grant('suppliers', $employee));

        $this->roles->update($roleId, ['name' => 'Moved ' . uniqid(), 'active' => false], null);

        $this->assertFalse($model->has_grant('suppliers', $employee), 'An inactive role gives nothing');
    }

    public function testAssign_ReplacesTheRolesOfTheEmployeeAndAuditsTheChange(): void
    {
        $manager  = $this->rolesManager(['sales', 'items']);
        $first    = $this->createRole(['sales']);
        $second   = $this->createRole(['items']);
        $employee = $this->createEmployee();

        $this->roles->assign($employee, [$first], $manager);
        $this->roles->assign($employee, [$second], $manager);
        $this->roles->assign($employee, [], $manager);

        $audit = $this->auditOf('employee', $employee, 'employee_roles_changed');

        $this->assertCount(3, $audit);
        $this->assertSame(['before' => [], 'after' => [$first]], json_decode($audit[0]->details, true));
        $this->assertSame(['before' => [$first], 'after' => [$second]], json_decode($audit[1]->details, true));
        $this->assertSame(['before' => [$second], 'after' => []], json_decode($audit[2]->details, true));
        $this->assertSame([], $this->roles->roleIdsOf($employee));
    }

    public function testAssign_WritesNoEntryWhenTheRolesStayTheSame(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);

        $before = count($this->auditOf('employee', $employee, 'employee_roles_changed'));

        $this->roles->assign($employee, [$role], null);

        $this->assertCount($before, $this->auditOf('employee', $employee, 'employee_roles_changed'));
    }

    public function testAssign_RejectsARoleThatDoesNotExistAndKeepsTheCurrentRoles(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);

        $this->assertSame(RoleError::NotFound, $this->refusal(fn () => $this->roles->assign($employee, [$role, 999999], null))->error);
        $this->assertSame(RoleError::NotFound, $this->refusal(fn () => $this->roles->assign($employee, ['x'], null))->error);
        $this->assertSame([$role], $this->roles->roleIdsOf($employee));
    }

    public function testAssign_RejectsAnEmployeeThatDoesNotExistOrWasDeleted(): void
    {
        $role    = $this->createRole(['sales']);
        $deleted = $this->createEmployee();

        $this->roleDb()->table('employees')->where('person_id', $deleted)->update(['deleted' => 1]);

        $this->assertSame(RoleError::EmployeeNotFound, $this->refusal(fn () => $this->roles->assign(999999, [$role], null))->error);
        $this->assertSame(RoleError::EmployeeNotFound, $this->refusal(fn () => $this->roles->assign($deleted, [$role], null))->error);
    }

    public function testAssign_RejectsARoleWithAPermissionTheActorDoesNotHoldUnlessTheActorIsAnAdministrator(): void
    {
        $role     = $this->createRole(['sales', 'items']);
        $manager  = $this->rolesManager(['sales']);
        $employee = $this->createEmployee();

        $refusal = $this->refusal(fn () => $this->roles->assign($employee, [$role], $manager));

        $this->assertSame(RoleError::CannotGrant, $refusal->error);
        $this->assertSame([], $this->roles->roleIdsOf($employee));

        $this->roles->assign($employee, [$role], $this->rolesAdministrator());

        $this->assertSame([$role], $this->roles->roleIdsOf($employee));
    }

    public function testAssign_LetsAnActorKeepARoleTheyCouldNotGive(): void
    {
        $wide     = $this->createRole(['sales', 'items']);
        $narrow   = $this->createRole(['sales']);
        $manager  = $this->rolesManager(['sales']);
        $employee = $this->employeeWithRoles([$wide]);

        $this->roles->assign($employee, [$wide, $narrow], $manager);

        $this->assertSame([$wide, $narrow], $this->roles->roleIdsOf($employee));
    }

    public function testCheckAssignment_RefusesWhatAssignAndSetRecordScopeWouldRefuseAndWritesNothing(): void
    {
        $wide     = $this->createRole(['sales', 'items']);
        $manager  = $this->rolesManager(['sales']);
        $employee = $this->createEmployee();
        $audits   = $this->roleDb()->table('accounting_audit_log')->countAllResults();

        $this->assertSame(RoleError::PermissionDenied, $this->refusal(fn () => $this->roles->checkAssignment($employee, [$wide], 'all', $this->createEmployee()))->error);
        $this->assertSame(RoleError::NotFound, $this->refusal(fn () => $this->roles->checkAssignment($employee, [999999], 'all', $manager))->error);
        $this->assertSame(RoleError::NotFound, $this->refusal(fn () => $this->roles->checkAssignment(null, ['x'], 'all', $manager))->error);
        $this->assertSame(RoleError::CannotGrant, $this->refusal(fn () => $this->roles->checkAssignment($employee, [$wide], 'all', $manager))->error);
        $this->assertSame(RoleError::CannotGrant, $this->refusal(fn () => $this->roles->checkAssignment(null, [$wide], 'all', $manager))->error, 'An employee not yet saved holds no role');
        $this->assertSame(RoleError::InvalidScope, $this->refusal(fn () => $this->roles->checkAssignment($employee, [], 'nobody', $manager))->error);

        $this->assertSame([], $this->roles->roleIdsOf($employee));
        $this->assertSame($audits, $this->roleDb()->table('accounting_audit_log')->countAllResults(), 'A check writes nothing');
    }

    public function testCheckAssignment_AcceptsWhatAnAdministratorOrAnActorWhoHoldsTheRolePermissionsMayGiveAndTheRolesAlreadyHeld(): void
    {
        $wide     = $this->createRole(['sales', 'items']);
        $narrow   = $this->createRole(['sales']);
        $manager  = $this->rolesManager(['sales']);
        $employee = $this->employeeWithRoles([$wide]);

        $this->roles->checkAssignment($employee, [$wide, $narrow], 'own', $manager);
        $this->roles->checkAssignment(null, [$narrow], 'all', $manager);
        $this->roles->checkAssignment(null, [$wide], 'own', $this->rolesAdministrator());
        $this->roles->checkAssignment(null, [$wide], 'all', null);
        $this->addToAssertionCount(1);
    }

    public function testSetRecordScope_StoresOwnForTheEmployeeAndAuditsTheChange(): void
    {
        $manager  = $this->rolesManager();
        $employee = $this->createEmployee();

        $this->assertSame('all', $this->roles->recordScopeOf($employee), 'An employee that was given nothing sees everything');

        $this->roles->setRecordScope($employee, 'own', $manager);
        $this->roles->setRecordScope($employee, 'own', $manager);

        $this->assertSame('own', $this->roles->recordScopeOf($employee));
        $this->assertCount(1, $this->auditOf('employee', $employee, 'employee_record_scope_changed'));

        $this->roles->setRecordScope($employee, 'all', $manager);

        $this->assertSame('all', $this->roles->recordScopeOf($employee));
        $this->assertSame(0, $this->roleDb()->table('employee_record_scopes')->where('person_id', $employee)->countAllResults(), 'No row means all');
        $this->assertCount(2, $this->auditOf('employee', $employee, 'employee_record_scope_changed'));
    }

    public function testSetRecordScope_RequiresThePermissionAndAKnownScopeAndAnEmployee(): void
    {
        $employee = $this->createEmployee();

        $this->assertSame(RoleError::PermissionDenied, $this->refusal(fn () => $this->roles->setRecordScope($employee, 'own', $this->createEmployee()))->error);
        $this->assertSame(RoleError::InvalidScope, $this->refusal(fn () => $this->roles->setRecordScope($employee, 'branch', null))->error);
        $this->assertSame(RoleError::EmployeeNotFound, $this->refusal(fn () => $this->roles->setRecordScope(999999, 'own', null))->error);
        $this->assertSame('all', $this->roles->recordScopeOf($employee));
    }

    public function testRolesOf_ListsTheRolesOfAnEmployeeByNameAndRolePermissionsOfNamesWhoGivesWhat(): void
    {
        $sales    = $this->roles->create(['name' => 'B sales ' . uniqid(), 'permissions' => ['sales', 'items']], null);
        $stock    = $this->roles->create(['name' => 'A stock ' . uniqid(), 'permissions' => ['items']], null);
        $off      = $this->roles->create(['name' => 'C off ' . uniqid(), 'active' => false, 'permissions' => ['customers']], null);
        $employee = $this->employeeWithRoles([$sales, $stock, $off]);

        $this->assertSame([$stock, $sales, $off], array_map(static fn (object $role): int => (int) $role->role_id, $this->roles->rolesOf($employee)));

        $given = $this->roles->rolePermissionsOf($employee);

        $this->assertSame(['items', 'sales'], array_keys($given));
        $this->assertCount(2, $given['items']);
        $this->assertCount(1, $given['sales']);
    }

    public function testAll_CountsThePermissionsAndTheEmployeesOfEachRole(): void
    {
        $roleId = $this->createRole(['sales', 'items']);

        $this->employeeWithRoles([$roleId]);
        $this->employeeWithRoles([$roleId]);

        $gone = $this->employeeWithRoles([$roleId]);

        $this->roleDb()->table('employees')->where('person_id', $gone)->update(['deleted' => 1]);

        $row = current(array_filter($this->roles->all(), static fn (object $role): bool => (int) $role->role_id === $roleId));

        $this->assertSame(2, (int) $row->permission_count);
        $this->assertSame(2, (int) $row->employee_count);
    }

    public function testReleaseEmployees_TakesTheRolesAndTheScopeAndAuditsWhatWasHeld(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);

        $this->roles->setRecordScope($employee, 'own', null);
        $this->roles->releaseEmployees([$employee], null);

        $this->assertSame([], $this->roles->roleIdsOf($employee));
        $this->assertSame('all', $this->roles->recordScopeOf($employee));

        $audit = $this->auditOf('employee', $employee, 'employee_roles_changed');

        $this->assertSame(['before' => [$role], 'after' => []], json_decode($audit[array_key_last($audit)]->details, true));
    }

    public function testReleaseEmployees_DoesNothingAndDoesNotFailBeforeTheMigrationHasCreatedTheTables(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);

        $this->withoutRoleTables(function () use ($employee): void {
            $this->roles->releaseEmployees([$employee], null);
        });

        $this->assertSame([$role], $this->roles->roleIdsOf($employee), 'The tables were not there, so nothing was taken');
    }

    public function testCarryLocationPermission_DoesNothingAndDoesNotFailBeforeTheMigrationHasCreatedTheTables(): void
    {
        $holder = $this->createRole(['sales']);

        $this->withoutRoleTables(function (): void {
            $this->roles->carryLocationPermission('sales', 'sales_carry_missing');
        });

        $this->assertNotContains('sales_carry_missing', $this->roles->permissionsOf($holder));
    }

    public function testCarryLocationPermission_GivesTheNewPermissionToTheRolesThatHoldTheModule(): void
    {
        $holder = $this->createRole(['sales']);
        $other  = $this->createRole(['items']);

        $this->roleDb()->table('stock_locations')->insert(['location_name' => 'Carry ' . uniqid()]);

        $locationId = (int) $this->roleDb()->insertID();

        $this->roleDb()->table('permissions')->insert(['permission_id' => 'sales_carry' . $locationId, 'module_id' => 'sales', 'location_id' => $locationId]);

        $this->roles->carryLocationPermission('sales', 'sales_carry' . $locationId);

        $this->assertContains('sales_carry' . $locationId, $this->roles->permissionsOf($holder));
        $this->assertNotContains('sales_carry' . $locationId, $this->roles->permissionsOf($other));
    }

    public function testReaders_RefuseWhenATableTheyReadCannotBeRead(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);
        $readers  = [
            'all'               => [['roles'], 'The roles', static fn (RoleService $roles) => $roles->all()],
            'find'              => [['roles'], "The role {$role}", static fn (RoleService $roles) => $roles->find($role)],
            'permissionsOf'     => [['role_permissions'], "The permissions of the role {$role}", static fn (RoleService $roles) => $roles->permissionsOf($role)],
            'recordScopeOf'     => [['employee_record_scopes'], "The record scope of the employee {$employee}", static fn (RoleService $roles) => $roles->recordScopeOf($employee)],
            'roleIdsOf'         => [['employee_roles'], "The roles of the employee {$employee}", static fn (RoleService $roles) => $roles->roleIdsOf($employee)],
            'rolesOf'           => [['employee_roles', 'roles'], "The roles of the employee {$employee}", static fn (RoleService $roles) => $roles->rolesOf($employee)],
            'rolePermissionsOf' => [['employee_roles', 'roles', 'role_permissions'], "The permissions that the roles of the employee {$employee} give", static fn (RoleService $roles) => $roles->rolePermissionsOf($employee)],
        ];

        foreach ([false, true] as $throws) {
            foreach ($readers as $name => [$tables, $what, $read]) {
                foreach ($tables as $table) {
                    $exception = $this->unavailable(fn () => $read($this->rolesOverAFailingTable($table, $throws)), "{$name} over {$table}, throws " . var_export($throws, true));

                    $this->assertStringStartsWith("{$what} could not be read: ", $exception->getMessage(), "{$name} over {$table}");
                    $this->assertSame($throws, $exception->getPrevious() instanceof DatabaseException, "{$name} over {$table}");
                }
            }
        }
    }

    public function testReaders_AnswerThroughAConnectionThatDoesNotFail(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->employeeWithRoles([$role]);
        $roles    = $this->rolesOverAFailingTable(null, false);

        $this->assertSame($role, (int) $roles->find($role)->role_id);
        $this->assertSame(['sales'], $roles->permissionsOf($role));
        $this->assertSame([$role], $roles->roleIdsOf($employee));
        $this->assertSame('all', $roles->recordScopeOf($employee));
        $this->assertSame([$role], array_map(static fn (object $held): int => (int) $held->role_id, $roles->rolesOf($employee)));
        $this->assertSame(['sales' => [$roles->find($role)->name]], $roles->rolePermissionsOf($employee));
        $this->assertContains($role, array_map(static fn (object $listed): int => (int) $listed->role_id, $roles->all()));
    }

    public function testAll_RefusesOnAProductionConnectionWhoseSubselectedTableCannotBeRead(): void
    {
        foreach (['role_permissions', 'employee_roles'] as $table) {
            $this->withUnreadableTable($table, function () use ($table): void {
                $exception = $this->unavailable(fn () => (new RoleService())->all());

                $this->assertNull($exception->getPrevious(), $table);
                $this->assertStringStartsWith('The roles could not be read: ', $exception->getMessage(), $table);
            });
        }
    }

    public function testAssign_RefusesWithTheNamedExceptionWhenWhatItCountsCannotBeReadAndChangesNothing(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->createEmployee();

        foreach ([false, true] as $throws) {
            foreach (['employees' => "The employee {$employee}", 'roles' => 'The roles asked for'] as $table => $what) {
                $exception = $this->unavailable(fn () => $this->rolesOverAFailingTable($table, $throws)->assign($employee, [$role], null), "{$table}, throws " . var_export($throws, true));

                $this->assertStringStartsWith("{$what} could not be read: ", $exception->getMessage(), $table);
            }
        }

        $this->assertSame([], $this->roles->roleIdsOf($employee));
        $this->assertSame([], $this->auditOf('employee', $employee, 'employee_roles_changed'));
    }

    public function testSetRecordScope_RefusesWithTheNamedExceptionWhenWhatItReadsCannotBeReadAndChangesNothing(): void
    {
        $employee = $this->createEmployee();

        foreach ([false, true] as $throws) {
            foreach (['employees' => "The employee {$employee}", 'employee_record_scopes' => "The record scope of the employee {$employee}"] as $table => $what) {
                $exception = $this->unavailable(fn () => $this->rolesOverAFailingTable($table, $throws)->setRecordScope($employee, 'own', null), "{$table}, throws " . var_export($throws, true));

                $this->assertStringStartsWith("{$what} could not be read: ", $exception->getMessage(), $table);
            }
        }

        $this->assertSame('all', $this->roles->recordScopeOf($employee));
        $this->assertSame([], $this->auditOf('employee', $employee, 'employee_record_scope_changed'));
    }

    public function testCreate_RefusesWithTheNamedExceptionWhenTheNameOrThePermissionsCannotBeCheckedAndStoresNothing(): void
    {
        foreach ([false, true] as $throws) {
            foreach (['roles' => 'The roles that bear the name asked for', 'permissions' => 'The permissions asked for'] as $table => $what) {
                $name      = 'Unchecked ' . uniqid();
                $exception = $this->unavailable(fn () => $this->rolesOverAFailingTable($table, $throws)->create(['name' => $name, 'permissions' => ['sales']], null));

                $this->assertStringStartsWith("{$what} could not be read: ", $exception->getMessage(), $table);
                $this->assertSame(0, $this->roleDb()->table('roles')->where('name', $name)->countAllResults(), $table);
            }
        }
    }

    public function testUpdate_KeepsTheRoleAsItWasWhenItsPermissionsCannotBeRead(): void
    {
        $role = $this->createRole(['sales']);
        $name = $this->roles->find($role)->name;

        foreach ([false, true] as $throws) {
            $this->unavailable(fn () => $this->rolesOverAFailingTable('role_permissions', $throws)->update($role, ['name' => $name . ' changed', 'permissions' => ['sales', 'items']], null));
        }

        $this->assertSame($name, $this->roles->find($role)->name);
        $this->assertSame(['sales'], $this->roles->permissionsOf($role));
        $this->assertSame([], $this->auditOf('role', $role, 'role_updated'));
    }

    public function testCheckAssignment_RefusesWithTheNamedExceptionWhateverItReadsThatCannotBeRead(): void
    {
        $role     = $this->createRole(['sales']);
        $employee = $this->createEmployee();

        foreach ([false, true] as $throws) {
            foreach (['roles' => 'The roles asked for', 'employee_roles' => "The roles of the employee {$employee}", 'role_permissions' => 'The permissions of the roles asked for'] as $table => $what) {
                $exception = $this->unavailable(fn () => $this->rolesOverAFailingTable($table, $throws)->checkAssignment($employee, [$role], 'all', null), "{$table}, throws " . var_export($throws, true));

                $this->assertStringStartsWith("{$what} could not be read: ", $exception->getMessage(), $table);
            }
        }
    }

    public function testDelete_KeepsTheRoleAndItsPermissionsWhenTheyCannotBeRead(): void
    {
        $role = $this->createRole(['sales']);

        foreach ([false, true] as $throws) {
            $this->unavailable(fn () => $this->rolesOverAFailingTable('role_permissions', $throws)->delete($role, null));
        }

        $this->assertNotNull($this->roles->find($role));
        $this->assertSame(['sales'], $this->roles->permissionsOf($role));
        $this->assertSame([], $this->auditOf('role', $role, 'role_deleted'));
    }

    public function testReleaseEmployees_KeepsTheRolesAndTheScopeOfAnEmployeeWhenTheirRolesCannotBeRead(): void
    {
        $role     = $this->createRole([], 'own');
        $employee = $this->employeeWithRoles([$role]);

        foreach ([false, true] as $throws) {
            $this->unavailable(fn () => $this->rolesOverAFailingTable('employee_roles', $throws)->releaseEmployees([$employee], null));
        }

        $this->assertSame([$role], $this->roles->roleIdsOf($employee));
        $this->assertTrue((new RecordScope())->ownOnly($employee));
    }
}
