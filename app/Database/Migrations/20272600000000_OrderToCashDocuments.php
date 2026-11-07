<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The order-to-cash documents: sales orders (a document that posts nothing and moves no stock until an
 * invoice is made from it, with the quantity each invoice took from each line), and the schedules that
 * make invoices and expenses on their own (with a run for every date they were due, one per schedule and
 * date by a unique key). Three modules and their permissions are registered and nothing is granted.
 * Additive only: new tables, no existing table is altered. Safe to run again.
 */
class OrderToCashDocuments extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULES       = [
        'sales_orders'       => [140, ['sales_orders', 'so_add', 'so_edit', 'so_delete', 'so_view_all', 'so_convert']],
        'recurring_invoices' => [141, ['recurring_invoices', 'recurring_invoices_view', 'recurring_invoices_manage']],
        'recurring_expenses' => [142, ['recurring_expenses', 'recurring_expenses_view', 'recurring_expenses_manage']],
    ];

    public function up(): void
    {
        $this->createSalesOrders();
        $this->createSchedules();

        foreach (self::MODULES as $moduleId => [$sort, $permissions]) {
            $this->db->table('modules')->ignore(true)->insert([
                'module_id'     => $moduleId,
                'name_lang_key' => 'module_' . $moduleId,
                'desc_lang_key' => 'module_' . $moduleId . '_desc',
                'sort'          => $sort,
            ]);

            $this->db->table('permissions')->ignore(true)->insertBatch(array_map(static fn (string $permission): array => ['permission_id' => $permission, 'module_id' => $moduleId], $permissions));
        }
    }

    public function down(): void
    {
        $all = [];

        foreach (self::MODULES as $moduleId => [$sort, $permissions]) {
            array_push($all, ...$permissions);
        }

        $this->db->table('grants')->whereIn('permission_id', $all)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $all)->delete();
        $this->db->table('modules')->whereIn('module_id', array_keys(self::MODULES))->delete();

        foreach (['recurring_runs', 'recurring_schedule_reminders', 'recurring_schedule_lines', 'recurring_schedules', 'sales_order_invoice_lines', 'sales_order_invoices', 'sales_order_lines', 'sales_orders'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function createSalesOrders(): void
    {
        $orders    = $this->db->prefixTable('sales_orders');
        $lines     = $this->db->prefixTable('sales_order_lines');
        $links     = $this->db->prefixTable('sales_order_invoices');
        $taken     = $this->db->prefixTable('sales_order_invoice_lines');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $customers = $this->db->prefixTable('customers');
        $items     = $this->db->prefixTable('items');
        $stock     = $this->db->prefixTable('stock_locations');
        $sales     = $this->db->prefixTable('sales');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$orders}` (
                `order_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `customer_id` INT(10) NOT NULL,
                `order_date` DATE NOT NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'open',
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NULL,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`order_id`),
                KEY `{$orders}_branch` (`company_id`, `branch_id`, `order_date`),
                KEY `{$orders}_customer` (`customer_id`, `status`),
                CONSTRAINT `{$orders}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$orders}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$orders}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$orders}_status` CHECK (`status` IN ('open', 'approved', 'cancelled'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `line_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `order_id` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `item_id` INT(10) NOT NULL,
                `description` VARCHAR(255) NOT NULL DEFAULT '',
                `quantity` DECIMAL(15,3) NOT NULL,
                `unit_price` DECIMAL(15,2) NOT NULL,
                `discount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `discount_type` TINYINT(1) NOT NULL DEFAULT 0,
                `item_location` INT(11) NOT NULL,
                PRIMARY KEY (`line_id`),
                UNIQUE KEY `{$lines}_line` (`order_id`, `line_no`),
                KEY `{$lines}_item` (`item_id`),
                CONSTRAINT `{$lines}_fk_order` FOREIGN KEY (`order_id`) REFERENCES `{$orders}` (`order_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_location` FOREIGN KEY (`item_location`) REFERENCES `{$stock}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_quantity` CHECK (`quantity` > 0 AND `unit_price` >= 0 AND `discount` >= 0)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$links}` (
                `sale_id` INT(10) NOT NULL,
                `order_id` INT UNSIGNED NOT NULL,
                `linked_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`sale_id`),
                KEY `{$links}_order` (`order_id`),
                CONSTRAINT `{$links}_fk_sale` FOREIGN KEY (`sale_id`) REFERENCES `{$sales}` (`sale_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$links}_fk_order` FOREIGN KEY (`order_id`) REFERENCES `{$orders}` (`order_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$taken}` (
                `sale_id` INT(10) NOT NULL,
                `order_line_id` INT UNSIGNED NOT NULL,
                `quantity` DECIMAL(15,3) NOT NULL,
                PRIMARY KEY (`sale_id`, `order_line_id`),
                KEY `{$taken}_line` (`order_line_id`),
                CONSTRAINT `{$taken}_fk_link` FOREIGN KEY (`sale_id`) REFERENCES `{$links}` (`sale_id`) ON DELETE CASCADE,
                CONSTRAINT `{$taken}_fk_line` FOREIGN KEY (`order_line_id`) REFERENCES `{$lines}` (`line_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$taken}_quantity` CHECK (`quantity` > 0)
            ) {$options}",
        );
    }

    private function createSchedules(): void
    {
        $schedules  = $this->db->prefixTable('recurring_schedules');
        $lines      = $this->db->prefixTable('recurring_schedule_lines');
        $reminders  = $this->db->prefixTable('recurring_schedule_reminders');
        $runs       = $this->db->prefixTable('recurring_runs');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $customers  = $this->db->prefixTable('customers');
        $items      = $this->db->prefixTable('items');
        $stock      = $this->db->prefixTable('stock_locations');
        $categories = $this->db->prefixTable('expense_categories');
        $suppliers  = $this->db->prefixTable('suppliers');
        $treasuries = $this->db->prefixTable('treasuries');
        $employees  = $this->db->prefixTable('employees');
        $sales      = $this->db->prefixTable('sales');
        $expenses   = $this->db->prefixTable('expenses');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$schedules}` (
                `schedule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `kind` VARCHAR(8) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `status` VARCHAR(8) NOT NULL DEFAULT 'active',
                `period_count` INT UNSIGNED NOT NULL DEFAULT 1,
                `period_unit` VARCHAR(6) NOT NULL DEFAULT 'months',
                `start_date` DATE NOT NULL,
                `end_date` DATE NULL,
                `max_repeats` INT UNSIGNED NOT NULL DEFAULT 0,
                `issue_before_days` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_occurrence` INT UNSIGNED NOT NULL DEFAULT 0,
                `next_due_date` DATE NULL,
                `ended_reason` VARCHAR(12) NULL,
                `employee_id` INT(10) NOT NULL,
                `customer_id` INT(10) NULL,
                `payment_terms_days` SMALLINT UNSIGNED NULL,
                `issue_mode` VARCHAR(5) NULL,
                `expense_category_id` INT(10) NULL,
                `amount` DECIMAL(15,2) NULL,
                `tax_amount` DECIMAL(15,2) NULL,
                `payment_type` VARCHAR(40) NULL,
                `supplier_id` INT(10) NULL,
                `supplier_tax_code` VARCHAR(255) NULL,
                `treasury_id` INT UNSIGNED NULL,
                `comment` VARCHAR(500) NOT NULL DEFAULT '',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`schedule_id`),
                KEY `{$schedules}_due` (`status`, `next_due_date`),
                KEY `{$schedules}_branch` (`company_id`, `branch_id`, `kind`),
                CONSTRAINT `{$schedules}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$schedules}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$schedules}_fk_employee` FOREIGN KEY (`employee_id`) REFERENCES `{$employees}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$schedules}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$schedules}_fk_category` FOREIGN KEY (`expense_category_id`) REFERENCES `{$categories}` (`expense_category_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$schedules}_fk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `{$suppliers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$schedules}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$schedules}_kind` CHECK (`kind` IN ('invoice', 'expense')),
                CONSTRAINT `{$schedules}_status` CHECK (`status` IN ('active', 'paused', 'ended')),
                CONSTRAINT `{$schedules}_unit` CHECK (`period_unit` IN ('days', 'weeks', 'months', 'years')),
                CONSTRAINT `{$schedules}_period` CHECK (`period_count` >= 1),
                CONSTRAINT `{$schedules}_mode` CHECK (`issue_mode` IS NULL OR `issue_mode` IN ('draft', 'issue')),
                CONSTRAINT `{$schedules}_template` CHECK ((`kind` = 'invoice' AND `customer_id` IS NOT NULL AND `issue_mode` IS NOT NULL) OR (`kind` = 'expense' AND `expense_category_id` IS NOT NULL AND `amount` IS NOT NULL AND `payment_type` IS NOT NULL))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `line_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `schedule_id` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `item_id` INT(10) NOT NULL,
                `description` VARCHAR(255) NOT NULL DEFAULT '',
                `quantity` DECIMAL(15,3) NOT NULL,
                `unit_price` DECIMAL(15,2) NOT NULL,
                `discount` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `discount_type` TINYINT(1) NOT NULL DEFAULT 0,
                `item_location` INT(11) NOT NULL,
                PRIMARY KEY (`line_id`),
                UNIQUE KEY `{$lines}_line` (`schedule_id`, `line_no`),
                CONSTRAINT `{$lines}_fk_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `{$schedules}` (`schedule_id`) ON DELETE CASCADE,
                CONSTRAINT `{$lines}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_location` FOREIGN KEY (`item_location`) REFERENCES `{$stock}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_quantity` CHECK (`quantity` > 0 AND `unit_price` >= 0 AND `discount` >= 0)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$reminders}` (
                `reminder_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `schedule_id` INT UNSIGNED NOT NULL,
                `send_when` TINYINT UNSIGNED NOT NULL,
                `days` SMALLINT UNSIGNED NULL,
                PRIMARY KEY (`reminder_id`),
                KEY `{$reminders}_schedule` (`schedule_id`),
                CONSTRAINT `{$reminders}_fk_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `{$schedules}` (`schedule_id`) ON DELETE CASCADE,
                CONSTRAINT `{$reminders}_when` CHECK (`send_when` <= 4 AND ((`send_when` >= 3 AND `days` IS NOT NULL) OR (`send_when` < 3 AND `days` IS NULL)))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$runs}` (
                `run_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `schedule_id` INT UNSIGNED NOT NULL,
                `occurrence` INT UNSIGNED NOT NULL,
                `due_date` DATE NOT NULL,
                `status` VARCHAR(9) NOT NULL,
                `sale_id` INT(10) NULL,
                `expense_id` INT(10) NULL,
                `generated_as` VARCHAR(5) NULL,
                `refusal_source` VARCHAR(12) NULL,
                `refusal_code` VARCHAR(64) NULL,
                `refusal_context` VARCHAR(500) NULL,
                `attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`run_id`),
                UNIQUE KEY `{$runs}_due` (`schedule_id`, `due_date`),
                UNIQUE KEY `{$runs}_occurrence` (`schedule_id`, `occurrence`),
                KEY `{$runs}_sale` (`sale_id`),
                KEY `{$runs}_expense` (`expense_id`),
                CONSTRAINT `{$runs}_fk_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `{$schedules}` (`schedule_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$runs}_fk_sale` FOREIGN KEY (`sale_id`) REFERENCES `{$sales}` (`sale_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$runs}_fk_expense` FOREIGN KEY (`expense_id`) REFERENCES `{$expenses}` (`expense_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$runs}_status` CHECK (`status` IN ('generated', 'refused')),
                CONSTRAINT `{$runs}_outcome` CHECK ((`status` = 'generated' AND ((`sale_id` IS NOT NULL) + (`expense_id` IS NOT NULL)) = 1) OR (`status` = 'refused' AND `sale_id` IS NULL AND `expense_id` IS NULL AND `refusal_code` IS NOT NULL))
            ) {$options}",
        );
    }
}
