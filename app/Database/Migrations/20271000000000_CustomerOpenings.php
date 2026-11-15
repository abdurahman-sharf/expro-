<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a customer owed us, or held with us, before the books began: a document of its own, one
 * revision at a time, whose journal is reversed and posted again when it changes. At most one
 * revision stands for a customer. The credit that is spent on invoices can now also be spent on
 * such a document, so an application points at an invoice or at an opening, one of the two. No
 * permission is added: the opening balance permission governs it. Additive only: the new table, one
 * column and its key on the applications, and the invoice of an application allowed to be empty.
 * Safe to run again.
 */
class CustomerOpenings extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $table     = $this->db->prefixTable('customer_openings');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $customers = $this->db->prefixTable('customers');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `opening_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `customer_id` INT(10) NOT NULL,
                `revision` INT UNSIGNED NOT NULL,
                `side` VARCHAR(8) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `opening_date` DATE NOT NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `status` VARCHAR(10) NOT NULL DEFAULT 'posted',
                `supersedes_id` INT UNSIGNED NULL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reversed_by` INT(10) NULL,
                `reversed_at` DATETIME NULL,
                `reverse_reason` VARCHAR(255) NOT NULL DEFAULT '',
                `standing_customer` INT(10) GENERATED ALWAYS AS (IF(`status` = 'posted', `customer_id`, NULL)) STORED,
                PRIMARY KEY (`opening_id`),
                UNIQUE KEY `{$table}_revision` (`customer_id`, `revision`),
                UNIQUE KEY `{$table}_standing` (`standing_customer`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `opening_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$table}_side` CHECK (`side` IN ('owes_us', 'we_owe')),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('posted', 'reversed'))
            ) {$options}",
        );

        $applications = $this->db->prefixTable('customer_credit_applications');

        if (! $this->hasColumn('customer_credit_applications', 'opening_id')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD COLUMN `opening_id` INT UNSIGNED NULL DEFAULT NULL AFTER `invoice_sale_id`");
        }

        if ($this->columnIsRequired('customer_credit_applications', 'invoice_sale_id')) {
            $this->db->query("ALTER TABLE `{$applications}` MODIFY COLUMN `invoice_sale_id` INT(10) NULL DEFAULT NULL");
        }

        if (! $this->hasIndex('customer_credit_applications', 'customer_credit_applications_opening')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD KEY `{$applications}_opening` (`opening_id`)");
        }

        if (! $this->hasConstraint('customer_credit_applications', 'customer_credit_applications_fk_opening')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD CONSTRAINT `{$applications}_fk_opening` FOREIGN KEY (`opening_id`) REFERENCES `{$table}` (`opening_id`) ON DELETE RESTRICT");
        }

        if (! $this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD CONSTRAINT `{$applications}_target` CHECK ((`invoice_sale_id` IS NULL) <> (`opening_id` IS NULL))");
        }
    }

    public function down(): void
    {
        $applications = $this->db->prefixTable('customer_credit_applications');

        if ($this->hasColumn('customer_credit_applications', 'opening_id')) {
            $this->db->query("DELETE FROM `{$applications}` WHERE `opening_id` IS NOT NULL");

            if ($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target')) {
                $this->db->query("ALTER TABLE `{$applications}` DROP CONSTRAINT `{$applications}_target`");
            }

            if ($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_fk_opening')) {
                $this->db->query("ALTER TABLE `{$applications}` DROP FOREIGN KEY `{$applications}_fk_opening`");
            }

            if ($this->hasIndex('customer_credit_applications', 'customer_credit_applications_opening')) {
                $this->db->query("ALTER TABLE `{$applications}` DROP KEY `{$applications}_opening`");
            }

            $this->db->query("ALTER TABLE `{$applications}` DROP COLUMN `opening_id`");
        }

        if (! $this->columnIsRequired('customer_credit_applications', 'invoice_sale_id')) {
            $this->requireInvoice($applications);
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('customer_openings') . '`');
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }

    private function requireInvoice(string $applications): void
    {
        $mode = $this->db->query('SELECT @@SESSION.sql_mode AS mode')->getRow()->mode;

        $this->db->query("SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES')");

        try {
            $this->db->query("ALTER TABLE `{$applications}` MODIFY COLUMN `invoice_sale_id` INT(10) NOT NULL");
        } finally {
            $this->db->query('SET SESSION sql_mode = ?', [$mode]);
        }
    }

    private function columnIsRequired(string $table, string $column): bool
    {
        $row = $this->db->query(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow();

        return $row !== null && $row->IS_NULLABLE === 'NO';
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($index)],
        )->getRow() !== null;
    }

    private function hasConstraint(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($constraint)],
        )->getRow() !== null;
    }
}
