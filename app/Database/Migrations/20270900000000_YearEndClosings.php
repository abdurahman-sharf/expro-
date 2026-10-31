<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What closing a year in the books needs: one row for each closing journal a branch holds for a
 * fiscal year, with the figures it was made from, and the permission to make and take back a
 * closing, which belongs to the periods screen and is granted to nobody. A closing that was taken
 * back keeps its row and gives up its active key, so a branch has at most one active closing for a
 * year and any number of taken-back ones. Only a table and a permission are added: no journal, no
 * period and no balance is touched. Safe to run again.
 */
class YearEndClosings extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_periods';
    private const PERMISSION    = 'acc_year_end_close';

    public function up(): void
    {
        $this->createClosings();

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID]);
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('accounting_year_end_closings') . '`');
    }

    private function createClosings(): void
    {
        $table     = $this->db->prefixTable('accounting_year_end_closings');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $years     = $this->db->prefixTable('accounting_fiscal_years');
        $accounts  = $this->db->prefixTable('accounting_accounts');
        $journals  = $this->db->prefixTable('accounting_journals');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `closing_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL,
                `fiscal_year_id` INT UNSIGNED NOT NULL,
                `revision` INT UNSIGNED NOT NULL DEFAULT 1,
                `period_from` DATE NOT NULL,
                `period_to` DATE NOT NULL,
                `retained_account_id` INT UNSIGNED NOT NULL,
                `result_amount` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `line_count` INT UNSIGNED NOT NULL DEFAULT 0,
                `fingerprint` CHAR(64) NOT NULL,
                `journal_id` INT UNSIGNED NULL,
                `reversal_journal_id` INT UNSIGNED NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'active',
                `active_key` TINYINT UNSIGNED NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reversed_by` INT(10) NULL,
                `reversed_at` DATETIME NULL,
                `reverse_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`closing_id`),
                UNIQUE KEY `{$table}_active` (`company_id`, `branch_id`, `fiscal_year_id`, `active_key`),
                UNIQUE KEY `{$table}_revision` (`company_id`, `branch_id`, `fiscal_year_id`, `revision`),
                KEY `{$table}_year` (`fiscal_year_id`),
                KEY `{$table}_branch` (`branch_id`),
                KEY `{$table}_account` (`retained_account_id`),
                KEY `{$table}_journal` (`journal_id`),
                KEY `{$table}_reversal` (`reversal_journal_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_year` FOREIGN KEY (`fiscal_year_id`) REFERENCES `{$years}` (`fiscal_year_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_account` FOREIGN KEY (`retained_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_journal` FOREIGN KEY (`journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_reversal` FOREIGN KEY (`reversal_journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('active', 'reversed')),
                CONSTRAINT `{$table}_active_key` CHECK ((`status` = 'active' AND `active_key` = 1) OR (`status` = 'reversed' AND `active_key` IS NULL))
            ) {$options}",
        );
    }
}
