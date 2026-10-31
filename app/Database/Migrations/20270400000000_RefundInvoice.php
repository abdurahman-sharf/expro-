<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A return of sale can be issued as a credit note or as a refund invoice. The sale keeps which one
 * in a column that stays empty for a credit note, so every return that exists is still a credit
 * note and no journal, document or balance is touched. The column takes nothing but those two
 * values. Safe to run again.
 */
class RefundInvoice extends Migration
{
    public function up(): void
    {
        $sales = $this->db->prefixTable('sales');

        if (! $this->hasColumn('sales', 'return_document')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `return_document` VARCHAR(16) NULL DEFAULT NULL CHECK (`return_document` IS NULL OR `return_document` = 'refund_invoice') AFTER `return_of_sale_id`");
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('sales', 'return_document')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales') . '` DROP COLUMN `return_document`');
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
