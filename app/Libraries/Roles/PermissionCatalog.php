<?php

namespace App\Libraries\Roles;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The permissions a role can carry, grouped by module as the employee form shows them and under
 * the same labels. A sensitive permission is listed but cannot be chosen, and a branch scope is not
 * listed at all.
 */
final class PermissionCatalog
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{module_id: string, label: string, description: string, selectable: bool, permissions: list<array{permission_id: string, label: string, sensitive: bool, role_locked: bool}>}>
     */
    public function modules(): array
    {
        $subpermissions = $this->db->table('permissions p')
            ->select('p.permission_id, p.module_id')
            ->join('modules m', 'm.module_id = p.module_id')
            ->where('p.permission_id !=', 'p.module_id', false)
            ->orderBy('p.permission_id', 'ASC')
            ->get()
            ->getResult();

        $byModule = [];

        foreach ($subpermissions as $permission) {
            if (in_array($permission->permission_id, [BranchGuard::ALL_BRANCHES, BranchGuard::SCOPE_MANAGE], true)) {
                continue;
            }

            $byModule[$permission->module_id][] = [
                'permission_id' => (string) $permission->permission_id,
                'label'         => $this->subLabel((string) $permission->module_id, (string) $permission->permission_id),
                'sensitive'     => in_array($permission->permission_id, AccountingGuard::SENSITIVE, true),
                'role_locked'   => in_array($permission->permission_id, RoleGrants::REFUSED, true),
            ];
        }

        $modules = [];

        foreach ($this->db->table('modules')->orderBy('sort', 'ASC')->get()->getResult() as $module) {
            $modules[] = [
                'module_id'   => (string) $module->module_id,
                'label'       => lang('Module.' . $module->module_id),
                'description' => lang('Module.' . $module->module_id . '_desc'),
                'selectable'  => ! in_array($module->module_id, RoleGrants::forbidden(), true),
                'permissions' => $byModule[$module->module_id] ?? [],
            ];
        }

        return $modules;
    }

    private function subLabel(string $moduleId, string $permissionId): string
    {
        $exploded = explode('_', $permissionId, 2);
        $key      = ucfirst($moduleId . '.' . ($exploded[1] ?? $permissionId));
        $label    = lang($key);

        return $label === '' || $label === $key ? ucwords(str_replace('_', ' ', $exploded[1] ?? $permissionId)) : $label;
    }
}
