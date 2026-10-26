<?php

namespace App\Database\Migrations;

use App\Libraries\Accounting\BankAccountPlacement;
use CodeIgniter\Database\Migration;

/**
 * A bank account is kept under the main account of the banks (1202) and carries the name of its
 * bank and its account number, which the screen asks for. The columns and the routing row are
 * additive. The accounts of the bank accounts opened earlier under the main treasury account are
 * taken to the banks: only their place in the chart changes, so no journal line is touched and no
 * balance changes, and that is checked inside the same transaction. A bank account opened before
 * the two details were asked keeps them empty until somebody completes them. Safe to run again.
 */
class BankAccounts extends Migration
{
    public function up(): void
    {
        $this->addColumns();

        $placement = new BankAccountPlacement($this->db);

        if ($placement->openRouting()) {
            $moved = $placement->move();

            log_message('notice', 'BankAccounts moved ' . count($moved) . ' bank account(s) under the main account of the banks.');
        }
    }

    public function down(): void
    {
        $treasuries = $this->db->prefixTable('treasuries');

        if ($this->hasIndex('treasuries', 'treasuries_bank_account')) {
            $this->db->query("ALTER TABLE `{$treasuries}` DROP INDEX `" . $this->db->prefixTable('treasuries_bank_account') . '`');
        }

        foreach (['account_number', 'bank_name'] as $column) {
            if ($this->hasColumn('treasuries', $column)) {
                $this->db->query("ALTER TABLE `{$treasuries}` DROP COLUMN `{$column}`");
            }
        }

        $this->db->table('accounting_routing')->where('routing_key', 'treasuries.banks')->delete();
    }

    private function addColumns(): void
    {
        $treasuries = $this->db->prefixTable('treasuries');

        if (! $this->hasColumn('treasuries', 'bank_name')) {
            $this->db->query("ALTER TABLE `{$treasuries}` ADD COLUMN `bank_name` VARCHAR(120) NULL DEFAULT NULL AFTER `kind`");
        }

        if (! $this->hasColumn('treasuries', 'account_number')) {
            $this->db->query("ALTER TABLE `{$treasuries}` ADD COLUMN `account_number` VARCHAR(34) NULL DEFAULT NULL AFTER `bank_name`");
        }

        if (! $this->hasIndex('treasuries', 'treasuries_bank_account')) {
            $this->db->query("ALTER TABLE `{$treasuries}` ADD UNIQUE INDEX `" . $this->db->prefixTable('treasuries_bank_account') . '` (`company_id`, `bank_name`, `account_number`)');
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
}
