<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the money a customer owes needs from the sale, and the screen that shows it: the status of
 * each payment (only a completed one is in the books), and where the credit of a customer went (a
 * credit note or a payment beyond an invoice, applied to an invoice that was still open, and
 * released again when what it was applied to or taken from changes). Additive only, and safe to
 * run again. Every payment that exists counts as completed.
 */
class SalesReceivables extends Migration
{
    private const MODULE_ID   = 'accounting_receivables';
    private const MODULE_SORT = 123;

    public function up(): void
    {
        $this->registerModule();

        $payments = $this->db->prefixTable('sales_payments');

        if (! $this->hasColumn('sales_payments', 'payment_status')) {
            $this->db->query("ALTER TABLE `{$payments}` ADD COLUMN `payment_status` VARCHAR(12) NOT NULL DEFAULT 'completed'");
        }

        $table     = $this->db->prefixTable('customer_credit_applications');
        $customers = $this->db->prefixTable('customers');
        $sales     = $this->db->prefixTable('sales');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `application_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `customer_id` INT(10) NOT NULL,
                `invoice_sale_id` INT(10) NOT NULL,
                `source_type` VARCHAR(16) NOT NULL,
                `source_id` INT(10) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `automatic` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `released_by` INT(10) NULL,
                `released_at` DATETIME NULL,
                PRIMARY KEY (`application_id`),
                KEY `{$table}_invoice` (`invoice_sale_id`),
                KEY `{$table}_source` (`source_type`, `source_id`),
                KEY `{$table}_customer` (`customer_id`),
                CONSTRAINT `{$table}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_invoice` FOREIGN KEY (`invoice_sale_id`) REFERENCES `{$sales}` (`sale_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::MODULE_ID)->delete();
        $this->db->table('permissions')->where('permission_id', self::MODULE_ID)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('customer_credit_applications') . '`');

        if ($this->hasColumn('sales_payments', 'payment_status')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales_payments') . '` DROP COLUMN `payment_status`');
        }
    }

    /**
     * The screen of customer balances and aging. Nothing is granted: the owner grants it from the
     * employees screen, as with the other accounting screens.
     */
    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::MODULE_SORT,
        ]);

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::MODULE_ID, 'module_id' => self::MODULE_ID]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow()->found > 0;
    }
}
