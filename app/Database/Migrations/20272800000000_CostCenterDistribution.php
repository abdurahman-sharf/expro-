<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What a journal line can carry beyond its amount: a distribution of the line over cost centers by
 * percentage (the single cost center of the line stays as it was and reads as one row at 100), the
 * default distribution of an account, and the tags and the tax note of a line. Only tables are
 * added, with nothing written to them: no journal, no line and no balance is touched, and a line
 * with no row here is exactly what it was. The rows of a line go with the line when a draft is
 * saved again. Safe to run again.
 */
class CostCenterDistribution extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $lines   = $this->db->prefixTable('accounting_journal_lines');
        $centers = $this->db->prefixTable('accounting_cost_centers');
        $account = $this->db->prefixTable('accounting_accounts');
        $options = self::TABLE_OPTIONS;

        $distributions = $this->db->prefixTable('accounting_journal_line_distributions');
        $defaults      = $this->db->prefixTable('accounting_account_cost_centers');
        $meta          = $this->db->prefixTable('accounting_journal_line_meta');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$distributions}` (
                `distribution_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `line_id` INT UNSIGNED NOT NULL,
                `cost_center_id` INT UNSIGNED NOT NULL,
                `percent` DECIMAL(9,4) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`distribution_id`),
                UNIQUE KEY `{$distributions}_pair` (`line_id`, `cost_center_id`),
                KEY `{$distributions}_center` (`cost_center_id`),
                CONSTRAINT `{$distributions}_fk_line` FOREIGN KEY (`line_id`) REFERENCES `{$lines}` (`line_id`) ON DELETE CASCADE,
                CONSTRAINT `{$distributions}_fk_center` FOREIGN KEY (`cost_center_id`) REFERENCES `{$centers}` (`cost_center_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$distributions}_values` CHECK (`percent` > 0 AND `amount` >= 0)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$defaults}` (
                `account_id` INT UNSIGNED NOT NULL,
                `cost_center_id` INT UNSIGNED NOT NULL,
                `percent` DECIMAL(9,4) NOT NULL,
                PRIMARY KEY (`account_id`, `cost_center_id`),
                KEY `{$defaults}_center` (`cost_center_id`),
                CONSTRAINT `{$defaults}_fk_account` FOREIGN KEY (`account_id`) REFERENCES `{$account}` (`account_id`) ON DELETE CASCADE,
                CONSTRAINT `{$defaults}_fk_center` FOREIGN KEY (`cost_center_id`) REFERENCES `{$centers}` (`cost_center_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$defaults}_percent` CHECK (`percent` > 0)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$meta}` (
                `line_id` INT UNSIGNED NOT NULL,
                `tags` VARCHAR(1000) NULL,
                `tax_code` VARCHAR(16) NULL,
                `tax_percent` DECIMAL(9,4) NULL,
                `tax_included` TINYINT(1) NULL,
                `tax_amount` DECIMAL(15,4) NULL,
                PRIMARY KEY (`line_id`),
                CONSTRAINT `{$meta}_fk_line` FOREIGN KEY (`line_id`) REFERENCES `{$lines}` (`line_id`) ON DELETE CASCADE
            ) {$options}",
        );
    }

    public function down(): void
    {
        foreach (['accounting_journal_line_distributions', 'accounting_account_cost_centers', 'accounting_journal_line_meta'] as $table) {
            if ($this->hasTable($table) && $this->db->table($table)->countAllResults() > 0) {
                throw new RuntimeException('Journal lines carry a cost center distribution, tags or a tax note; restore from a backup instead of rolling this migration back.');
            }
        }

        foreach (['accounting_journal_line_meta', 'accounting_account_cost_centers', 'accounting_journal_line_distributions'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
