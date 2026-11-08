<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Multiple units, and tracking by serial number or by batch with an expiry date. A unit template names
 * a base unit and the larger units that convert to it by a factor; an item may name one, and may carry
 * a tracking type. The lines of sales, purchases and stock permits gain the unit they were entered in,
 * the factor at the time and the quantity as entered, next to the quantity in base units they have
 * always held, and a check keeps the two equal. Serial numbers, batches and the quantity of each batch
 * per stock location are new tables, and each line of a document records which serials and batches it
 * moved, so that taking a document back puts exactly those back. Existing rows get no unit and no
 * tracking and nothing is rewritten: a legacy item has one unit. The screens are one module with three
 * permissions, and nothing is granted. Safe to run again.
 */
class StockUnitsAndTracking extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE        = 'stock_units';
    private const SORT          = 141;
    private const PERMISSIONS   = ['stk_units_manage', 'stk_tracking_manage', 'stk_batch_manual_pick'];
    private const TABLES        = ['stock_tracking_lines', 'stock_batch_quantities', 'stock_batches', 'stock_serials', 'item_unit_barcodes', 'item_stock_profiles', 'unit_template_units', 'unit_templates'];
    private const LINE_TABLES   = ['sales_items', 'receivings_items', 'stock_permit_lines'];

    public function up(): void
    {
        $this->createTemplates();
        $this->createProfiles();
        $this->createTracking();

        foreach (self::LINE_TABLES as $table) {
            $this->addUnitColumns($table);
        }

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
        $this->refuseWhenUsed();

        $permissionIds = [self::MODULE, ...self::PERMISSIONS];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();

        foreach (self::LINE_TABLES as $table) {
            $this->dropUnitColumns($table);
        }

        foreach (self::TABLES as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function refuseWhenUsed(): void
    {
        foreach (self::LINE_TABLES as $table) {
            if ($this->hasColumn($table, 'unit_id') && $this->db->table($table)->where('unit_id IS NOT NULL', null, false)->countAllResults() > 0) {
                throw new RuntimeException("A line of {$table} was entered in a larger unit; restore from a backup instead of rolling this migration back.");
            }
        }

        foreach (['stock_serials', 'stock_batches'] as $table) {
            if ($this->db->tableExists($table) && $this->db->table($table)->countAllResults() > 0) {
                throw new RuntimeException("{$table} holds records; restore from a backup instead of rolling this migration back.");
            }
        }
    }

    private function createTemplates(): void
    {
        $templates = $this->db->prefixTable('unit_templates');
        $units     = $this->db->prefixTable('unit_template_units');
        $companies = $this->db->prefixTable('companies');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$templates}` (
                `template_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `name` VARCHAR(100) NOT NULL,
                `main_unit_name` VARCHAR(50) NOT NULL,
                `main_small_name` VARCHAR(25) NOT NULL,
                `version` INT UNSIGNED NOT NULL DEFAULT 1,
                `previous_template_id` INT UNSIGNED NULL DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`template_id`),
                UNIQUE KEY `{$templates}_name` (`company_id`, `name`, `version`),
                KEY `{$templates}_previous` (`previous_template_id`),
                CONSTRAINT `{$templates}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$templates}_fk_previous` FOREIGN KEY (`previous_template_id`) REFERENCES `{$templates}` (`template_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$units}` (
                `unit_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `template_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(50) NOT NULL,
                `factor` DECIMAL(18,4) NOT NULL,
                `small_name` VARCHAR(25) NOT NULL,
                `position` SMALLINT UNSIGNED NOT NULL DEFAULT 1,
                PRIMARY KEY (`unit_id`),
                UNIQUE KEY `{$units}_name` (`template_id`, `name`),
                CONSTRAINT `{$units}_fk_template` FOREIGN KEY (`template_id`) REFERENCES `{$templates}` (`template_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$units}_factor` CHECK (`factor` > 0)
            ) {$options}",
        );
    }

    private function createProfiles(): void
    {
        $profiles  = $this->db->prefixTable('item_stock_profiles');
        $barcodes  = $this->db->prefixTable('item_unit_barcodes');
        $templates = $this->db->prefixTable('unit_templates');
        $units     = $this->db->prefixTable('unit_template_units');
        $items     = $this->db->prefixTable('items');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$profiles}` (
                `item_id` INT(10) NOT NULL,
                `unit_template_id` INT UNSIGNED NULL DEFAULT NULL,
                `tracking_type` VARCHAR(10) NOT NULL DEFAULT 'none',
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`item_id`),
                KEY `{$profiles}_template` (`unit_template_id`),
                CONSTRAINT `{$profiles}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$profiles}_fk_template` FOREIGN KEY (`unit_template_id`) REFERENCES `{$templates}` (`template_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$profiles}_tracking` CHECK (`tracking_type` IN ('none', 'serial', 'batch'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$barcodes}` (
                `barcode_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `item_id` INT(10) NOT NULL,
                `unit_id` INT UNSIGNED NULL DEFAULT NULL,
                `barcode` VARCHAR(255) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`barcode_id`),
                UNIQUE KEY `{$barcodes}_barcode` (`barcode`),
                KEY `{$barcodes}_item` (`item_id`),
                KEY `{$barcodes}_unit` (`unit_id`),
                CONSTRAINT `{$barcodes}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$barcodes}_fk_unit` FOREIGN KEY (`unit_id`) REFERENCES `{$units}` (`unit_id`) ON DELETE RESTRICT
            ) {$options}",
        );
    }

    private function createTracking(): void
    {
        $serials   = $this->db->prefixTable('stock_serials');
        $batches   = $this->db->prefixTable('stock_batches');
        $balances  = $this->db->prefixTable('stock_batch_quantities');
        $lines     = $this->db->prefixTable('stock_tracking_lines');
        $items     = $this->db->prefixTable('items');
        $locations = $this->db->prefixTable('stock_locations');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$serials}` (
                `serial_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `item_id` INT(10) NOT NULL,
                `serial_number` VARCHAR(30) NOT NULL,
                `state` VARCHAR(10) NOT NULL DEFAULT 'in_stock',
                `location_id` INT(11) NULL DEFAULT NULL,
                `received_source` VARCHAR(32) NULL DEFAULT NULL,
                `received_id` INT UNSIGNED NULL DEFAULT NULL,
                `sold_source` VARCHAR(32) NULL DEFAULT NULL,
                `sold_id` INT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`serial_id`),
                UNIQUE KEY `{$serials}_unique` (`item_id`, `serial_number`),
                KEY `{$serials}_location` (`location_id`, `item_id`, `state`),
                CONSTRAINT `{$serials}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$serials}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$serials}_state` CHECK (`state` IN ('in_stock', 'sold', 'returned', 'removed')),
                CONSTRAINT `{$serials}_shape` CHECK ((`state` IN ('in_stock', 'returned') AND `location_id` IS NOT NULL) OR (`state` IN ('sold', 'removed') AND `location_id` IS NULL))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$batches}` (
                `batch_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `item_id` INT(10) NOT NULL,
                `batch_number` VARCHAR(60) NOT NULL,
                `expiry_date` DATE NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`batch_id`),
                UNIQUE KEY `{$batches}_unique` (`item_id`, `batch_number`),
                KEY `{$batches}_expiry` (`item_id`, `expiry_date`),
                CONSTRAINT `{$batches}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$balances}` (
                `batch_id` BIGINT UNSIGNED NOT NULL,
                `location_id` INT(11) NOT NULL,
                `quantity` DECIMAL(15,3) NOT NULL DEFAULT 0,
                PRIMARY KEY (`batch_id`, `location_id`),
                KEY `{$balances}_location` (`location_id`),
                CONSTRAINT `{$balances}_fk_batch` FOREIGN KEY (`batch_id`) REFERENCES `{$batches}` (`batch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$balances}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$balances}_quantity` CHECK (`quantity` >= 0)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$lines}` (
                `tracking_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `source_type` VARCHAR(32) NOT NULL,
                `source_id` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `movement` VARCHAR(10) NOT NULL,
                `item_id` INT(10) NOT NULL,
                `location_id` INT(11) NOT NULL,
                `serial_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `batch_id` BIGINT UNSIGNED NULL DEFAULT NULL,
                `quantity` DECIMAL(15,3) NOT NULL,
                `reversal_of` BIGINT UNSIGNED NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`tracking_id`),
                UNIQUE KEY `{$lines}_reversal` (`reversal_of`),
                KEY `{$lines}_source` (`source_type`, `source_id`, `line_no`),
                KEY `{$lines}_item` (`item_id`, `location_id`),
                KEY `{$lines}_serial` (`serial_id`),
                KEY `{$lines}_batch` (`batch_id`),
                CONSTRAINT `{$lines}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_serial` FOREIGN KEY (`serial_id`) REFERENCES `{$serials}` (`serial_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_batch` FOREIGN KEY (`batch_id`) REFERENCES `{$batches}` (`batch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_fk_reversal` FOREIGN KEY (`reversal_of`) REFERENCES `{$lines}` (`tracking_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$lines}_movement` CHECK (`movement` IN ('receive', 'issue', 'sale', 'putback', 'transfer_out', 'transfer_in')),
                CONSTRAINT `{$lines}_one` CHECK ((`serial_id` IS NULL) <> (`batch_id` IS NULL))
            ) {$options}",
        );
    }

    private function addUnitColumns(string $table): void
    {
        $name   = $this->db->prefixTable($table);
        $units  = $this->db->prefixTable('unit_template_units');
        $priced = $table === 'stock_permit_lines' ? 'unit_cost_entered' : 'unit_price_entered';

        if (! $this->hasColumn($table, 'unit_id')) {
            $this->db->query(
                "ALTER TABLE `{$name}`
                    ADD COLUMN `unit_id` INT UNSIGNED NULL DEFAULT NULL,
                    ADD COLUMN `unit_factor` DECIMAL(18,4) NULL DEFAULT NULL,
                    ADD COLUMN `unit_quantity` DECIMAL(15,3) NULL DEFAULT NULL,
                    ADD COLUMN `{$priced}` DECIMAL(24,6) NULL DEFAULT NULL",
            );
        }

        if (! $this->hasForeignKey($table, "{$table}_fk_unit")) {
            $this->db->query("ALTER TABLE `{$name}` ADD KEY `{$name}_unit` (`unit_id`), ADD CONSTRAINT `{$name}_fk_unit` FOREIGN KEY (`unit_id`) REFERENCES `{$units}` (`unit_id`) ON DELETE RESTRICT");
        }

        if (! $this->hasCheck($table, "{$table}_unit_shape")) {
            $base = $table === 'receivings_items' ? '`quantity_purchased` * `receiving_quantity`' : '`' . ($table === 'sales_items' ? 'quantity_purchased' : 'quantity') . '`';

            $this->db->query(
                "ALTER TABLE `{$name}` ADD CONSTRAINT `{$name}_unit_shape` CHECK (`unit_id` IS NULL OR (`unit_factor` > 0 AND `unit_quantity` IS NOT NULL AND ROUND({$base}, 3) = ROUND(`unit_quantity` * `unit_factor`, 3)))",
            );
        }
    }

    private function dropUnitColumns(string $table): void
    {
        $name = $this->db->prefixTable($table);

        if ($this->hasCheck($table, "{$table}_unit_shape")) {
            $this->db->query("ALTER TABLE `{$name}` DROP CONSTRAINT `{$name}_unit_shape`");
        }

        if ($this->hasForeignKey($table, "{$table}_fk_unit")) {
            $this->db->query("ALTER TABLE `{$name}` DROP FOREIGN KEY `{$name}_fk_unit`");
            $this->db->query("ALTER TABLE `{$name}` DROP KEY `{$name}_unit`");
        }

        foreach (['unit_id', 'unit_factor', 'unit_quantity', 'unit_price_entered', 'unit_cost_entered'] as $column) {
            if ($this->hasColumn($table, $column)) {
                $this->db->query("ALTER TABLE `{$name}` DROP COLUMN `{$column}`");
            }
        }
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($constraint), 'FOREIGN KEY'],
        )->getRow() !== null;
    }

    private function hasCheck(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($constraint), 'CHECK'],
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
