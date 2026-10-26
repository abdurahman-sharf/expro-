<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What posting an expense and a cash-up to the books needs to know: the account of an expense
 * category, and for an expense and a cash-up the branch they belong to and the treasury the money
 * moved through. A cash-up also keeps the cash it was expected to hold and the difference it was
 * posted with. Only nullable columns, their indexes and their keys are added: no expense, no
 * cash-up and no journal is touched, and the rows that exist stay as they are, without a journal.
 * Safe to run again.
 */
class ExpensesAccounting extends Migration
{
    private const COLUMNS = [
        'expenses' => [
            'branch_id'   => ['INT UNSIGNED NULL DEFAULT NULL', 'branches', 'branch_id'],
            'treasury_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'treasuries', 'treasury_id'],
        ],
        'expense_categories' => [
            'account_id' => ['INT UNSIGNED NULL DEFAULT NULL', 'accounting_accounts', 'account_id'],
        ],
        'cash_up' => [
            'branch_id'            => ['INT UNSIGNED NULL DEFAULT NULL', 'branches', 'branch_id'],
            'treasury_id'          => ['INT UNSIGNED NULL DEFAULT NULL', 'treasuries', 'treasury_id'],
            'expected_amount_cash' => ['DECIMAL(15,4) NULL DEFAULT NULL', null, null],
            'cash_difference'      => ['DECIMAL(15,4) NULL DEFAULT NULL', null, null],
        ],
    ];

    public function up(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $prefixed = $this->db->prefixTable($table);

            foreach ($columns as $column => [$definition, $referenced, $referencedColumn]) {
                if (! $this->hasColumn($table, $column)) {
                    $this->db->query("ALTER TABLE `{$prefixed}` ADD COLUMN `{$column}` {$definition}");
                }

                if ($referenced === null) {
                    continue;
                }

                if (! $this->hasIndex($table, "{$table}_{$column}")) {
                    $this->db->query("ALTER TABLE `{$prefixed}` ADD KEY `{$prefixed}_{$column}` (`{$column}`)");
                }

                if (! $this->hasForeignKey($table, "{$table}_fk_{$column}")) {
                    $target = $this->db->prefixTable($referenced);

                    $this->db->query("ALTER TABLE `{$prefixed}` ADD CONSTRAINT `{$prefixed}_fk_{$column}` FOREIGN KEY (`{$column}`) REFERENCES `{$target}` (`{$referencedColumn}`) ON DELETE RESTRICT");
                }
            }
        }
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $columns) {
            $prefixed = $this->db->prefixTable($table);

            foreach ($columns as $column => [, $referenced]) {
                if ($referenced !== null && $this->hasForeignKey($table, "{$table}_fk_{$column}")) {
                    $this->db->query("ALTER TABLE `{$prefixed}` DROP FOREIGN KEY `{$prefixed}_fk_{$column}`");
                }

                if ($referenced !== null && $this->hasIndex($table, "{$table}_{$column}")) {
                    $this->db->query("ALTER TABLE `{$prefixed}` DROP KEY `{$prefixed}_{$column}`");
                }

                if ($this->hasColumn($table, $column)) {
                    $this->db->query("ALTER TABLE `{$prefixed}` DROP COLUMN `{$column}`");
                }
            }
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
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
}
