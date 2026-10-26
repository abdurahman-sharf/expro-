<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a payment to a supplier that belongs to no purchase needs, and what a supplier who owes us
 * from before the books started needs: the advance, a sum of money paid to a supplier, or an
 * opening balance the supplier owes. Each is a journal of its own and a credit held with the
 * supplier that is spent on their purchases, the oldest first. An opening balance we owe the
 * supplier is a purchase document without lines, so it needs nothing here. The permission to
 * enter them is registered and granted to no one. Additive only, and safe to run again.
 */
class SupplierAdvances extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_payables';
    private const PERMISSION    = 'acc_payables_manage';

    public function up(): void
    {
        $table      = $this->db->prefixTable('supplier_advances');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $suppliers  = $this->db->prefixTable('suppliers');
        $treasuries = $this->db->prefixTable('treasuries');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `advance_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `supplier_id` INT(10) NOT NULL,
                `kind` VARCHAR(8) NOT NULL DEFAULT 'payment',
                `amount` DECIMAL(15,4) NOT NULL,
                `advance_date` DATE NOT NULL,
                `payment_type` VARCHAR(40) NULL,
                `treasury_id` INT UNSIGNED NULL,
                `reference_code` VARCHAR(64) NOT NULL DEFAULT '',
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `status` VARCHAR(10) NOT NULL DEFAULT 'completed',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`advance_id`),
                KEY `{$table}_supplier` (`supplier_id`, `status`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `advance_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `{$suppliers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$table}_kind` CHECK (`kind` IN ('payment', 'opening')),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('completed', 'cancelled'))
            ) {$options}",
        );

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID]);
    }

    public function down(): void
    {
        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('supplier_advances') . '`');
    }
}
