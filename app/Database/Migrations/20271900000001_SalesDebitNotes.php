<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The sales debit note: a document that raises what a customer owes without an invoice, one journal
 * against the account of the customer, never edited and taken back by reversing its journal. The credit
 * that is spent on invoices and opening balances can now also be spent on a debit note, so an
 * application points at an invoice, at an opening or at a debit note, one of the three. The screen is
 * registered as a module and the permission to create and take back debit notes as a permission, and
 * nothing is granted. Additive only: the new table, one column and its key on the applications, and the
 * rule that an application has exactly one target widened to three (the same check, with the same name). Safe to run again.
 */
class SalesDebitNotes extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE        = 'accounting_debit_notes';
    private const PERMISSION    = 'acc_debit_notes_manage';

    public function up(): void
    {
        $table     = $this->db->prefixTable('sales_debit_notes');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $customers = $this->db->prefixTable('customers');
        $accounts  = $this->db->prefixTable('accounting_accounts');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `debit_note_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `customer_id` INT(10) NOT NULL,
                `note_date` DATE NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `tax_percent` DECIMAL(9,4) NOT NULL DEFAULT 0,
                `tax_amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `total` DECIMAL(15,4) NOT NULL,
                `revenue_account_id` INT UNSIGNED NULL DEFAULT NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `status` VARCHAR(10) NOT NULL DEFAULT 'posted',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reversed_by` INT(10) NULL,
                `reversed_at` DATETIME NULL,
                `reverse_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`debit_note_id`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `note_date`),
                KEY `{$table}_customer` (`customer_id`, `status`, `note_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_account` FOREIGN KEY (`revenue_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0 AND `total` > 0 AND `tax_amount` >= 0),
                CONSTRAINT `{$table}_percent` CHECK (`tax_percent` >= 0 AND `tax_percent` <= 100),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('posted', 'reversed'))
            ) {$options}",
        );

        $applications = $this->db->prefixTable('customer_credit_applications');

        if (! $this->hasColumn('customer_credit_applications', 'debit_note_id')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD COLUMN `debit_note_id` INT UNSIGNED NULL DEFAULT NULL AFTER `opening_id`");
        }

        if (! $this->hasIndex('customer_credit_applications', 'customer_credit_applications_debit_note')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD KEY `{$applications}_debit_note` (`debit_note_id`)");
        }

        if (! $this->hasConstraint('customer_credit_applications', 'customer_credit_applications_fk_debit_note')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD CONSTRAINT `{$applications}_fk_debit_note` FOREIGN KEY (`debit_note_id`) REFERENCES `{$table}` (`debit_note_id`) ON DELETE RESTRICT");
        }

        if (! $this->targetNamesDebitNotes()) {
            if ($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target')) {
                $this->db->query("ALTER TABLE `{$applications}` DROP CONSTRAINT `{$applications}_target`");
            }

            $this->db->query("ALTER TABLE `{$applications}` ADD CONSTRAINT `{$applications}_target` CHECK ((`invoice_sale_id` IS NOT NULL) + (`opening_id` IS NOT NULL) + (`debit_note_id` IS NOT NULL) = 1)");
        }

        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE,
            'name_lang_key' => 'module_' . self::MODULE,
            'desc_lang_key' => 'module_' . self::MODULE . '_desc',
            'sort'          => 134,
        ]);
        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => self::MODULE, 'module_id' => self::MODULE],
            ['permission_id' => self::PERMISSION, 'module_id' => self::MODULE],
        ]);
    }

    public function down(): void
    {
        $this->db->table('grants')->whereIn('permission_id', [self::MODULE, self::PERMISSION])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE, self::PERMISSION])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();

        $applications = $this->db->prefixTable('customer_credit_applications');

        if ($this->hasColumn('customer_credit_applications', 'debit_note_id')) {
            $this->db->query("DELETE FROM `{$applications}` WHERE `debit_note_id` IS NOT NULL");

            if ($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target')) {
                $this->db->query("ALTER TABLE `{$applications}` DROP CONSTRAINT `{$applications}_target`");
            }

            if ($this->hasConstraint('customer_credit_applications', 'customer_credit_applications_fk_debit_note')) {
                $this->db->query("ALTER TABLE `{$applications}` DROP FOREIGN KEY `{$applications}_fk_debit_note`");
            }

            if ($this->hasIndex('customer_credit_applications', 'customer_credit_applications_debit_note')) {
                $this->db->query("ALTER TABLE `{$applications}` DROP KEY `{$applications}_debit_note`");
            }

            $this->db->query("ALTER TABLE `{$applications}` DROP COLUMN `debit_note_id`");
        }

        if ($this->hasColumn('customer_credit_applications', 'opening_id') && ! $this->hasConstraint('customer_credit_applications', 'customer_credit_applications_target')) {
            $this->db->query("ALTER TABLE `{$applications}` ADD CONSTRAINT `{$applications}_target` CHECK ((`invoice_sale_id` IS NULL) <> (`opening_id` IS NULL))");
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('sales_debit_notes') . '`');
    }

    private function targetNamesDebitNotes(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CHECK_CLAUSE LIKE ?',
            [$this->db->prefixTable('customer_credit_applications'), $this->db->prefixTable('customer_credit_applications_target'), '%debit_note_id%'],
        )->getRow() !== null;
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

    private function hasConstraint(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($constraint)],
        )->getRow() !== null;
    }
}
