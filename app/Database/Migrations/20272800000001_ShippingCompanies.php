<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The shipping companies a sale can name for its shipping charge, each with an optional account of what
 * is owed to it, and the nullable column that holds the one a sale named. A sale that names none, and
 * every sale that exists, posts its shipping to the routed account as before. Only a table, a nullable
 * column, its index and its key are added: no journal, no sale and no balance is touched. Safe to run again.
 */
class ShippingCompanies extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $companies = $this->db->prefixTable('shipping_companies');
        $accounts  = $this->db->prefixTable('accounting_accounts');
        $sales     = $this->db->prefixTable('sales');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$companies}` (
                `shipping_company_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `account_id` INT UNSIGNED NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`shipping_company_id`),
                UNIQUE KEY `{$companies}_name` (`name`),
                KEY `{$companies}_account` (`account_id`),
                CONSTRAINT `{$companies}_fk_account` FOREIGN KEY (`account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        if (! $this->hasColumn('sales', 'shipping_company_id')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `shipping_company_id` INT UNSIGNED NULL DEFAULT NULL");
        }

        if (! $this->hasIndex('sales', 'sales_shipping_company')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD KEY `{$sales}_shipping_company` (`shipping_company_id`)");
        }

        if (! $this->hasForeignKey('sales', 'sales_fk_shipping_company')) {
            $this->db->query("ALTER TABLE `{$sales}` ADD CONSTRAINT `{$sales}_fk_shipping_company` FOREIGN KEY (`shipping_company_id`) REFERENCES `{$companies}` (`shipping_company_id`) ON DELETE RESTRICT");
        }
    }

    public function down(): void
    {
        $sales = $this->db->prefixTable('sales');

        if ($this->hasColumn('sales', 'shipping_company_id') && $this->db->table('sales')->where('shipping_company_id IS NOT NULL', null, false)->countAllResults() > 0) {
            throw new RuntimeException('A sale names a shipping company; restore from a backup instead of rolling this migration back.');
        }

        if ($this->hasForeignKey('sales', 'sales_fk_shipping_company')) {
            $this->db->query("ALTER TABLE `{$sales}` DROP FOREIGN KEY `{$sales}_fk_shipping_company`");
        }

        if ($this->hasIndex('sales', 'sales_shipping_company')) {
            $this->db->query("ALTER TABLE `{$sales}` DROP KEY `{$sales}_shipping_company`");
        }

        if ($this->hasColumn('sales', 'shipping_company_id')) {
            $this->db->query("ALTER TABLE `{$sales}` DROP COLUMN `shipping_company_id`");
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('shipping_companies') . '`');
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
