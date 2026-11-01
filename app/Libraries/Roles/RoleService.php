<?php

namespace App\Libraries\Roles;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\BranchGuard;
use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Roles: a named set of permission ids, held by employees next to their own grants. A role never
 * copies a grant into an employee, so changing a role changes what everyone who holds it may do at
 * once. A role can never hold a sensitive permission or a branch scope: those stay with the
 * employee, handed out by an administrator. Whoever changes a role or gives one away may only add
 * what they hold themselves, unless they are an administrator, as on the employee form. Every
 * change is written to the audit log. The books and the console act without an employee, and that
 * is always allowed.
 */
final class RoleService
{
    use RunsInTransaction;

    private const NAME_LENGTH        = 100;
    private const DESCRIPTION_LENGTH = 255;

    private BaseConnection $db;
    private AuditLog $audit;
    private Employee $employee;

    public function __construct(?BaseConnection $db = null, ?Employee $employee = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->employee = $employee ?? model(Employee::class);
    }

    /**
     * @return list<object>
     */
    public function all(): array
    {
        if (! RoleTables::exist($this->db, 'roles', 'role_permissions', 'employee_roles')) {
            return [];
        }

        return $this->db->table('roles r')
            ->select('r.role_id, r.name, r.description, r.active, r.record_scope, r.created_at, r.updated_at')
            ->select('(SELECT COUNT(*) FROM ' . $this->db->prefixTable('role_permissions') . ' rp WHERE rp.role_id = r.role_id) AS permission_count', false)
            ->select('(SELECT COUNT(*) FROM ' . $this->db->prefixTable('employee_roles') . ' er JOIN ' . $this->db->prefixTable('employees') . ' e ON e.person_id = er.person_id AND e.deleted = 0 WHERE er.role_id = r.role_id) AS employee_count', false)
            ->orderBy('r.name', 'ASC')
            ->get()
            ->getResult();
    }

    public function find(int $roleId): ?object
    {
        return $this->db->table('roles')->where('role_id', $roleId)->get()->getRow();
    }

    /**
     * @return list<string>
     */
    public function permissionsOf(int $roleId): array
    {
        $rows = $this->db->table('role_permissions')->select('permission_id')->where('role_id', $roleId)->orderBy('permission_id', 'ASC')->get()->getResultArray();

        return array_column($rows, 'permission_id');
    }

    /**
     * @param array{name?: mixed, description?: mixed, active?: mixed, record_scope?: mixed, permissions?: mixed} $input
     *
     * @throws RoleException
     */
    public function create(array $input, ?int $actorId): int
    {
        $this->requireManage($actorId);

        $data        = $this->validatedHeader($input, null);
        $permissions = $this->validatedPermissions($input['permissions'] ?? []);

        $this->assertMayGrant($actorId, $permissions);

        return $this->transactional(function () use ($data, $permissions, $actorId): int {
            $this->db->table('roles')->insert($data + ['created_by' => $actorId]);

            $roleId = (int) $this->db->insertID();

            $this->writePermissions($roleId, $permissions);
            $this->audit->write('role', $roleId, 'role_created', $actorId, JournalOrigin::User, $data + ['permissions' => $permissions]);

            RoleGrants::flush();

            return $roleId;
        });
    }

    /**
     * @param array{name?: mixed, description?: mixed, active?: mixed, record_scope?: mixed, permissions?: mixed} $input
     *
     * @throws RoleException
     */
    public function update(int $roleId, array $input, ?int $actorId): void
    {
        $this->requireManage($actorId);

        $role = $this->requireRole($roleId);
        $data = $this->validatedHeader($input, $roleId);

        $before = $this->permissionsOf($roleId);
        $after  = array_key_exists('permissions', $input) ? $this->validatedPermissions($input['permissions']) : $before;
        $added  = array_values(array_diff($after, $before));

        $this->assertMayGrant($actorId, $added);

        $this->transactional(function () use ($roleId, $role, $data, $before, $after, $added, $actorId): void {
            $changed = array_filter(
                $data,
                static fn (mixed $value, string $field): bool => (string) $role->{$field} !== (string) $value,
                ARRAY_FILTER_USE_BOTH,
            );

            if ($changed !== []) {
                $this->db->table('roles')->where('role_id', $roleId)->update($data);
                $this->audit->write('role', $roleId, 'role_updated', $actorId, JournalOrigin::User, [
                    'before' => array_intersect_key((array) $role, $changed),
                    'after'  => $changed,
                ]);
            }

            $removed = array_values(array_diff($before, $after));

            if ($added !== [] || $removed !== []) {
                $this->writePermissions($roleId, $after);
                $this->audit->write('role', $roleId, 'role_permissions_changed', $actorId, JournalOrigin::User, ['added' => $added, 'removed' => $removed]);
            }

            RoleGrants::flush();
        });
    }

