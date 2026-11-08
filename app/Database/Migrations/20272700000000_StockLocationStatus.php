<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The status and the primary flag of a stock location. A location is active (the value that changes
 * nothing, so every location that exists stays selectable) or inactive, and exactly one live location
 * is primary: the one with the lowest number is marked, because the first location is the one the shop
 * started with. A generated slot with a unique key lets the database refuse a second live primary, so
 * the rule holds even when a screen forgets it. No stock, journal, document or sub-account is touched.
 * Safe to run again; down() refuses once a location is inactive or the primary was moved.
 */
class StockLocationStatus extends Migration
{
    public function up(): void
    {
        $table = $this->db->prefixTable('stock_locations');
        $key   = $table . '_primary_slot';

        if (! $this->hasColumn('status')) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `status` TINYINT(1) NOT NULL DEFAULT 1");
        }

        if (! $this->hasColumn('is_primary')) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `is_primary` TINYINT(1) NOT NULL DEFAULT 0");
        }

        if (! $this->hasColumn('primary_slot')) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `primary_slot` TINYINT(1) GENERATED ALWAYS AS (IF(`is_primary` = 1 AND `deleted` = 0, 1, NULL)) VIRTUAL");
        }

        if (! $this->hasIndex($table, $key)) {
            $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `{$key}` (`primary_slot`)");
        }

        if (! $this->hasConstraint($table, $table . '_status_check')) {
            $this->db->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$table}_status_check` CHECK (`status` IN (0, 1) AND `is_primary` IN (0, 1))");
        }

        $this->markFirstLocationPrimary($table);
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('stock_locations');
        $key   = $table . '_primary_slot';

        if ($this->hasColumn('status') && $this->hasColumn('is_primary')) {
            $inactive = $this->db->table('stock_locations')->where('status', 0)->countAllResults();

            if ($inactive > 0 || $this->primaryWasMoved()) {
                throw new RuntimeException('The status or the primary flag of a stock location was changed; taking the migration down would drop it.');
            }
        }

        if ($this->hasConstraint($table, $table . '_status_check')) {
            $this->db->query("ALTER TABLE `{$table}` DROP CONSTRAINT `{$table}_status_check`");
        }

        if ($this->hasIndex($table, $key)) {
            $this->db->query("ALTER TABLE `{$table}` DROP INDEX `{$key}`");
        }

        foreach (['primary_slot', 'is_primary', 'status'] as $column) {
            if ($this->hasColumn($column)) {
                $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }
    }

    private function markFirstLocationPrimary(string $table): void
    {
        $hasPrimary = $this->db->table('stock_locations')->where(['deleted' => 0, 'is_primary' => 1])->countAllResults() > 0;

        if ($hasPrimary) {
            return;
        }

        $first = $this->db->table('stock_locations')->selectMin('location_id', 'location_id')->where('deleted', 0)->get()->getRow();

        if ($first !== null && $first->location_id !== null) {
            $this->db->query("UPDATE `{$table}` SET `is_primary` = 1 WHERE `location_id` = ?", [(int) $first->location_id]);
        }
    }

    private function primaryWasMoved(): bool
    {
        $first   = $this->db->table('stock_locations')->selectMin('location_id', 'location_id')->where('deleted', 0)->get()->getRow();
        $primary = $this->db->table('stock_locations')->select('location_id')->where(['deleted' => 0, 'is_primary' => 1])->get()->getRow();

        return $first !== null && $first->location_id !== null && $primary !== null && (int) $primary->location_id !== (int) $first->location_id;
    }

    private function hasColumn(string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('stock_locations'), $column],
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
