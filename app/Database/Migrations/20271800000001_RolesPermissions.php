<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The roles screens as a module and the permission to manage roles, which is sensitive: nothing is
 * granted, and only an administrator hands it out from the employee form. Safe to run again.
 */
class RolesPermissions extends Migration
{
    private const MODULE_ID  = 'roles';
    private const PERMISSION = 'acc_roles_manage';
    private const SORT       = 86;

    public function up(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::SORT,
        ]);

        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => self::MODULE_ID, 'module_id' => self::MODULE_ID],
            ['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID],
        ]);
    }

    public function down(): void
    {
        $this->db->table('grants')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();
    }
}
