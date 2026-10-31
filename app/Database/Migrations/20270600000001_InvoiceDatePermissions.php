<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registers the permissions to type the date of a sale and of a purchase instead of taking the day
 * it is saved. Nothing is granted: the owner grants them from the employees screen, and until then
 * every document is dated the day it is saved, as it always was. Safe to run again.
 */
class InvoiceDatePermissions extends Migration
{
    private const PERMISSIONS = [
        'sales_invoice_date'      => 'sales',
        'receivings_invoice_date' => 'receivings',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission => $module) {
            $this->db->table('permissions')->ignore(true)->insert(['permission_id' => $permission, 'module_id' => $module]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->delete();
            $this->db->table('permissions')->where('permission_id', $permission)->delete();
        }
    }
}
