<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What posting a sale needs from the sale itself: whether its goods have left (an invoice can
 * be issued before they do, and its cost is posted when they do), and the sale a return is
 * taken back from (the cost of a returned item is the cost it was sold at). Additive only, and
 * safe to run again. Every sale that exists counts as delivered and as no return of another.
 */
class SalesAccounting extends Migration
{
    public function up(): void
    {
        $sales = $this->db->prefixTable('sales');

        if (! $this->hasColumn('sales', 'delivered')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `delivered` TINYINT(1) NOT NULL DEFAULT 1");
        }

        if (! $this->hasColumn('sales', 'return_of_sale_id')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `return_of_sale_id` INT(10) NULL DEFAULT NULL");
        }

        if (! $this->hasIndex('sales', 'sales_return_of')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD INDEX `" . $this->db->prefixTable('sales_return_of') . '` (`return_of_sale_id`)');
        }

        if (! $this->hasForeignKey('sales', 'sales_fk_return_of')) {
            $this->db->query(
                "ALTER TABLE `{$sales}` ADD CONSTRAINT `" . $this->db->prefixTable('sales_fk_return_of') . "` FOREIGN KEY (`return_of_sale_id`) REFERENCES `{$sales}` (`sale_id`) ON DELETE RESTRICT",
            );
        }
    }

    public function down(): void
    {
        $sales = $this->db->prefixTable('sales');

        if ($this->hasForeignKey('sales', 'sales_fk_return_of')) {
            $this->db->query("ALTER TABLE `{$sales}` DROP FOREIGN KEY `" . $this->db->prefixTable('sales_fk_return_of') . '`');
        }

        if ($this->hasIndex('sales', 'sales_return_of')) {
            $this->db->query("ALTER TABLE `{$sales}` DROP INDEX `" . $this->db->prefixTable('sales_return_of') . '`');
        }

        foreach (['return_of_sale_id', 'delivered'] as $column) {
            if ($this->hasColumn('sales', $column)) {
                $this->db->query("ALTER TABLE `{$sales}` DROP COLUMN `{$column}`");
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

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($index)],
        )->getRow()->found > 0;
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($name), 'FOREIGN KEY'],
        )->getRow()->found > 0;
    }
}
