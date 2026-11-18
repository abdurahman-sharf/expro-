<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleService;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use ReflectionProperty;

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
     * @param list<int>    $roleIds
     * @param list<string> $grants
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

    /**
     * Runs the callback as the code does on a server whose migration of the roles has not run: the
     * tables of roles are not there.
     */
    protected function withoutRoleTables(callable $then): void
    {
        $db     = $this->roleDb();
        $tables = ['employee_default_locations', 'employee_record_scopes', 'employee_roles', 'role_permissions', 'roles'];

        RoleGrants::flush();

        foreach ($tables as $table) {
            $name = $db->prefixTable($table);
            $db->query("RENAME TABLE `{$name}` TO `{$name}_away`");
        }

        try {
            $then();
        } finally {
            foreach (array_reverse($tables) as $table) {
                $name = $db->prefixTable($table);
                $db->query("RENAME TABLE `{$name}_away` TO `{$name}`");
            }

            RoleGrants::flush();
        }
    }

    /**
     * Runs the callback as a production server whose table of record scopes cannot be read: the table
     * is there, so the probe finds it, but the column the scope is read from is not, and the
     * connection does not debug, so a failed query answers false and does not throw.
     */
    protected function withUnreadableRecordScopes(callable $then): void
    {
        $db     = $this->roleDb();
        $table  = $db->prefixTable('employee_record_scopes');
        $debug  = new ReflectionProperty(BaseConnection::class, 'DBDebug');
        $before = $debug->getValue($db);

        RoleGrants::flush();
        $db->query("ALTER TABLE `{$table}` CHANGE `record_scope` `record_scope_away` VARCHAR(8) NOT NULL DEFAULT 'all'");

        try {
            $debug->setValue($db, false);
            $then();
        } finally {
            $debug->setValue($db, $before);
            $db->query("ALTER TABLE `{$table}` CHANGE `record_scope_away` `record_scope` VARCHAR(8) NOT NULL DEFAULT 'all'");
            RoleGrants::flush();
        }
    }
}
