<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The discount on the whole of a sale, next to the discounts of its lines. The sale keeps what was
 * entered (a percentage or an amount) and each line keeps the share of that discount it carries, so
 * the tax, the journal and the reports of a sale read the same figures. Only nullable columns are
 * added and nothing is read, written or recomputed: every sale that exists has no such discount and
 * stays exactly as it was. Safe to run again.
 */
class SalesInvoiceDiscount extends Migration
{
    public function up(): void
    {
        $sales = $this->db->prefixTable('sales');
        $items = $this->db->prefixTable('sales_items');

        if (! $this->hasColumn('sales', 'invoice_discount_type')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `invoice_discount_type` TINYINT(1) NULL DEFAULT NULL");
        }

        if (! $this->hasColumn('sales', 'invoice_discount')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `invoice_discount` DECIMAL(15,4) NULL DEFAULT NULL");
        }

        if (! $this->hasColumn('sales_items', 'invoice_discount_share')) {
            $this->db->query("ALTER TABLE `{$items}` ADD COLUMN `invoice_discount_share` DECIMAL(15,4) NULL DEFAULT NULL");
        }
    }

    public function down(): void
    {
        foreach (['sales' => ['invoice_discount', 'invoice_discount_type'], 'sales_items' => ['invoice_discount_share']] as $table => $columns) {
            foreach ($columns as $column) {
                if ($this->hasColumn($table, $column)) {
                    $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . "` DROP COLUMN `{$column}`");
                }
            }
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow()->found > 0;
    }
}
