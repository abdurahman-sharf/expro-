<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The module of the financial reports, the trial balance and the statement of an account. It
 * reads the journals that are already in the books and adds nothing to them. Nothing is granted:
 * the owner grants the module from the employees screen, as with the other accounting screens.
 * Safe to run again.
 */
class FinancialReports extends Migration
{
    private const MODULE_ID   = 'accounting_reports';
    private const MODULE_SORT = 128;

    public function up(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::MODULE_SORT,
        ]);

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::MODULE_ID, 'module_id' => self::MODULE_ID]);
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::MODULE_ID)->delete();
        $this->db->table('permissions')->where('permission_id', self::MODULE_ID)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();
    }
}
