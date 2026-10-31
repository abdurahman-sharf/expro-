<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Roles for test fixtures, made through the role service the application uses, with the system as
 * the actor, and employees that hold them.
 *
 * Needs EmployeeFixtureTrait.
 */
trait RoleFixtureTrait
{
    protected function roleDb(): BaseConnection
    {
        return Database::connect();
    }

    /**
     * @param list<string> $permissions
     */
    protected function createRole(array $permissions = [], string $scope = 'all', bool $active = true, ?string $name = null, ?string $description = null): int
    {
        return (new RoleService())->create([
            'name'         => $name ?? 'Role ' . uniqid(),
            'description'  => $description,
            'active'       => $active,
            'record_scope' => $scope,
            'permissions'  => $permissions,
        ], null);
    }

    /**
     * @param list<int>                                                     $roleIds
     * @param list<string>                                                  $grants
     */
    protected function employeeWithRoles(array $roleIds, array $grants = []): int
    {
        $personId = $this->createEmployee(grants: array_map(
            static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'],
            $grants,
        ));

        (new RoleService())->assign($personId, $roleIds, null);

        return $personId;
    }

    protected function rolesManager(array $extraGrants = []): int
    {
        return $this->createEmployee(grants: array_map(
            static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'],
            [AccountingGuard::ROLES_MANAGE, 'roles', ...$extraGrants],
        ));
    }

    protected function rolesAdministrator(): int
    {
        return $this->rolesManager(ADMIN_MODULES);
    }

    protected function forgetRoleGrants(): void
    {
        RoleGrants::flush();
    }
}
