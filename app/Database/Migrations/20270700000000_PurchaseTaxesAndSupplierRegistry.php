<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The purchase taxes of an item (two, as many as its sales taxes) and the commercial registry
 * number of a supplier, next to its tax number. Only columns are added. An item whose purchase tax
 * is empty follows its sales tax of the same number, which is what a receiving line has always
 * defaulted to, so no existing item and no receiving line changes: the lines already saved keep the
 * tax they stored and no journal is read again. Safe to run again. It cannot be taken back once an
 * item or a supplier holds a value, because the value would be lost; restore from a backup instead.
 */
class PurchaseTaxesAndSupplierRegistry extends Migration
{
    private const ITEM_COLUMNS = [
        'purchase_tax_1_name'    => 'VARCHAR(255) NULL DEFAULT NULL',
        'purchase_tax_1_percent' => 'DECIMAL(15,3) NULL DEFAULT NULL',
        'purchase_tax_2_name'    => 'VARCHAR(255) NULL DEFAULT NULL',
        'purchase_tax_2_percent' => 'DECIMAL(15,3) NULL DEFAULT NULL',
    ];

    public function up(): void
    {
        $items     = $this->db->prefixTable('items');
        $suppliers = $this->db->prefixTable('suppliers');

        foreach (self::ITEM_COLUMNS as $column => $definition) {
            if (! $this->hasColumn('items', $column)) {
                $this->db->query("ALTER TABLE `{$items}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        if (! $this->hasColumn('suppliers', 'commercial_registry')) {
            $this->db->query("ALTER TABLE `{$suppliers}` ADD COLUMN `commercial_registry` VARCHAR(64) NOT NULL DEFAULT '' AFTER `tax_id`");
        }
    }

    public function down(): void
    {
        $items     = $this->db->prefixTable('items');
        $suppliers = $this->db->prefixTable('suppliers');

        if ($this->inUse()) {
            throw new RuntimeException('An item has a purchase tax or a supplier a commercial registry number; restore from a backup instead of rolling this migration back.');
        }

        foreach (array_keys(self::ITEM_COLUMNS) as $column) {
            if ($this->hasColumn('items', $column)) {
                $this->db->query("ALTER TABLE `{$items}` DROP COLUMN `{$column}`");
            }
        }

        if ($this->hasColumn('suppliers', 'commercial_registry')) {
            $this->db->query("ALTER TABLE `{$suppliers}` DROP COLUMN `commercial_registry`");
        }
    }

    private function inUse(): bool
    {
        if ($this->hasColumn('items', 'purchase_tax_1_percent') && $this->hasColumn('items', 'purchase_tax_2_percent')) {
            $items = $this->db->prefixTable('items');

            if ($this->db->query("SELECT 1 FROM `{$items}` WHERE `purchase_tax_1_percent` IS NOT NULL OR `purchase_tax_2_percent` IS NOT NULL OR `purchase_tax_1_name` IS NOT NULL OR `purchase_tax_2_name` IS NOT NULL LIMIT 1")->getRow() !== null) {
                return true;
            }
        }

        if ($this->hasColumn('suppliers', 'commercial_registry')) {
            $suppliers = $this->db->prefixTable('suppliers');

            return $this->db->query("SELECT 1 FROM `{$suppliers}` WHERE `commercial_registry` <> '' LIMIT 1")->getRow() !== null;
        }

        return false;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
