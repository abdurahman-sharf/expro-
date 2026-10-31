<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the credit flows need: the advance payment of a customer (money paid in with no invoice
 * behind it), the cash refund of the credit a customer holds and of the credit held with a
 * supplier, each with the credit it was taken from, and the record of a return that a manager let
 * through with no invoice to take it back from. The permissions to enter and cancel an advance, to
 * refund credit in cash and to let such a return through are registered and granted to no one.
 * Additive only: tables are created and nothing that exists is altered. Safe to run again.
 */
class CreditFlows extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const PERMISSIONS   = [
        'acc_receivables_manage' => 'accounting_receivables',
        'acc_receivables_refund' => 'accounting_receivables',
        'acc_payables_refund'    => 'accounting_payables',
        'sales_return_override'  => 'sales',
    ];

    public function up(): void
    {
        $this->createAdvances();
        $this->createRefunds('customer', 'customers');
        $this->createRefunds('supplier', 'suppliers');
        $this->createReturnOverrides();

        foreach (self::PERMISSIONS as $permission => $module) {
            $this->db->table('permissions')->ignore(true)->insert(['permission_id' => $permission, 'module_id' => $module]);
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::PERMISSIONS) as $permission) {
            $this->db->table('grants')->where('permission_id', $permission)->delete();
            $this->db->table('permissions')->where('permission_id', $permission)->delete();
        }

        foreach (['sale_return_overrides', 'customer_credit_refund_sources', 'customer_credit_refunds', 'supplier_credit_refund_sources', 'supplier_credit_refunds', 'customer_advances'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function createAdvances(): void
    {
        $table      = $this->db->prefixTable('customer_advances');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $customers  = $this->db->prefixTable('customers');
        $treasuries = $this->db->prefixTable('treasuries');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `advance_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `customer_id` INT(10) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `advance_date` DATE NOT NULL,
                `payment_type` VARCHAR(40) NULL,
                `treasury_id` INT UNSIGNED NULL,
                `reference_code` VARCHAR(64) NOT NULL DEFAULT '',
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `request_key` VARCHAR(64) NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'completed',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`advance_id`),
                UNIQUE KEY `{$table}_request` (`company_id`, `request_key`),
                KEY `{$table}_customer` (`customer_id`, `status`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `advance_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('completed', 'cancelled'))
            ) {$options}",
        );
    }

    /**
     * A refund and the credits it was taken from. The credit is in the sources of the refund and
     * not in a column of the refund, because one refund can use several credits.
     */
    private function createRefunds(string $party, string $partyTable): void
    {
        $table      = $this->db->prefixTable($party . '_credit_refunds');
        $sources    = $this->db->prefixTable($party . '_credit_refund_sources');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $parties    = $this->db->prefixTable($partyTable);
        $treasuries = $this->db->prefixTable('treasuries');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `refund_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `{$party}_id` INT(10) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `refund_date` DATE NOT NULL,
                `payment_type` VARCHAR(40) NULL,
                `treasury_id` INT UNSIGNED NULL,
                `reference_code` VARCHAR(64) NOT NULL DEFAULT '',
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `request_key` VARCHAR(64) NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'completed',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`refund_id`),
                UNIQUE KEY `{$table}_request` (`company_id`, `request_key`),
                KEY `{$table}_party` (`{$party}_id`, `status`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `refund_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_party` FOREIGN KEY (`{$party}_id`) REFERENCES `{$parties}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('completed', 'cancelled'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$sources}` (
                `refund_id` INT UNSIGNED NOT NULL,
                `source_type` VARCHAR(16) NOT NULL,
                `source_id` INT(10) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                PRIMARY KEY (`refund_id`, `source_type`, `source_id`),
                KEY `{$sources}_source` (`source_type`, `source_id`),
                CONSTRAINT `{$sources}_fk_refund` FOREIGN KEY (`refund_id`) REFERENCES `{$table}` (`refund_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$sources}_amount` CHECK (`amount` > 0)
            ) {$options}",
        );
    }

    private function createReturnOverrides(): void
    {
        $table     = $this->db->prefixTable('sale_return_overrides');
        $sales     = $this->db->prefixTable('sales');
        $branches  = $this->db->prefixTable('branches');
        $employees = $this->db->prefixTable('employees');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `sale_id` INT(10) NOT NULL,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `employee_id` INT(10) NOT NULL,
                `reason` VARCHAR(255) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`sale_id`),
                KEY `{$table}_branch` (`branch_id`, `created_at`),
                CONSTRAINT `{$table}_fk_sale` FOREIGN KEY (`sale_id`) REFERENCES `{$sales}` (`sale_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_employee` FOREIGN KEY (`employee_id`) REFERENCES `{$employees}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_reason` CHECK (CHAR_LENGTH(`reason`) > 0)
            ) {$options}",
        );
    }
}
