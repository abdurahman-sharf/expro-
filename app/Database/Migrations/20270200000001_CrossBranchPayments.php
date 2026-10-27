<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A payment on a document of one branch may go into, or come out of, a treasury of another. The
 * payment keeps the branch of that treasury, and only a payment that carries it is posted as a
 * journal in each branch. Every payment that exists has none, so every journal that exists stays as
 * it is and is posted again the way it was. Only a column, its index and its key are added to each of
 * the tables that hold what was paid. Safe to run again.
 */
class CrossBranchPayments extends Migration
{
    private const TABLES = ['sales_payments', 'receivings_payments', 'expenses'];

    public function up(): void
    {
        $branches = $this->db->prefixTable('branches');

        foreach (self::TABLES as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $name = $this->db->prefixTable($table);

            if (! $this->hasColumn($table, 'treasury_branch_id')) {
                $this->db->query("ALTER TABLE `{$name}` ADD COLUMN `treasury_branch_id` INT UNSIGNED NULL DEFAULT NULL");
            }

            if (! $this->hasIndex($table, $table . '_treasury_branch')) {
                $this->db->query("ALTER TABLE `{$name}` ADD KEY `{$name}_treasury_branch` (`treasury_branch_id`)");
            }

            if (! $this->hasForeignKey($table, $table . '_fk_treasury_branch')) {
                $this->db->query("ALTER TABLE `{$name}` ADD CONSTRAINT `{$name}_fk_treasury_branch` FOREIGN KEY (`treasury_branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $name = $this->db->prefixTable($table);

            if ($this->hasForeignKey($table, $table . '_fk_treasury_branch')) {
                $this->db->query("ALTER TABLE `{$name}` DROP FOREIGN KEY `{$name}_fk_treasury_branch`");
            }

            if ($this->hasIndex($table, $table . '_treasury_branch')) {
                $this->db->query("ALTER TABLE `{$name}` DROP KEY `{$name}_treasury_branch`");
            }

            if ($this->hasColumn($table, 'treasury_branch_id')) {
                $this->db->query("ALTER TABLE `{$name}` DROP COLUMN `treasury_branch_id`");
            }
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
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
