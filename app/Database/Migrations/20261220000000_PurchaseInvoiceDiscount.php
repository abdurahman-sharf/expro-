<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * A discount on the whole purchase, taken after the discounts of its lines. The purchase keeps what
 * was entered (a percentage or an amount) and each line keeps what it takes of it. Only nullable
 * columns are added, and a purchase saved before has none of it: its lines and its journals are not
 * read again, recalculated or posted again. Safe to run again. It cannot be taken back once a
 * purchase uses it, because the figures of that purchase would then no longer be explained by what
 * is stored; restore from a backup instead.
 */
class PurchaseInvoiceDiscount extends Migration
{
    public function up(): void
    {
        $receivings = $this->db->prefixTable('receivings');
        $items      = $this->db->prefixTable('receivings_items');

        if (! $this->hasColumn('receivings', 'invoice_discount_type')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD COLUMN `invoice_discount_type` TINYINT(1) NULL DEFAULT NULL AFTER `treasury_id`");
        }

        if (! $this->hasColumn('receivings', 'invoice_discount')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD COLUMN `invoice_discount` DECIMAL(15,4) NULL DEFAULT 0 AFTER `invoice_discount_type`");
        }

        if (! $this->hasColumn('receivings_items', 'invoice_discount_share')) {
            $this->db->query("ALTER TABLE `{$items}` ADD COLUMN `invoice_discount_share` DECIMAL(15,4) NULL DEFAULT NULL AFTER `tax_percent`");
        }
    }

    public function down(): void
    {
        $receivings = $this->db->prefixTable('receivings');
        $items      = $this->db->prefixTable('receivings_items');

        if ($this->inUse()) {
            throw new RuntimeException('A purchase carries a discount on the whole invoice; restore from a backup instead of rolling this migration back.');
        }

        if ($this->hasColumn('receivings_items', 'invoice_discount_share')) {
            $this->db->query("ALTER TABLE `{$items}` DROP COLUMN `invoice_discount_share`");
        }

        if ($this->hasColumn('receivings', 'invoice_discount')) {
            $this->db->query("ALTER TABLE `{$receivings}` DROP COLUMN `invoice_discount`");
        }

        if ($this->hasColumn('receivings', 'invoice_discount_type')) {
            $this->db->query("ALTER TABLE `{$receivings}` DROP COLUMN `invoice_discount_type`");
        }
    }

    private function inUse(): bool
    {
        if (! $this->hasColumn('receivings', 'invoice_discount_type')) {
            return false;
        }

        $receivings = $this->db->prefixTable('receivings');

        return $this->db->query("SELECT 1 FROM `{$receivings}` WHERE `invoice_discount_type` IS NOT NULL LIMIT 1")->getRow() !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
