<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A transfer of stock between two locations of different branches is two journals, one in each
 * branch, that meet in the accounts between branches the transfers of money already use. The
 * transfer keeps the branch the stock goes to, next to the branch it leaves, which the purchase has
 * always kept. Only a column, its index and its key are added: no journal, no document and no
 * balance is touched. Safe to run again.
 */
class StockTransfersBetweenBranches extends Migration
{
    public function up(): void
    {
        $receivings = $this->db->prefixTable('receivings');
        $branches   = $this->db->prefixTable('branches');

        if (! $this->hasColumn('receivings', 'to_branch_id')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD COLUMN `to_branch_id` INT UNSIGNED NULL DEFAULT NULL AFTER `branch_id`");
        }

        if (! $this->hasIndex('receivings', 'receivings_to_branch')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD KEY `{$receivings}_to_branch` (`to_branch_id`)");
        }

        if (! $this->hasForeignKey('receivings', 'receivings_fk_to_branch')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD CONSTRAINT `{$receivings}_fk_to_branch` FOREIGN KEY (`to_branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        $receivings = $this->db->prefixTable('receivings');

        if ($this->hasForeignKey('receivings', 'receivings_fk_to_branch')) {
            $this->db->query("ALTER TABLE `{$receivings}` DROP FOREIGN KEY `{$receivings}_fk_to_branch`");
        }

        if ($this->hasIndex('receivings', 'receivings_to_branch')) {
            $this->db->query("ALTER TABLE `{$receivings}` DROP KEY `{$receivings}_to_branch`");
        }

        if ($this->hasColumn('receivings', 'to_branch_id')) {
            $this->db->query("ALTER TABLE `{$receivings}` DROP COLUMN `to_branch_id`");
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($index)],
        )->getRow() !== null;
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($constraint), 'FOREIGN KEY'],
        )->getRow() !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
