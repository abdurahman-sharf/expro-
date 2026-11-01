<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Gives each tax a mode of its own and, when the shop wants one, a liability account of its own. The
 * tax list holds them by the name the taxes of an item carry, and a tax with no row, or a row with no
 * mode or account, behaves exactly as it did: it follows the default mode of the shop and goes to the
 * account of tax collected. The tax rows of a sale keep the account their tax was worked out with, next
 * to the mode they already keep, so that a document taken back or read again says what it was saved
 * with. Only a table, a nullable column with its index and a setting with its default are added: no
 * sale, no journal and no amount is touched. Safe to run again, and down() refuses once a tax row of a
 * sale holds an account.
 */
class PerTaxInclusive extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $this->createDefinitions();
        $this->addStamp();

        $config = $this->db->prefixTable('app_config');

        if ($this->hasTable('app_config') && $this->db->table('app_config')->where('key', 'require_tax_1')->countAllResults() === 0) {
            $this->db->query("INSERT INTO `{$config}` (`key`, `value`) VALUES ('require_tax_1', '0')");
        }
    }

    public function down(): void
    {
        $taxes = $this->db->prefixTable('sales_items_taxes');

        if ($this->hasColumn('sales_items_taxes', 'liability_account_id')) {
            if ($this->db->table('sales_items_taxes')->where('liability_account_id IS NOT NULL', null, false)->countAllResults() > 0) {
                throw new RuntimeException('A tax row of a sale holds a liability account; restore from a backup instead of rolling this migration back.');
            }

            if ($this->hasIndex('sales_items_taxes', 'sales_items_taxes_liability')) {
                $this->db->query("ALTER TABLE `{$taxes}` DROP KEY `{$taxes}_liability`");
            }

            $this->db->query("ALTER TABLE `{$taxes}` DROP COLUMN `liability_account_id`");
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('tax_definitions') . '`');
    }

    private function createDefinitions(): void
    {
        $definitions = $this->db->prefixTable('tax_definitions');
        $accounts    = $this->db->prefixTable('accounting_accounts');
        $options     = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$definitions}` (
                `tax_definition_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `tax_name` VARCHAR(255) NOT NULL,
                `tax_included` TINYINT(1) NULL DEFAULT NULL,
                `liability_account_id` INT UNSIGNED NULL DEFAULT NULL,
                PRIMARY KEY (`tax_definition_id`),
                UNIQUE KEY `{$definitions}_name` (`tax_name`),
                KEY `{$definitions}_account` (`liability_account_id`),
                CONSTRAINT `{$definitions}_fk_account` FOREIGN KEY (`liability_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT
            ) {$options}",
        );
    }

    private function addStamp(): void
    {
        $taxes = $this->db->prefixTable('sales_items_taxes');

        if (! $this->hasColumn('sales_items_taxes', 'liability_account_id')) {
            $this->db->query("ALTER TABLE `{$taxes}` ADD COLUMN `liability_account_id` INT UNSIGNED NULL DEFAULT NULL AFTER `tax_category_id`");
        }

        if (! $this->hasIndex('sales_items_taxes', 'sales_items_taxes_liability')) {
            $this->db->query("ALTER TABLE `{$taxes}` ADD KEY `{$taxes}_liability` (`liability_account_id`)");
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

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
