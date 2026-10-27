<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The permission to edit the text of a posted automatic journal (its description, the description
 * of its lines and their cost center) and to add or remove the files of a posted journal. It belongs
 * to the journals screen and is sensitive: nothing is granted, and only an administrator hands it
 * out from the employee form. Safe to run again.
 */
class JournalDetailsPermission extends Migration
{
    private const MODULE_ID  = 'accounting_journals';
    private const PERMISSION = 'acc_journals_edit_details';

    public function up(): void
    {
        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID]);
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();
    }
}