    /**
     * A role that employees hold is deactivated, not deleted, so what they lose is a decision.
     *
     * @throws RoleException
     */
    public function delete(int $roleId, ?int $actorId): void
    {
        $this->requireManage($actorId);

        $role = $this->requireRole($roleId);

        if ($this->holders($roleId) > 0) {
            throw new RoleException(RoleError::InUse, "The role {$roleId} is held by employees.", ['name' => $role->name]);
        }

        $this->transactional(function () use ($roleId, $role, $actorId): void {
            $this->audit->write('role', $roleId, 'role_deleted', $actorId, JournalOrigin::User, ['name' => $role->name, 'permissions' => $this->permissionsOf($roleId)]);
            $this->db->table('roles')->where('role_id', $roleId)->delete();

            RoleGrants::flush();
        });
    }

    /**
     * The roles of an employee, replaced by the roles given.
     *
     * @param list<int|string> $roleIds
     *
     * @throws RoleException
     */
    public function assign(int $personId, array $roleIds, ?int $actorId): void
    {
        $this->requireManage($actorId);

        if ($this->db->table('employees')->where(['person_id' => $personId, 'deleted' => 0])->countAllResults() === 0) {
            throw new RoleException(RoleError::EmployeeNotFound, "The employee {$personId} does not exist.");
        }

        $wanted = $this->existingRoleIds($roleIds);
        $before = $this->roleIdsOf($personId);
        $added  = array_values(array_diff($wanted, $before));

        $this->assertMayGrant($actorId, $this->permissionsOfRoles($added));

        if ($wanted === $before) {
            return;
        }

        $this->transactional(function () use ($personId, $wanted, $before, $actorId): void {
            $this->db->table('employee_roles')->where('person_id', $personId)->delete();

            foreach ($wanted as $roleId) {
                $this->db->table('employee_roles')->insert(['person_id' => $personId, 'role_id' => $roleId]);
            }

            $this->audit->write('employee', $personId, 'employee_roles_changed', $actorId, JournalOrigin::User, ['before' => $before, 'after' => $wanted]);

            RoleGrants::flush();
        });
    }

    /**
     * Whatever saving the roles and the record scope of an employee would refuse, asked before the
     * employee is saved so that a refusal leaves nothing behind. An employee not yet saved has no
     * personId.
     *
     * @param list<int|string> $roleIds
     *
     * @throws RoleException
     */
    public function checkAssignment(?int $personId, array $roleIds, string $scope, ?int $actorId): void
    {
        $this->requireManage($actorId);

        $wanted = $this->existingRoleIds($roleIds);
        $before = $personId === null ? [] : $this->roleIdsOf($personId);

        $this->assertMayGrant($actorId, $this->permissionsOfRoles(array_values(array_diff($wanted, $before))));
        $this->assertRecordScope($scope);
    }

    /**
     * The employee's own option to see their own records only. A role they hold can narrow them
     * further, and never widens them.
     *
     * @throws RoleException
     */
    public function setRecordScope(int $personId, string $scope, ?int $actorId): void
    {
        $this->requireManage($actorId);
        $this->assertRecordScope($scope);

        if ($this->db->table('employees')->where(['person_id' => $personId, 'deleted' => 0])->countAllResults() === 0) {
            throw new RoleException(RoleError::EmployeeNotFound, "The employee {$personId} does not exist.");
        }

        $before = $this->recordScopeOf($personId);

        if ($before === $scope) {
            return;
        }

        $this->transactional(function () use ($personId, $scope, $before, $actorId): void {
            $this->db->table('employee_record_scopes')->where('person_id', $personId)->delete();

            if ($scope === RecordScope::OWN) {
                $this->db->table('employee_record_scopes')->insert(['person_id' => $personId, 'record_scope' => $scope]);
            }

            $this->audit->write('employee', $personId, 'employee_record_scope_changed', $actorId, JournalOrigin::User, ['before' => $before, 'after' => $scope]);

            RoleGrants::flush();
        });
    }

    public function recordScopeOf(int $personId): string
    {
        $row = $this->db->table('employee_record_scopes')->select('record_scope')->where('person_id', $personId)->get()->getRow();

        return $row !== null && $row->record_scope === RecordScope::OWN ? RecordScope::OWN : RecordScope::ALL;
    }

    /**
     * @return list<int>
     */
    public function roleIdsOf(int $personId): array
    {
        $rows = $this->db->table('employee_roles')->select('role_id')->where('person_id', $personId)->orderBy('role_id', 'ASC')->get()->getResultArray();

        return array_map('intval', array_column($rows, 'role_id'));
    }

