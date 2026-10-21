<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registers the accounting screens as modules with their permissions. Nothing is granted:
 * the owner grants them from the employees screen. The sub-permissions deliberately do not
 * start with their module id, because Employee::has_module_grant() would then ask for at
 * least two grants and a user holding only the module's own grant could not open it.
 */
class AccountingModules extends Migration
{
    private const MODULES = [
        ['module_id' => 'accounting_chart', 'sort' => 120],
        ['module_id' => 'accounting_journals', 'sort' => 121],
        ['module_id' => 'accounting_cost_centers', 'sort' => 122],
    ];
    private const SUB_PERMISSIONS = [
        'acc_chart_manage'        => 'accounting_chart',
        'acc_journals_all'        => 'accounting_journals',
        'acc_journals_draft'      => 'accounting_journals',
        'acc_journals_post'       => 'accounting_journals',
        'acc_journals_reverse'    => 'accounting_journals',
        'acc_journals_audit'      => 'accounting_journals',
        'acc_cost_centers_manage' => 'accounting_cost_centers',
    ];

    public function up(): void
    {
        $modules     = [];
        $permissions = [];

        foreach (self::MODULES as $module) {
            $id = $module['module_id'];

            $modules[] = [
                'module_id'     => $id,
                'name_lang_key' => "module_{$id}",
                'desc_lang_key' => "module_{$id}_desc",
                'sort'          => $module['sort'],
            ];
            $permissions[] = ['permission_id' => $id, 'module_id' => $id];
        }

        foreach (self::SUB_PERMISSIONS as $permissionId => $moduleId) {
            $permissions[] = ['permission_id' => $permissionId, 'module_id' => $moduleId];
        }

        $this->db->table('modules')->ignore(true)->insertBatch($modules);
        $this->db->table('permissions')->ignore(true)->insertBatch($permissions);
    }

    public function down(): void
    {
        $moduleIds     = array_column(self::MODULES, 'module_id');
        $permissionIds = [...$moduleIds, ...array_keys(self::SUB_PERMISSIONS)];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->whereIn('module_id', $moduleIds)->delete();
    }
}
