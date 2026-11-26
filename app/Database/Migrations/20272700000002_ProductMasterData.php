<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Three fields of a product: a brand (a small list of its own), the code the supplier gives the
 * product, and a status. The status has three values,
 * active (0, the value that changes nothing), inactive (1) and suspended (2); both of the last two hide the
 * product from the lists of new documents and mean nothing else, and the `deleted` flag keeps its
 * meaning. Every column may be empty or holds the value that changes nothing, so an item that exists reads
 * as it did and no document, movement or journal is touched. Safe to run again; down() refuses once a
 * value is stored.
 */
class ProductMasterData extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const ITEM_COLUMNS  = [
        'brand_id'      => 'INT UNSIGNED NULL DEFAULT NULL',
        'status'        => 'TINYINT(1) NOT NULL DEFAULT 0',
        'supplier_code' => 'VARCHAR(100) NULL DEFAULT NULL',
    ];

    public function up(): void
    {
        $this->createBrands();
        $this->addItemColumns();
    }

    public function down(): void
    {
        $this->refuseWhileEntered();

        $items = $this->db->prefixTable('items');
        $key   = $items . '_fk_brand';

        if ($this->hasConstraint($items, $key)) {
            $this->db->query("ALTER TABLE `{$items}` DROP FOREIGN KEY `{$key}`");
        }

        foreach (['status_check' => 'CONSTRAINT', 'brand' => 'INDEX', 'status' => 'INDEX'] as $suffix => $kind) {
            $name = $items . '_' . $suffix;

            if ($kind === 'CONSTRAINT' && $this->hasConstraint($items, $name)) {
                $this->db->query("ALTER TABLE `{$items}` DROP CONSTRAINT `{$name}`");
            }

            if ($kind === 'INDEX' && $this->hasIndex($items, $name)) {
                $this->db->query("ALTER TABLE `{$items}` DROP INDEX `{$name}`");
            }
        }

        foreach (array_reverse(array_keys(self::ITEM_COLUMNS)) as $column) {
            if ($this->hasColumn($column)) {
                $this->db->query("ALTER TABLE `{$items}` DROP COLUMN `{$column}`");
            }
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('item_brands') . '`');
    }

    private function refuseWhileEntered(): void
    {
        $entered = [
            'brands' => $this->hasTable('item_brands') && $this->db->table('item_brands')->countAllResults() > 0,
            'items'  => $this->hasColumn('brand_id') && $this->db->table('items')
                ->groupStart()->where('brand_id IS NOT NULL')->orWhere('status !=', 0)->orWhere('supplier_code IS NOT NULL')->groupEnd()
                ->countAllResults() > 0,
        ];

        $names = array_keys(array_filter($entered));

        if ($names !== []) {
            throw new RuntimeException('The product master data holds values (' . implode(', ', $names) . '); taking the migration down would drop them.');
        }
    }

    private function createBrands(): void
    {
        $table = $this->db->prefixTable('item_brands');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `brand_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`brand_id`),
                UNIQUE KEY `{$table}_name` (`name`),
                CONSTRAINT `{$table}_status` CHECK (`status` IN (0, 1))
            ) " . self::TABLE_OPTIONS,
        );
    }

    private function addItemColumns(): void
    {
        $items  = $this->db->prefixTable('items');
        $brands = $this->db->prefixTable('item_brands');

        foreach (self::ITEM_COLUMNS as $column => $definition) {
            if (! $this->hasColumn($column)) {
                $this->db->query("ALTER TABLE `{$items}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        foreach (['brand' => 'brand_id', 'status' => 'status'] as $suffix => $column) {
            $name = $items . '_' . $suffix;

            if (! $this->hasIndex($items, $name)) {
                $this->db->query("ALTER TABLE `{$items}` ADD INDEX `{$name}` (`{$column}`)");
            }
        }

        $key = $items . '_fk_brand';

        if (! $this->hasConstraint($items, $key)) {
            $this->db->query("ALTER TABLE `{$items}` ADD CONSTRAINT `{$key}` FOREIGN KEY (`brand_id`) REFERENCES `{$brands}` (`brand_id`) ON DELETE RESTRICT");
        }

        $check = $items . '_status_check';

        if (! $this->hasConstraint($items, $check)) {
            $this->db->query("ALTER TABLE `{$items}` ADD CONSTRAINT `{$check}` CHECK (`status` IN (0, 1, 2))");
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }

    private function hasColumn(string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('items'), $column],
        )->getRow() !== null;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index],
        )->getRow() !== null;
    }

    private function hasConstraint(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        )->getRow() !== null;
    }
}
