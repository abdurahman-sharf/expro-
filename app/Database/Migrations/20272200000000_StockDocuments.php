<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The stock documents: the numbered stock permits (add, issue and transfer between two stock
 * locations), the stocktake with the quantities it froze and counted, and the stock requests between
 * locations, each with its lines. A document is never deleted: a permit or a stocktake is cancelled by
 * reversing what it posted and a request by a status. A stocktake keeps one slot per location and day
 * while it stands, which a unique key guards. The screens are registered as one module with five
 * permissions, and nothing is granted. Only tables, a module and permissions are added: no journal,
 * no stock movement and no balance is touched. Safe to run again.
 */
class StockDocuments extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE        = 'stock_documents';
    private const SORT          = 140;
    private const PERMISSIONS   = ['stk_permits_manage', 'stk_stocktake_manage', 'stk_stocktake_approve', 'stk_requests_manage', 'stk_requests_approve'];
    private const TABLES        = ['stock_request_lines', 'stock_requests', 'stocktake_lines', 'stocktakes', 'stock_permit_lines', 'stock_permits'];

    public function up(): void
    {
        $this->createPermits();
        $this->createStocktakes();
        $this->createRequests();

        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE,
            'name_lang_key' => 'module_' . self::MODULE,
            'desc_lang_key' => 'module_' . self::MODULE . '_desc',
            'sort'          => self::SORT,
        ]);
        $this->db->table('permissions')->ignore(true)->insertBatch(array_map(
            static fn (string $permissionId): array => ['permission_id' => $permissionId, 'module_id' => self::MODULE],
            [self::MODULE, ...self::PERMISSIONS],
        ));
    }

    public function down(): void
    {
        $permissionIds = [self::MODULE, ...self::PERMISSIONS];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();

        foreach (self::TABLES as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function createPermits(): void
    {
        $table     = $this->db->prefixTable('stock_permits');
        $lines     = $this->db->prefixTable('stock_permit_lines');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $locations = $this->db->prefixTable('stock_locations');
        $customers = $this->db->prefixTable('customers');
        $suppliers = $this->db->prefixTable('suppliers');
        $accounts  = $this->db->prefixTable('accounting_accounts');
        $items     = $this->db->prefixTable('items');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `permit_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `to_branch_id` INT UNSIGNED NULL DEFAULT NULL,
                `permit_type` VARCHAR(10) NOT NULL,
                `permit_date` DATE NOT NULL,
                `location_id` INT(11) NOT NULL,
                `to_location_id` INT(11) NULL DEFAULT NULL,
                `supplier_id` INT(10) NULL DEFAULT NULL,
                `customer_id` INT(10) NULL DEFAULT NULL,
                `account_id` INT UNSIGNED NULL DEFAULT NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `status` VARCHAR(10) NOT NULL DEFAULT 'posted',
                `booked` TINYINT(1) NOT NULL DEFAULT 0,
                `revision` INT UNSIGNED NOT NULL DEFAULT 1,
                `request_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NULL,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`permit_id`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `permit_date`),
                KEY `{$table}_type` (`permit_type`, `status`, `permit_date`),
                KEY `{$table}_location` (`location_id`),
                KEY `{$table}_destination` (`to_location_id`),
                KEY `{$table}_request` (`request_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_to_branch` FOREIGN KEY (`to_branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_to_location` FOREIGN KEY (`to_location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `{$suppliers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_account` FOREIGN KEY (`account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_type_check` CHECK (`permit_type` IN ('add', 'issue', 'transfer')),
                CONSTRAINT `{$table}_status_check` CHECK (`status` IN ('posted', 'cancelled')),
                CONSTRAINT `{$table}_shape_check` CHECK ((`permit_type` = 'transfer') = (`to_location_id` IS NOT NULL))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `permit_id` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `item_id` INT(10) NOT NULL,
                `quantity` DECIMAL(15,3) NOT NULL,
                `unit_cost` DECIMAL(15,6) NOT NULL DEFAULT 0,
                PRIMARY KEY (`permit_id`, `line_no`),
                KEY `{$lines}_item` (`item_id`),
                CONSTRAINT `{$lines}_fk_permit` FOREIGN KEY (`permit_id`) REFERENCES `{$table}` (`permit_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_quantity` CHECK (`quantity` > 0 AND `unit_cost` >= 0)
            ) {$options}",
        );
    }

    private function createStocktakes(): void
    {
        $table     = $this->db->prefixTable('stocktakes');
        $lines     = $this->db->prefixTable('stocktake_lines');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $locations = $this->db->prefixTable('stock_locations');
        $items     = $this->db->prefixTable('items');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `stocktake_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `location_id` INT(11) NOT NULL,
                `count_date` DATE NOT NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'counting',
                `slot` VARCHAR(40) NULL DEFAULT NULL,
                `booked` TINYINT(1) NOT NULL DEFAULT 0,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `started_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `created_by` INT(10) NULL,
                `approved_by` INT(10) NULL,
                `approved_at` DATETIME NULL,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`stocktake_id`),
                UNIQUE KEY `{$table}_slot` (`slot`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `count_date`),
                KEY `{$table}_location` (`location_id`, `count_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_status_check` CHECK (`status` IN ('counting', 'approved', 'cancelled'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `stocktake_id` INT UNSIGNED NOT NULL,
                `item_id` INT(10) NOT NULL,
                `system_quantity` DECIMAL(15,3) NOT NULL,
                `counted_quantity` DECIMAL(15,3) NULL DEFAULT NULL,
                `difference` DECIMAL(15,3) NULL DEFAULT NULL,
                `unit_cost` DECIMAL(15,6) NULL DEFAULT NULL,
                PRIMARY KEY (`stocktake_id`, `item_id`),
                KEY `{$lines}_item` (`item_id`),
                CONSTRAINT `{$lines}_fk_stocktake` FOREIGN KEY (`stocktake_id`) REFERENCES `{$table}` (`stocktake_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_counted` CHECK (`counted_quantity` IS NULL OR `counted_quantity` >= 0)
            ) {$options}",
        );
    }

    private function createRequests(): void
    {
        $table     = $this->db->prefixTable('stock_requests');
        $lines     = $this->db->prefixTable('stock_request_lines');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $locations = $this->db->prefixTable('stock_locations');
        $items     = $this->db->prefixTable('items');
        $permits   = $this->db->prefixTable('stock_permits');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `request_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `from_branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `from_location_id` INT(11) NOT NULL,
                `to_location_id` INT(11) NOT NULL,
                `request_date` DATE NOT NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'requested',
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `permit_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `approved_by` INT(10) NULL,
                `approved_at` DATETIME NULL,
                `fulfilled_by` INT(10) NULL,
                `fulfilled_at` DATETIME NULL,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`request_id`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `request_date`),
                KEY `{$table}_from_branch` (`from_branch_id`),
                KEY `{$table}_status` (`status`, `request_date`),
                KEY `{$table}_from_location` (`from_location_id`),
                KEY `{$table}_to_location` (`to_location_id`),
                KEY `{$table}_permit` (`permit_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_from_branch` FOREIGN KEY (`from_branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_from_location` FOREIGN KEY (`from_location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_to_location` FOREIGN KEY (`to_location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_permit` FOREIGN KEY (`permit_id`) REFERENCES `{$permits}` (`permit_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_status_check` CHECK (`status` IN ('requested', 'approved', 'fulfilled', 'cancelled')),
                CONSTRAINT `{$table}_locations_check` CHECK (`from_location_id` <> `to_location_id`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `request_id` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `item_id` INT(10) NOT NULL,
                `quantity` DECIMAL(15,3) NOT NULL,
                PRIMARY KEY (`request_id`, `line_no`),
                KEY `{$lines}_item` (`item_id`),
                CONSTRAINT `{$lines}_fk_request` FOREIGN KEY (`request_id`) REFERENCES `{$table}` (`request_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_quantity` CHECK (`quantity` > 0)
            ) {$options}",
        );
    }
}