    /**
     * The roles an employee holds with what each gives, for the employee form.
     *
     * @return list<object>
     */
    public function rolesOf(int $personId): array
    {
        return $this->db->table('employee_roles er')
            ->select('r.role_id, r.name, r.active, r.record_scope')
            ->join('roles r', 'r.role_id = er.role_id')
            ->where('er.person_id', $personId)
            ->orderBy('r.name', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * What an employee holds only through their roles, with the names of the roles that give it.
     *
     * @return array<string, list<string>>
     */
    public function rolePermissionsOf(int $personId): array
    {
        $rows = $this->db->table('employee_roles er')
            ->select('rp.permission_id, r.name')
            ->join('roles r', 'r.role_id = er.role_id AND r.active = 1')
            ->join('role_permissions rp', 'rp.role_id = er.role_id')
            ->where('er.person_id', $personId)
            ->whereNotIn('rp.permission_id', RoleGrants::forbidden())
            ->orderBy('r.name', 'ASC')
            ->get()
            ->getResult();

        $byPermission = [];

        foreach ($rows as $row) {
            $byPermission[(string) $row->permission_id][] = (string) $row->name;
        }

        return $byPermission;
    }

    /**
     * The roles and the record scope of employees who are deleted go with their grants.
     *
     * @param list<int|string> $personIds
     */
    public function releaseEmployees(array $personIds, ?int $actorId): void
    {
        $ids = array_values(array_unique(array_map('intval', $personIds)));

        if ($ids === [] || ! RoleTables::exist($this->db, 'employee_roles', 'employee_record_scopes')) {
            return;
        }

        $this->transactional(function () use ($ids, $actorId): void {
            foreach ($ids as $personId) {
                $before = $this->roleIdsOf($personId);

                if ($before !== []) {
                    $this->audit->write('employee', $personId, 'employee_roles_changed', $actorId, JournalOrigin::User, ['before' => $before, 'after' => []]);
                }
            }

            $this->db->table('employee_roles')->whereIn('person_id', $ids)->delete();
            $this->db->table('employee_record_scopes')->whereIn('person_id', $ids)->delete();

            RoleGrants::flush();
        });
    }

    /**
     * A new permission of a stock location reaches the roles that hold its module, as it reaches
     * every employee.
     */
    public function carryLocationPermission(string $moduleId, string $permissionId): void
    {
        if (! RoleTables::exist($this->db, 'role_permissions')) {
            return;
        }

        $this->db->query(
            'INSERT IGNORE INTO ' . $this->db->prefixTable('role_permissions') . ' (role_id, permission_id) SELECT role_id, ? FROM ' . $this->db->prefixTable('role_permissions') . ' WHERE permission_id = ?',
            [$permissionId, $moduleId],
        );

        RoleGrants::flush();
    }

    public function holders(int $roleId): int
    {
        return $this->db->table('employee_roles er')
            ->join('employees e', 'e.person_id = er.person_id AND e.deleted = 0')
            ->where('er.role_id', $roleId)
            ->countAllResults();
    }

    /**
     * @throws RoleException
     */
    /**
     * @throws RoleException
     */
    private function assertRecordScope(string $scope): void
    {
        if (! in_array($scope, RecordScope::SCOPES, true)) {
            throw new RoleException(RoleError::InvalidScope, 'A record scope is all or own.');
        }
    }

    private function requireManage(?int $actorId): void
    {
        if ($actorId !== null && ! $this->employee->has_grant(AccountingGuard::ROLES_MANAGE, $actorId)) {
            throw new RoleException(RoleError::PermissionDenied, 'Missing permission ' . AccountingGuard::ROLES_MANAGE . '.');
        }
    }

    /**
     * Whoever is not an administrator gives only what they hold.
     *
     * @param list<string> $permissionIds
     *
     * @throws RoleException
     */
    private function assertMayGrant(?int $actorId, array $permissionIds): void
    {
        if ($actorId === null || $this->employee->isAdmin($actorId)) {
            return;
        }

        foreach ($permissionIds as $permissionId) {
            if (! $this->employee->has_grant($permissionId, $actorId)) {
                throw new RoleException(RoleError::CannotGrant, "The employee does not hold {$permissionId}.", ['permission' => $permissionId]);
            }
        }
    }

    /**
     * @throws RoleException
     */
    private function requireRole(int $roleId): object
    {
        $role = $this->find($roleId);

        if ($role === null) {
            throw new RoleException(RoleError::NotFound, "The role {$roleId} does not exist.");
        }

        return $role;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, description: ?string, active: int, record_scope: string}
     *
     * @throws RoleException
     */
    private function validatedHeader(array $input, ?int $exceptRoleId): array
    {
        $name = is_string($input['name'] ?? null) ? trim($input['name']) : '';

        if ($name === '' || mb_strlen($name) > self::NAME_LENGTH) {
            throw new RoleException(RoleError::InvalidName, 'A role has a name of 1 to ' . self::NAME_LENGTH . ' characters.');
        }

        $taken = $this->db->table('roles')->where('name', $name);

        if ($exceptRoleId !== null) {
            $taken->where('role_id !=', $exceptRoleId);
        }

        if ($taken->countAllResults() > 0) {
            throw new RoleException(RoleError::NameTaken, "The name {$name} is used by another role.", ['name' => $name]);
        }

        $description = $input['description'] ?? null;

        if ($description !== null && ! is_string($description)) {
            throw new RoleException(RoleError::InvalidDescription, 'The description is text.');
        }

        $description = $description === null ? null : trim($description);

        if ($description !== null && mb_strlen($description) > self::DESCRIPTION_LENGTH) {
            throw new RoleException(RoleError::InvalidDescription, 'The description is at most ' . self::DESCRIPTION_LENGTH . ' characters.');
        }

        $scope = $input['record_scope'] ?? RecordScope::ALL;

        if (! is_string($scope) || ! in_array($scope, RecordScope::SCOPES, true)) {
            throw new RoleException(RoleError::InvalidScope, 'A record scope is all or own.');
        }

        return [
            'name'         => $name,
            'description'  => $description === '' ? null : $description,
            'active'       => array_key_exists('active', $input) ? (filter_var($input['active'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : 1,
            'record_scope' => $scope,
        ];
    }

    /**
     * @return list<string>
     *
     * @throws RoleException
     */
    private function validatedPermissions(mixed $permissionIds): array
    {
        if (! is_array($permissionIds)) {
            throw new RoleException(RoleError::UnknownPermission, 'The permissions are a list of permission ids.');
        }

        $ids = [];

        foreach ($permissionIds as $permissionId) {
            if (! is_string($permissionId) || $permissionId === '') {
                throw new RoleException(RoleError::UnknownPermission, 'A permission is the id of a permission.');
            }

            $ids[$permissionId] = $permissionId;
        }

        $ids = array_values($ids);

        sort($ids);

        foreach ($ids as $permissionId) {
            if (in_array($permissionId, AccountingGuard::SENSITIVE, true)) {
                throw new RoleException(RoleError::SensitivePermission, "The permission {$permissionId} is sensitive and only goes to an employee.", ['permission' => $permissionId]);
            }

            if (in_array($permissionId, [BranchGuard::ALL_BRANCHES, BranchGuard::SCOPE_MANAGE], true)) {
                throw new RoleException(RoleError::BranchPermission, "The permission {$permissionId} sets the branches of an employee and stays with the employee.", ['permission' => $permissionId]);
            }
        }

        $known = $ids === [] ? [] : array_column($this->db->table('permissions')->select('permission_id')->whereIn('permission_id', $ids)->get()->getResultArray(), 'permission_id');

        foreach ($ids as $permissionId) {
            if (! in_array($permissionId, $known, true)) {
                throw new RoleException(RoleError::UnknownPermission, "The permission {$permissionId} does not exist.", ['permission' => $permissionId]);
            }
        }

        return $ids;
    }

    /**
     * @param list<string> $permissionIds
     */
    private function writePermissions(int $roleId, array $permissionIds): void
    {
        $this->db->table('role_permissions')->where('role_id', $roleId)->delete();

        foreach ($permissionIds as $permissionId) {
            $this->db->table('role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
    }

    /**
     * @param list<int|string> $roleIds
     *
     * @return list<int>
     *
     * @throws RoleException
     */
    private function existingRoleIds(array $roleIds): array
    {
        $ids = [];

        foreach ($roleIds as $roleId) {
            if (! (is_int($roleId) || (is_string($roleId) && ctype_digit($roleId))) || (int) $roleId < 1) {
                throw new RoleException(RoleError::NotFound, 'A role is the number of a role.');
            }

            $ids[(int) $roleId] = (int) $roleId;
        }

        $ids = array_values($ids);

        sort($ids);

        if ($ids !== [] && $this->db->table('roles')->whereIn('role_id', $ids)->countAllResults() !== count($ids)) {
            throw new RoleException(RoleError::NotFound, 'A role of the list does not exist.');
        }

        return $ids;
    }

    /**
     * @param list<int> $roleIds
     *
     * @return list<string>
     */
    private function permissionsOfRoles(array $roleIds): array
    {
        if ($roleIds === []) {
            return [];
        }

        $rows = $this->db->table('role_permissions')->distinct()->select('permission_id')->whereIn('role_id', $roleIds)->get()->getResultArray();

        return array_column($rows, 'permission_id');
    }
}
