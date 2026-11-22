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
            $this->dropChecksOfReturnDocument();
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales') . '` DROP COLUMN `return_document`');
        }
    }

    private function dropChecksOfReturnDocument(): void
    {
        if ($this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'information_schema' AND TABLE_NAME = 'CHECK_CONSTRAINTS'")->getRow() === null) {
            return;
        }

        $sales = $this->db->prefixTable('sales');
        $names = $this->db->query(
            "SELECT cc.CONSTRAINT_NAME AS name FROM information_schema.CHECK_CONSTRAINTS AS cc INNER JOIN information_schema.TABLE_CONSTRAINTS AS tc ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME AND tc.CONSTRAINT_TYPE = 'CHECK' WHERE cc.CONSTRAINT_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND cc.CHECK_CLAUSE LIKE ?",
            [$sales, '%return_document%'],
        )->getResultArray();

        foreach ($names as $row) {
            // MariaDB names the check of a column after the column and drops it with the column; it cannot be dropped by name.
            if ($row['name'] === 'return_document') {
                continue;
            }

            $this->db->query("ALTER TABLE `{$sales}` DROP CONSTRAINT `{$row['name']}`");
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
