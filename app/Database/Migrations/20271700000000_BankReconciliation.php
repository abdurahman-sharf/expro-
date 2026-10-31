<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the statements of a bank account and their reconciliation with the books need: the code of
 * the bank a bank account was picked under in the directory, the statements imported by file with
 * their lines, the matches of a statement line with the lines of the journals of the account, and the
 * reconciliations closed up to a date. The permission to reconcile is registered under the module of
 * the treasuries and nothing is granted. Only a nullable column, tables and a permission are added:
 * no journal, no journal line, no document and no balance is touched, and a match is a row of its
 * own that points at a journal line without changing it. Safe to run again.
 */
class BankReconciliation extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const PERMISSION    = ['permission_id' => 'acc_bank_reconcile', 'module_id' => 'accounting_treasuries'];

    public function up(): void
    {
        $this->addBankCode();
        $this->createTables();

        $this->db->table('permissions')->ignore(true)->insert(self::PERMISSION);
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION['permission_id'])->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION['permission_id'])->delete();

        foreach (['bank_match_lines', 'bank_matches', 'bank_reconciliations', 'bank_statement_lines', 'bank_statements'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }

        if ($this->hasColumn('treasuries', 'bank_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('treasuries') . '` DROP COLUMN `bank_code`');
        }
    }

    private function addBankCode(): void
    {
        if (! $this->hasColumn('treasuries', 'bank_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('treasuries') . '` ADD COLUMN `bank_code` VARCHAR(24) NULL DEFAULT NULL AFTER `bank_name`');
        }
    }

    private function createTables(): void
    {
        $companies       = $this->db->prefixTable('companies');
        $branches        = $this->db->prefixTable('branches');
        $treasuries      = $this->db->prefixTable('treasuries');
        $journalLines    = $this->db->prefixTable('accounting_journal_lines');
        $statements      = $this->db->prefixTable('bank_statements');
        $lines           = $this->db->prefixTable('bank_statement_lines');
        $reconciliations = $this->db->prefixTable('bank_reconciliations');
        $matches         = $this->db->prefixTable('bank_matches');
        $matchLines      = $this->db->prefixTable('bank_match_lines');
        $options         = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$statements}` (
                `statement_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `treasury_id` INT UNSIGNED NOT NULL,
                `file_name` VARCHAR(255) NOT NULL DEFAULT '',
                `checksum` CHAR(64) NOT NULL,
                `delimiter_name` VARCHAR(10) NOT NULL,
                `skipped_first_row` TINYINT(1) NOT NULL DEFAULT 0,
                `row_count` INT UNSIGNED NOT NULL,
                `line_count` INT UNSIGNED NOT NULL,
                `duplicate_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `date_from` DATE NOT NULL,
                `date_to` DATE NOT NULL,
                `imported_by` INT(10) NULL,
                `imported_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`statement_id`),
                UNIQUE KEY `{$statements}_checksum` (`treasury_id`, `checksum`),
                KEY `{$statements}_branch` (`company_id`, `branch_id`),
                CONSTRAINT `{$statements}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$statements}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$statements}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$statements}_delimiter` CHECK (`delimiter_name` IN ('comma', 'semicolon', 'tab')),
                CONSTRAINT `{$statements}_counts` CHECK (`line_count` > 0 AND `row_count` >= `line_count`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `line_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `statement_id` INT UNSIGNED NOT NULL,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `treasury_id` INT UNSIGNED NOT NULL,
                `row_no` INT UNSIGNED NOT NULL,
                `line_date` DATE NOT NULL,
                `description` VARCHAR(500) NOT NULL DEFAULT '',
                `reference` VARCHAR(100) NOT NULL DEFAULT '',
                `amount` DECIMAL(15,4) NOT NULL,
                `dedupe_key` CHAR(64) NOT NULL,
                PRIMARY KEY (`line_id`),
                UNIQUE KEY `{$lines}_dedupe` (`treasury_id`, `dedupe_key`),
                KEY `{$lines}_statement` (`statement_id`),
                KEY `{$lines}_date` (`treasury_id`, `line_date`),
                CONSTRAINT `{$lines}_fk_statement` FOREIGN KEY (`statement_id`) REFERENCES `{$statements}` (`statement_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_amount` CHECK (`amount` <> 0)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$reconciliations}` (
                `reconciliation_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `treasury_id` INT UNSIGNED NOT NULL,
                `closed_through` DATE NOT NULL,
                `statement_balance` DECIMAL(15,4) NOT NULL,
                `book_balance` DECIMAL(15,4) NOT NULL,
                `difference` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `match_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `status` VARCHAR(8) NOT NULL DEFAULT 'closed',
                `closed_by` INT(10) NULL,
                `closed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reopened_by` INT(10) NULL,
                `reopened_at` DATETIME NULL,
                `reopen_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`reconciliation_id`),
                KEY `{$reconciliations}_treasury` (`treasury_id`, `status`, `closed_through`),
                CONSTRAINT `{$reconciliations}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$reconciliations}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$reconciliations}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$reconciliations}_status` CHECK (`status` IN ('closed', 'reopened'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$matches}` (
                `match_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `treasury_id` INT UNSIGNED NOT NULL,
                `statement_line_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `method` VARCHAR(10) NOT NULL DEFAULT 'manual',
                `reconciliation_id` INT UNSIGNED NULL DEFAULT NULL,
                `matched_by` INT(10) NULL,
                `matched_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`match_id`),
                UNIQUE KEY `{$matches}_line` (`statement_line_id`),
                KEY `{$matches}_treasury` (`treasury_id`),
                KEY `{$matches}_reconciliation` (`reconciliation_id`),
                CONSTRAINT `{$matches}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$matches}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$matches}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$matches}_fk_line` FOREIGN KEY (`statement_line_id`) REFERENCES `{$lines}` (`line_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$matches}_fk_reconciliation` FOREIGN KEY (`reconciliation_id`) REFERENCES `{$reconciliations}` (`reconciliation_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$matches}_method` CHECK (`method` IN ('manual', 'suggested'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$matchLines}` (
                `match_id` INT UNSIGNED NOT NULL,
                `journal_line_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                PRIMARY KEY (`match_id`, `journal_line_id`),
                UNIQUE KEY `{$matchLines}_journal_line` (`journal_line_id`),
                CONSTRAINT `{$matchLines}_fk_match` FOREIGN KEY (`match_id`) REFERENCES `{$matches}` (`match_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$matchLines}_fk_journal_line` FOREIGN KEY (`journal_line_id`) REFERENCES `{$journalLines}` (`line_id`) ON DELETE RESTRICT
            ) {$options}",
        );
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
