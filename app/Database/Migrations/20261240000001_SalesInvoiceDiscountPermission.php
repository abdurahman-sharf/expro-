<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Registers the permission to give a discount on the whole of a sale, next to the permission to
 * change a selling price. Nothing is granted: the owner grants it from the employees screen, and
 * until then the register offers no such discount. Safe to run again.
 */
class SalesInvoiceDiscountPermission extends Migration
{
    private const PERMISSION = 'sales_invoice_discount';

    public function up(): void
    {
        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => 'sales']);
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();
    }
}
