<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the money owed to a supplier needs from the purchase, and the screen that shows it: the
 * payments made against a purchase after it was saved, each with the status that says whether it
 * is in the books, and where the credit held with a supplier went (a debit note, or a payment
 * beyond a purchase, applied to a purchase that was still open, and released again when what it
 * was applied to or taken from changes). Additive only, and safe to run again. The payment made
 * at once, when a purchase is saved, stays on the purchase as it was.
 */
class PurchasesPayables extends Migration
{
    private const MODULE_ID   = 'accounting_payables';
    private const MODULE_SORT = 126;

    public function up(): void
    {
        $this->registerModule();

        $payments   = $this->db->prefixTable('receivings_payments');
        $receivings = $this->db->prefixTable('receivings');
        $suppliers  = $this->db->prefixTable('suppliers');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$payments}` (
                `payment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `receiving_id` INT(10) NOT NULL,
                `payment_type` VARCHAR(40) NOT NULL,
                `payment_amount` DECIMAL(15,2) NOT NULL,
                `payment_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `employee_id` INT(10) NULL,
                `reference_code` VARCHAR(255) NOT NULL DEFAULT '',
                `payment_status` VARCHAR(12) NOT NULL DEFAULT 'completed',
                PRIMARY KEY (`payment_id`),
                KEY `{$payments}_receiving` (`receiving_id`),
                CONSTRAINT `{$payments}_fk_receiving` FOREIGN KEY (`receiving_id`) REFERENCES `{$receivings}` (`receiving_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$payments}_amount` CHECK (`payment_amount` <> 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );

        $table = $this->db->prefixTable('supplier_credit_applications');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `application_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `supplier_id` INT(10) NOT NULL,
                `invoice_receiving_id` INT(10) NOT NULL,
                `source_type` VARCHAR(16) NOT NULL,
                `source_id` INT(10) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `automatic` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `released_by` INT(10) NULL,
                `released_at` DATETIME NULL,
                PRIMARY KEY (`application_id`),
                KEY `{$table}_invoice` (`invoice_receiving_id`),
                KEY `{$table}_source` (`source_type`, `source_id`),
                KEY `{$table}_supplier` (`supplier_id`),
                CONSTRAINT `{$table}_fk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `{$suppliers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_invoice` FOREIGN KEY (`invoice_receiving_id`) REFERENCES `{$receivings}` (`receiving_id`) ON DELETE CASCADE,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::MODULE_ID)->delete();
        $this->db->table('permissions')->where('permission_id', self::MODULE_ID)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('supplier_credit_applications') . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('receivings_payments') . '`');
    }

    /**
     * The screen of supplier balances and aging. Nothing is granted: the owner grants it from the
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
}
