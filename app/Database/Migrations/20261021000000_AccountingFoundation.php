<?php

namespace App\Database\Migrations;

use App\Libraries\Accounting\DefaultChart;
use CodeIgniter\Database\Migration;

class AccountingFoundation extends Migration
{
    private const TABLE_OPTIONS        = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const TABLES_IN_DROP_ORDER = [
        'accounting_ai_proposals',
        'accounting_audit_log',
        'accounting_posting_failures',
        'accounting_allocations',
        'accounting_journal_lines',
        'accounting_journals',
        'accounting_backfill_batches',
        'accounting_routing',
        'accounting_sequences',
        'accounting_closed_periods',
        'accounting_fiscal_years',
        'accounting_cost_centers',
        'accounting_accounts',
    ];

    public function up(): void
    {
        foreach ($this->definitions() as $sql) {
            $this->db->query($this->withPrefix($sql));
        }

        $this->db->table('app_config')->ignore(true)->insert(['key' => 'accounting_enabled', 'value' => '0']);

        (new DefaultChart($this->db))->install();
    }

    public function down(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (self::TABLES_IN_DROP_ORDER as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $this->db->table('app_config')
            ->whereIn('key', ['accounting_enabled', 'accounting_start_date', 'accounting_base_currency'])
            ->delete();
    }

    private function withPrefix(string $sql): string
    {
        return str_replace('{p}', $this->db->getPrefix(), $sql);
    }

    /**
     * @return list<string>
     */
    private function definitions(): array
    {
        $options = self::TABLE_OPTIONS;

        return [
            "CREATE TABLE IF NOT EXISTS `{p}accounting_accounts` (
                `account_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_id` INT UNSIGNED NULL,
                `code` VARCHAR(32) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `account_type` VARCHAR(16) NOT NULL,
                `normal_balance` VARCHAR(8) NOT NULL,
                `is_group` TINYINT(1) NOT NULL DEFAULT 0,
                `is_system` TINYINT(1) NOT NULL DEFAULT 0,
                `system_key` VARCHAR(64) NULL,
                `entity_type` VARCHAR(16) NULL,
                `entity_id` INT UNSIGNED NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`account_id`),
                UNIQUE KEY `{p}accounting_accounts_code` (`code`),
                UNIQUE KEY `{p}accounting_accounts_system_key` (`system_key`),
                UNIQUE KEY `{p}accounting_accounts_entity` (`entity_type`, `entity_id`),
                KEY `{p}accounting_accounts_parent` (`parent_id`),
                CONSTRAINT `{p}accounting_accounts_fk_parent` FOREIGN KEY (`parent_id`)
                    REFERENCES `{p}accounting_accounts` (`account_id`) ON DELETE RESTRICT
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_cost_centers` (
                `cost_center_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `parent_id` INT UNSIGNED NULL,
                `code` VARCHAR(32) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`cost_center_id`),
                UNIQUE KEY `{p}accounting_cost_centers_code` (`code`),
                KEY `{p}accounting_cost_centers_parent` (`parent_id`),
                CONSTRAINT `{p}accounting_cost_centers_fk_parent` FOREIGN KEY (`parent_id`)
                    REFERENCES `{p}accounting_cost_centers` (`cost_center_id`) ON DELETE RESTRICT
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_fiscal_years` (
                `fiscal_year_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL,
                `start_date` DATE NOT NULL,
                `end_date` DATE NOT NULL,
                `status` VARCHAR(8) NOT NULL DEFAULT 'open',
                `created_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`fiscal_year_id`),
                KEY `{p}accounting_fiscal_years_dates` (`start_date`, `end_date`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_closed_periods` (
                `closed_period_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `date_from` DATE NOT NULL,
                `date_to` DATE NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `note` VARCHAR(255) NULL,
                `created_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`closed_period_id`),
                KEY `{p}accounting_closed_periods_dates` (`date_from`, `date_to`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_sequences` (
                `series` VARCHAR(16) NOT NULL,
                `last_number` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`series`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_routing` (
                `routing_key` VARCHAR(64) NOT NULL,
                `routing_type` VARCHAR(16) NOT NULL DEFAULT 'auto',
                `account_id` INT UNSIGNED NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`routing_key`),
                KEY `{p}accounting_routing_account` (`account_id`),
                CONSTRAINT `{p}accounting_routing_fk_account` FOREIGN KEY (`account_id`)
                    REFERENCES `{p}accounting_accounts` (`account_id`) ON DELETE RESTRICT
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_backfill_batches` (
                `batch_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `status` VARCHAR(16) NOT NULL DEFAULT 'preview',
                `start_date` DATE NOT NULL,
                `note` VARCHAR(255) NULL,
                `created_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `decided_by` INT NULL,
                `decided_at` DATETIME NULL,
                PRIMARY KEY (`batch_id`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_journals` (
                `journal_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `series` VARCHAR(16) NOT NULL DEFAULT 'manual',
                `number` VARCHAR(32) NULL,
                `journal_date` DATE NOT NULL,
                `description` TEXT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
                `source_type` VARCHAR(32) NOT NULL DEFAULT 'manual',
                `source_id` INT UNSIGNED NULL,
                `entry_type` VARCHAR(16) NOT NULL DEFAULT 'general',
                `revision` INT UNSIGNED NOT NULL DEFAULT 1,
                `origin` VARCHAR(16) NOT NULL DEFAULT 'user',
                `currency_code` CHAR(3) NOT NULL,
                `exchange_rate` DECIMAL(18,8) NOT NULL DEFAULT 1,
                `total_debit` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `total_credit` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `reverses_journal_id` INT UNSIGNED NULL,
                `backfill_batch_id` INT UNSIGNED NULL,
                `document_uuid` CHAR(36) NULL,
                `created_by` INT NULL,
                `posted_by` INT NULL,
                `posted_at` DATETIME NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`journal_id`),
                UNIQUE KEY `{p}accounting_journals_number` (`series`, `number`),
                UNIQUE KEY `{p}accounting_journals_source` (`source_type`, `source_id`, `entry_type`, `revision`),
                KEY `{p}accounting_journals_date` (`journal_date`),
                KEY `{p}accounting_journals_status` (`status`),
                KEY `{p}accounting_journals_batch` (`backfill_batch_id`),
                KEY `{p}accounting_journals_reverses` (`reverses_journal_id`),
                CONSTRAINT `{p}accounting_journals_fk_reverses` FOREIGN KEY (`reverses_journal_id`)
                    REFERENCES `{p}accounting_journals` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{p}accounting_journals_fk_batch` FOREIGN KEY (`backfill_batch_id`)
                    REFERENCES `{p}accounting_backfill_batches` (`batch_id`) ON DELETE RESTRICT
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_journal_lines` (
                `line_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `journal_id` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `account_id` INT UNSIGNED NOT NULL,
                `description` VARCHAR(255) NULL,
                `debit` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `credit` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `foreign_amount` DECIMAL(15,4) NULL,
                `cost_center_id` INT UNSIGNED NULL,
                `party_type` VARCHAR(16) NULL,
                `party_id` INT NULL,
                `tax_code` VARCHAR(16) NULL,
                `tax_rate` DECIMAL(9,4) NULL,
                `tax_base` DECIMAL(15,4) NULL,
                `tax_exemption_reason` VARCHAR(255) NULL,
                PRIMARY KEY (`line_id`),
                UNIQUE KEY `{p}accounting_journal_lines_order` (`journal_id`, `line_no`),
                KEY `{p}accounting_journal_lines_account` (`account_id`),
                KEY `{p}accounting_journal_lines_cost_center` (`cost_center_id`),
                KEY `{p}accounting_journal_lines_party` (`party_type`, `party_id`),
                CONSTRAINT `{p}accounting_journal_lines_fk_journal` FOREIGN KEY (`journal_id`)
                    REFERENCES `{p}accounting_journals` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{p}accounting_journal_lines_fk_account` FOREIGN KEY (`account_id`)
                    REFERENCES `{p}accounting_accounts` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{p}accounting_journal_lines_fk_cost_center` FOREIGN KEY (`cost_center_id`)
                    REFERENCES `{p}accounting_cost_centers` (`cost_center_id`) ON DELETE RESTRICT,
                CONSTRAINT `{p}accounting_journal_lines_amounts` CHECK (`debit` >= 0 AND `credit` >= 0 AND (`debit` = 0 OR `credit` = 0))
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_allocations` (
                `allocation_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `settling_line_id` INT UNSIGNED NOT NULL,
                `settled_line_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `created_by` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`allocation_id`),
                UNIQUE KEY `{p}accounting_allocations_pair` (`settling_line_id`, `settled_line_id`),
                KEY `{p}accounting_allocations_settled` (`settled_line_id`),
                CONSTRAINT `{p}accounting_allocations_fk_settling` FOREIGN KEY (`settling_line_id`)
                    REFERENCES `{p}accounting_journal_lines` (`line_id`) ON DELETE RESTRICT,
                CONSTRAINT `{p}accounting_allocations_fk_settled` FOREIGN KEY (`settled_line_id`)
                    REFERENCES `{p}accounting_journal_lines` (`line_id`) ON DELETE RESTRICT,
                CONSTRAINT `{p}accounting_allocations_amount` CHECK (`amount` > 0)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_posting_failures` (
                `failure_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source_type` VARCHAR(32) NOT NULL,
                `source_id` INT UNSIGNED NULL,
                `entry_type` VARCHAR(16) NULL,
                `revision` INT UNSIGNED NULL,
                `error_code` VARCHAR(64) NOT NULL,
                `message` TEXT NOT NULL,
                `context` TEXT NULL,
                `employee_id` INT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`failure_id`),
                KEY `{p}accounting_posting_failures_source` (`source_type`, `source_id`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_audit_log` (
                `audit_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `entity_type` VARCHAR(32) NOT NULL,
                `entity_id` INT UNSIGNED NULL,
                `action` VARCHAR(32) NOT NULL,
                `employee_id` INT NULL,
                `origin` VARCHAR(16) NOT NULL DEFAULT 'user',
                `details` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`audit_id`),
                KEY `{p}accounting_audit_log_entity` (`entity_type`, `entity_id`),
                KEY `{p}accounting_audit_log_created` (`created_at`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_ai_proposals` (
                `proposal_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `kind` VARCHAR(32) NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
                `payload` TEXT NOT NULL,
                `evidence` TEXT NULL,
                `requested_by` INT NULL,
                `decided_by` INT NULL,
                `decided_at` DATETIME NULL,
                `journal_id` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`proposal_id`),
                KEY `{p}accounting_ai_proposals_status` (`status`),
                KEY `{p}accounting_ai_proposals_journal` (`journal_id`),
                CONSTRAINT `{p}accounting_ai_proposals_fk_journal` FOREIGN KEY (`journal_id`)
                    REFERENCES `{p}accounting_journals` (`journal_id`) ON DELETE RESTRICT
            ) {$options}",
        ];
    }
}
