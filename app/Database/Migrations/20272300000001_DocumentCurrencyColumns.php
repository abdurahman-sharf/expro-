<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Where a document keeps its currency and the rate it was captured at, and where a journal line keeps
 * the currency of the amount it carries next to the base amount. A document or a payment with no
 * currency is in the base currency, as every row is today. Treasuries get the currency they hold, and a
 * transfer between treasuries the amount that arrives in the currency of the treasury it goes into.
 * Only nullable columns are added: no journal, no document and no balance is touched. Safe to run again.
 */
class DocumentCurrencyColumns extends Migration
{
    private const DOCUMENTS = ['sales', 'sales_payments', 'receivings', 'receivings_payments', 'expenses', 'revenues'];

    public function up(): void
    {
        foreach (self::DOCUMENTS as $table) {
            if (! $this->hasTable($table)) {
                continue;
            }

            $name = $this->db->prefixTable($table);

            if (! $this->hasColumn($table, 'currency_code')) {
                $this->db->query("ALTER TABLE `{$name}` ADD COLUMN `currency_code` CHAR(3) NULL DEFAULT NULL");
            }

            if (! $this->hasColumn($table, 'currency_rate')) {
                $this->db->query("ALTER TABLE `{$name}` ADD COLUMN `currency_rate` DECIMAL(18,8) NULL DEFAULT NULL");
            }
        }

        if ($this->hasTable('treasuries') && ! $this->hasColumn('treasuries', 'currency_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('treasuries') . '` ADD COLUMN `currency_code` CHAR(3) NULL DEFAULT NULL');
        }

        if ($this->hasTable('treasury_transfers')) {
            $transfers = $this->db->prefixTable('treasury_transfers');

            foreach (['to_amount' => 'DECIMAL(15,4) NULL DEFAULT NULL', 'from_currency' => 'CHAR(3) NULL DEFAULT NULL', 'to_currency' => 'CHAR(3) NULL DEFAULT NULL'] as $column => $definition) {
                if (! $this->hasColumn('treasury_transfers', $column)) {
                    $this->db->query("ALTER TABLE `{$transfers}` ADD COLUMN `{$column}` {$definition}");
                }
            }
        }

        if ($this->hasTable('accounting_journal_lines') && ! $this->hasColumn('accounting_journal_lines', 'currency_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('accounting_journal_lines') . '` ADD COLUMN `currency_code` CHAR(3) NULL DEFAULT NULL AFTER `foreign_amount`');
        }
    }

    public function down(): void
    {
        $this->refuseWhileInUse();

        foreach (self::DOCUMENTS as $table) {
            foreach (['currency_code', 'currency_rate'] as $column) {
                if ($this->hasTable($table) && $this->hasColumn($table, $column)) {
                    $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . "` DROP COLUMN `{$column}`");
                }
            }
        }

        if ($this->hasTable('treasuries') && $this->hasColumn('treasuries', 'currency_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('treasuries') . '` DROP COLUMN `currency_code`');
        }

        foreach (['to_amount', 'from_currency', 'to_currency'] as $column) {
            if ($this->hasTable('treasury_transfers') && $this->hasColumn('treasury_transfers', $column)) {
                $this->db->query('ALTER TABLE `' . $this->db->prefixTable('treasury_transfers') . "` DROP COLUMN `{$column}`");
            }
        }

        if ($this->hasTable('accounting_journal_lines') && $this->hasColumn('accounting_journal_lines', 'currency_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('accounting_journal_lines') . '` DROP COLUMN `currency_code`');
        }
    }

    private function refuseWhileInUse(): void
    {
        $tables = [...self::DOCUMENTS, 'treasuries', 'accounting_journal_lines'];

        foreach ($tables as $table) {
            if ($this->hasTable($table) && $this->hasColumn($table, 'currency_code') && $this->db->table($table)->where('currency_code IS NOT NULL', null, false)->countAllResults() > 0) {
                throw new RuntimeException("Rows of {$table} carry a currency, so the columns are not dropped.");
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

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
