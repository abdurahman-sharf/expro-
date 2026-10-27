<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What it takes to clear what two branches owe each other, and to say which branch it is owed by:
 * the settlement document, the table that records the branch on the other side of every journal
 * the accounts between branches received from now on, the rows that make two settlements of one
 * pair wait for each other, and the permission to settle, which belongs to the treasuries screen
 * and is granted to nobody. Only tables and a permission are added: no
 * journal, no document and no balance is touched, and the transfers that exist stay as they are,
 * their other branch being read from their own documents. Safe to run again.
 */
class InterBranchSettlements extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_treasuries';
    private const PERMISSION    = 'acc_interbranch_settle';

    public function up(): void
    {
        $this->createSettlements();
        $this->createLegs();
        $this->createLocks();

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID]);
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('interbranch_pair_locks') . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('accounting_interbranch_legs') . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('interbranch_settlements') . '`');
    }

    private function createSettlements(): void
    {
        $table      = $this->db->prefixTable('interbranch_settlements');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $treasuries = $this->db->prefixTable('treasuries');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `settlement_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL,
                `counterpart_branch_id` INT UNSIGNED NOT NULL,
                `kind` VARCHAR(8) NOT NULL,
                `settle_date` DATE NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `payer_branch_id` INT UNSIGNED NULL,
                `from_treasury_id` INT UNSIGNED NULL,
                `to_treasury_id` INT UNSIGNED NULL,
                `first_receivable` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `first_payable` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `second_receivable` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `second_payable` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `request_key` VARCHAR(64) NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `status` VARCHAR(10) NOT NULL DEFAULT 'completed',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`settlement_id`),
                UNIQUE KEY `{$table}_request` (`company_id`, `request_key`),
                KEY `{$table}_pair` (`company_id`, `branch_id`, `counterpart_branch_id`, `settle_date`),
                KEY `{$table}_counterpart` (`counterpart_branch_id`),
                KEY `{$table}_from` (`from_treasury_id`),
                KEY `{$table}_to` (`to_treasury_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_counterpart` FOREIGN KEY (`counterpart_branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_from` FOREIGN KEY (`from_treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_to` FOREIGN KEY (`to_treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_order` CHECK (`branch_id` < `counterpart_branch_id`),
                CONSTRAINT `{$table}_kind` CHECK (`kind` IN ('cash', 'netting')),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('completed', 'cancelled'))
            ) {$options}",
        );
    }

    private function createLocks(): void
    {
        $table   = $this->db->prefixTable('interbranch_pair_locks');
        $options = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL,
                `counterpart_branch_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`company_id`, `branch_id`, `counterpart_branch_id`)
            ) {$options}",
        );
    }

    private function createLegs(): void
    {
        $table     = $this->db->prefixTable('accounting_interbranch_legs');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $journals  = $this->db->prefixTable('accounting_journals');
        $accounts  = $this->db->prefixTable('accounting_accounts');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `journal_id` INT UNSIGNED NOT NULL,
                `account_id` INT UNSIGNED NOT NULL,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `side` VARCHAR(10) NOT NULL,
                `counterpart_branch_id` INT UNSIGNED NOT NULL,
                `document_type` VARCHAR(32) NOT NULL,
                `document_id` INT UNSIGNED NOT NULL,
                `revision` INT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`journal_id`, `account_id`),
                KEY `{$table}_document` (`company_id`, `document_type`, `document_id`, `revision`),
                KEY `{$table}_account` (`account_id`),
                KEY `{$table}_counterpart` (`counterpart_branch_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_journal` FOREIGN KEY (`journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_account` FOREIGN KEY (`account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_counterpart` FOREIGN KEY (`counterpart_branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_side` CHECK (`side` IN ('receivable', 'payable'))
            ) {$options}",
        );
    }
}
