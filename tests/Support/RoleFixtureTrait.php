<?php

namespace Tests\Support;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Roles\RoleGrants;
use App\Libraries\Roles\RoleTables;
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
     * migration has left no record and the tables of roles are not there.
     */
    protected function withoutRoleTables(callable $then): void
    {
        $db     = $this->roleDb();
        $tables = ['employee_default_locations', 'employee_record_scopes', 'employee_roles', 'role_permissions', 'roles'];

        $this->withoutTheMigrationRecord(static function () use ($db, $tables, $then): void {
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
            }
        });
    }

    /**
     * Runs the callback while the table of migrations holds no record of the migration of the roles,
     * which is all the code goes by to know that the tables are there.
     */
    protected function withoutTheMigrationRecord(callable $then): void
    {
        $db   = $this->roleDb();
        $rows = $db->table('migrations')->where('version', RoleTables::MIGRATION)->get()->getResultArray();

        RoleGrants::flush();
        $db->table('migrations')->where('version', RoleTables::MIGRATION)->delete();

        try {
            $then();
        } finally {
            foreach ($rows as $row) {
                $db->table('migrations')->insert($row);
            }

            RoleGrants::flush();
        }
    }

    /**
     * Runs the callback as a production server whose table of record scopes cannot be read.
     */
    protected function withUnreadableRecordScopes(callable $then): void
    {
        $this->withUnreadableTable('employee_record_scopes', $then);
    }

    /**
     * Runs the callback as a production server whose table cannot be read: the name is still in the
     * list of the tables, as a view of a table that is gone, so the probe finds it, but a query on it
     * fails, and the connection does not debug, so the failed query answers false and does not throw.
     * A view and not a renamed column, which MySQL 8 refuses for a column of a check constraint.
     */
    protected function withUnreadableTable(string $name, callable $then): void
    {
        $db     = $this->roleDb();
        $table  = $db->prefixTable($name);
        $away   = $table . '_away';
        $gone   = $table . '_gone';
        $debug  = new ReflectionProperty(BaseConnection::class, 'DBDebug');
        $before = $debug->getValue($db);
        $moved  = $viewed = $emptied = false;

        RoleGrants::flush();

        try {
            $db->query("RENAME TABLE `{$table}` TO `{$away}`");
            $moved = true;
            $db->query("CREATE VIEW `{$table}` AS SELECT * FROM `{$away}`");
            $viewed = true;
            $db->query("RENAME TABLE `{$away}` TO `{$gone}`");
            $emptied = true;
            $debug->setValue($db, false);
            $then();
        } finally {
            $debug->setValue($db, $before);

            if ($viewed) {
                $db->query("DROP VIEW `{$table}`");
            }

            if ($moved) {
                $db->query('RENAME TABLE `' . ($emptied ? $gone : $away) . "` TO `{$table}`");
            }

            RoleGrants::flush();
        }
    }
}
