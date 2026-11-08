<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the register of fixed assets and their depreciation needs: the categories with the accounts and
 * the method they start from, the assets with the terms the depreciation follows, the units of an asset
 * (serial number, barcode and custody), the runs of depreciation that post one journal for a branch and
 * a month with the line each asset got in it, the disposals (a sale or a write-off), and the units an
 * asset of the units of production method was used for in a month. The screen is registered as a module
 * and the two permissions (to change the register, and to run, approve and take back a depreciation) as
 * permissions, and nothing is granted. Only tables, a module and permissions are added: no journal, no
 * document and no balance is touched. Safe to run again; down() refuses once an asset exists.
 */
class FixedAssets extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_assets';
    private const MODULE_SORT   = 150;
    private const PERMISSIONS   = ['acc_assets_manage', 'acc_assets_depreciate'];
    private const TABLES        = ['asset_usage', 'asset_operations', 'asset_depreciations', 'asset_depreciation_runs', 'asset_units', 'assets', 'asset_categories'];

    public function up(): void
    {
        $this->createCategories();
        $this->createAssets();
        $this->createUnits();
        $this->createRuns();
        $this->createDepreciations();
        $this->createOperations();
        $this->createUsage();
        $this->registerModule();
    }

    public function down(): void
    {
        if ($this->hasTable('assets') && $this->db->table('assets')->countAllResults() > 0) {
            throw new RuntimeException('An asset exists; taking the migration down would drop the register.');
        }

        $ids = [self::MODULE_ID, ...self::PERMISSIONS];

        $this->db->table('grants')->whereIn('permission_id', $ids)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $ids)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();

        foreach (self::TABLES as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function createCategories(): void
    {
        $table     = $this->db->prefixTable('asset_categories');
        $companies = $this->db->prefixTable('companies');
        $accounts  = $this->db->prefixTable('accounting_accounts');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `category_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `name` VARCHAR(100) NOT NULL,
                `cost_account_id` INT UNSIGNED NULL,
                `accumulated_account_id` INT UNSIGNED NULL,
                `expense_account_id` INT UNSIGNED NULL,
                `method` VARCHAR(12) NULL,
                `life_months` SMALLINT UNSIGNED NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`category_id`),
                UNIQUE KEY `{$table}_name` (`company_id`, `name`),
                KEY `{$table}_cost` (`cost_account_id`),
                KEY `{$table}_accumulated` (`accumulated_account_id`),
                KEY `{$table}_expense` (`expense_account_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_cost` FOREIGN KEY (`cost_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_accumulated` FOREIGN KEY (`accumulated_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_expense` FOREIGN KEY (`expense_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_method` CHECK (`method` IS NULL OR `method` IN ('fixed', 'declining', 'units', 'none'))
            ) {$options}",
        );
    }

    private function createAssets(): void
    {
        $table      = $this->db->prefixTable('assets');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $categories = $this->db->prefixTable('asset_categories');
        $accounts   = $this->db->prefixTable('accounting_accounts');
        $centers    = $this->db->prefixTable('accounting_cost_centers');
        $treasuries = $this->db->prefixTable('treasuries');
        $people     = $this->db->prefixTable('people');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `asset_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `code` VARCHAR(64) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `category_id` INT UNSIGNED NULL,
                `status` VARCHAR(12) NOT NULL DEFAULT 'active',
                `description` VARCHAR(1000) NOT NULL DEFAULT '',
                `supplier_id` INT(10) NULL,
                `purchase_reference` VARCHAR(100) NOT NULL DEFAULT '',
                `cost_center_id` INT UNSIGNED NULL,
                `purchase_date` DATE NOT NULL,
                `in_service_date` DATE NOT NULL,
                `cost` DECIMAL(15,4) NOT NULL,
                `salvage` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `useful_life_months` SMALLINT UNSIGNED NULL,
                `method` VARCHAR(12) NOT NULL,
                `period_length` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `period_interval` VARCHAR(5) NOT NULL DEFAULT 'month',
                `amount_per_period` DECIMAL(15,4) NULL,
                `percentage` DECIMAL(9,4) NULL,
                `cost_per_unit` DECIMAL(15,6) NULL,
                `unit_name` VARCHAR(100) NOT NULL DEFAULT '',
                `total_units` DECIMAL(15,2) NULL,
                `first_depreciation_date` DATE NULL,
                `end_date` DATE NULL,
                `cost_account_id` INT UNSIGNED NOT NULL,
                `accumulated_account_id` INT UNSIGNED NULL,
                `expense_account_id` INT UNSIGNED NULL,
                `funding` VARCHAR(10) NOT NULL DEFAULT 'none',
                `treasury_id` INT UNSIGNED NULL,
                `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
                `track_units` TINYINT(1) NOT NULL DEFAULT 0,
                `opening_accumulated` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `opening_through` DATE NULL,
                `disposed_at` DATE NULL,
                `deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `code_slot` VARCHAR(64) GENERATED ALWAYS AS (IF(`deleted` = 0, `code`, NULL)) VIRTUAL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`asset_id`),
                UNIQUE KEY `{$table}_code` (`company_id`, `code_slot`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `status`),
                KEY `{$table}_category` (`category_id`),
                KEY `{$table}_dates` (`purchase_date`, `in_service_date`),
                KEY `{$table}_cost_account` (`cost_account_id`),
                KEY `{$table}_accumulated_account` (`accumulated_account_id`),
                KEY `{$table}_expense_account` (`expense_account_id`),
                KEY `{$table}_treasury` (`treasury_id`),
                KEY `{$table}_supplier` (`supplier_id`),
                KEY `{$table}_center` (`cost_center_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_category` FOREIGN KEY (`category_id`) REFERENCES `{$categories}` (`category_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_cost_account` FOREIGN KEY (`cost_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_accumulated_account` FOREIGN KEY (`accumulated_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_expense_account` FOREIGN KEY (`expense_account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `{$people}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_center` FOREIGN KEY (`cost_center_id`) REFERENCES `{$centers}` (`cost_center_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amounts` CHECK (`cost` > 0 AND `salvage` >= 0 AND `salvage` <= `cost` AND `opening_accumulated` >= 0),
                CONSTRAINT `{$table}_quantity` CHECK (`quantity` >= 1 AND `period_length` >= 1),
                CONSTRAINT `{$table}_method` CHECK (`method` IN ('fixed', 'declining', 'units', 'none')),
                CONSTRAINT `{$table}_interval` CHECK (`period_interval` IN ('month', 'day', 'week')),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('active', 'inactive', 'consumed', 'sold', 'written_off')),
                CONSTRAINT `{$table}_funding` CHECK (`funding` IN ('treasury', 'payable', 'opening', 'none'))
            ) {$options}",
        );
    }

    private function createUnits(): void
    {
        $table     = $this->db->prefixTable('asset_units');
        $companies = $this->db->prefixTable('companies');
        $assets    = $this->db->prefixTable('assets');
        $people    = $this->db->prefixTable('people');
        $locations = $this->db->prefixTable('stock_locations');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `unit_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `asset_id` INT UNSIGNED NOT NULL,
                `position` SMALLINT UNSIGNED NOT NULL,
                `serial_number` VARCHAR(255) NULL,
                `barcode` VARCHAR(255) NULL,
                `used_by` VARCHAR(12) NOT NULL DEFAULT 'not_assigned',
                `employee_id` INT(10) NULL,
                `stock_location_id` INT(11) NULL,
                `physical_location` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`unit_id`),
                UNIQUE KEY `{$table}_position` (`asset_id`, `position`),
                UNIQUE KEY `{$table}_serial` (`company_id`, `serial_number`),
                UNIQUE KEY `{$table}_barcode` (`company_id`, `barcode`),
                KEY `{$table}_employee` (`employee_id`),
                KEY `{$table}_location` (`stock_location_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_asset` FOREIGN KEY (`asset_id`) REFERENCES `{$assets}` (`asset_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_employee` FOREIGN KEY (`employee_id`) REFERENCES `{$people}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_location` FOREIGN KEY (`stock_location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_used_by` CHECK (`used_by` IN ('not_assigned', 'employee', 'storage'))
            ) {$options}",
        );
    }

    private function createRuns(): void
    {
        $table     = $this->db->prefixTable('asset_depreciation_runs');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $journals  = $this->db->prefixTable('accounting_journals');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `run_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL,
                `period_start` DATE NOT NULL,
                `period_end` DATE NOT NULL,
                `run_number` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                `total` DECIMAL(15,4) NOT NULL,
                `line_count` INT UNSIGNED NOT NULL,
                `fingerprint` CHAR(64) NOT NULL,
                `journal_id` INT UNSIGNED NULL,
                `reversal_journal_id` INT UNSIGNED NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'active',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reversed_by` INT(10) NULL,
                `reversed_at` DATETIME NULL,
                `reverse_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`run_id`),
                UNIQUE KEY `{$table}_number` (`company_id`, `branch_id`, `period_start`, `run_number`),
                KEY `{$table}_branch` (`branch_id`),
                KEY `{$table}_journal` (`journal_id`),
                KEY `{$table}_reversal` (`reversal_journal_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_journal` FOREIGN KEY (`journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_reversal` FOREIGN KEY (`reversal_journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('active', 'reversed'))
            ) {$options}",
        );
    }

    private function createDepreciations(): void
    {
        $table     = $this->db->prefixTable('asset_depreciations');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $assets    = $this->db->prefixTable('assets');
        $runs      = $this->db->prefixTable('asset_depreciation_runs');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `depreciation_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL,
                `asset_id` INT UNSIGNED NOT NULL,
                `run_id` INT UNSIGNED NOT NULL,
                `period_start` DATE NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `units` DECIMAL(15,2) NULL,
                `active_key` TINYINT UNSIGNED NULL DEFAULT 1,
                PRIMARY KEY (`depreciation_id`),
                UNIQUE KEY `{$table}_active` (`asset_id`, `period_start`, `active_key`),
                KEY `{$table}_run` (`run_id`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `period_start`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_asset` FOREIGN KEY (`asset_id`) REFERENCES `{$assets}` (`asset_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_run` FOREIGN KEY (`run_id`) REFERENCES `{$runs}` (`run_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$table}_active_key` CHECK (`active_key` IS NULL OR `active_key` = 1)
            ) {$options}",
        );
    }

    private function createOperations(): void
    {
        $table      = $this->db->prefixTable('asset_operations');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $assets     = $this->db->prefixTable('assets');
        $treasuries = $this->db->prefixTable('treasuries');
        $journals   = $this->db->prefixTable('accounting_journals');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `operation_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL,
                `asset_id` INT UNSIGNED NOT NULL,
                `kind` VARCHAR(12) NOT NULL,
                `operation_date` DATE NOT NULL,
                `proceeds` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `treasury_id` INT UNSIGNED NULL,
                `cost_removed` DECIMAL(15,4) NOT NULL,
                `accumulated_removed` DECIMAL(15,4) NOT NULL,
                `result_amount` DECIMAL(15,4) NOT NULL,
                `previous_status` VARCHAR(12) NOT NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `journal_id` INT UNSIGNED NULL,
                `reversal_journal_id` INT UNSIGNED NULL,
                `status` VARCHAR(10) NOT NULL DEFAULT 'active',
                `active_key` TINYINT UNSIGNED NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `reversed_by` INT(10) NULL,
                `reversed_at` DATETIME NULL,
                `reverse_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`operation_id`),
                UNIQUE KEY `{$table}_active` (`asset_id`, `active_key`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `operation_date`),
                KEY `{$table}_treasury` (`treasury_id`),
                KEY `{$table}_journal` (`journal_id`),
                KEY `{$table}_reversal` (`reversal_journal_id`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_asset` FOREIGN KEY (`asset_id`) REFERENCES `{$assets}` (`asset_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_journal` FOREIGN KEY (`journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_reversal` FOREIGN KEY (`reversal_journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_kind` CHECK (`kind` IN ('sale', 'write_off')),
                CONSTRAINT `{$table}_proceeds` CHECK (`proceeds` >= 0),
                CONSTRAINT `{$table}_active_key` CHECK ((`status` = 'active' AND `active_key` <=> 1) OR (`status` = 'reversed' AND `active_key` IS NULL))
            ) {$options}",
        );
    }

    private function createUsage(): void
    {
        $table     = $this->db->prefixTable('asset_usage');
        $companies = $this->db->prefixTable('companies');
        $assets    = $this->db->prefixTable('assets');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `usage_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `asset_id` INT UNSIGNED NOT NULL,
                `period_start` DATE NOT NULL,
                `units` DECIMAL(15,2) NOT NULL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`usage_id`),
                UNIQUE KEY `{$table}_period` (`asset_id`, `period_start`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_asset` FOREIGN KEY (`asset_id`) REFERENCES `{$assets}` (`asset_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_units` CHECK (`units` >= 0)
            ) {$options}",
        );
    }

    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::MODULE_SORT,
        ]);

        $permissions = [['permission_id' => self::MODULE_ID, 'module_id' => self::MODULE_ID]];

        foreach (self::PERMISSIONS as $permissionId) {
            $permissions[] = ['permission_id' => $permissionId, 'module_id' => self::MODULE_ID];
        }

        $this->db->table('permissions')->ignore(true)->insertBatch($permissions);
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
