<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What posting a purchase needs from the purchase itself, and the record of what stock is worth.
 * A purchase gets a kind (received, returned to the supplier, or moved between stock locations),
 * the purchase a return is taken back from, a branch, and the tax rate of each line. Every stock
 * movement that reaches the books is written down with its quantity and its value, so what the
 * stock is worth comes from the movements and ties to the account of the stock location.
 * Additive only, and safe to run again. Every purchase that exists counts as a purchase of the
 * branch of its stock location, at a rate of no tax.
 */
class PurchasesAccounting extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $this->addPurchaseColumns();
        $this->addLineTax();
        $this->createStockEntries();
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('accounting_stock_entries') . '`');

        $lines = $this->db->prefixTable('receivings_items');

        if ($this->hasColumn('receivings_items', 'tax_percent')) {
            $this->db->query("ALTER TABLE `{$lines}` DROP COLUMN `tax_percent`");
        }

        $receivings = $this->db->prefixTable('receivings');

        foreach (['receivings_fk_return_of', 'receivings_fk_branch'] as $constraint) {
            if ($this->hasForeignKey('receivings', $constraint)) {
                $this->db->query("ALTER TABLE `{$receivings}` DROP FOREIGN KEY `" . $this->db->prefixTable($constraint) . '`');
            }
        }

        foreach (['receivings_return_of', 'receivings_branch'] as $index) {
            if ($this->hasIndex('receivings', $index)) {
                $this->db->query("ALTER TABLE `{$receivings}` DROP INDEX `" . $this->db->prefixTable($index) . '`');
            }
        }

        foreach (['return_of_receiving_id', 'receiving_type', 'branch_id'] as $column) {
            if ($this->hasColumn('receivings', $column)) {
                $this->db->query("ALTER TABLE `{$receivings}` DROP COLUMN `{$column}`");
            }
        }
    }

    private function addPurchaseColumns(): void
    {
        $receivings = $this->db->prefixTable('receivings');

        if (! $this->hasColumn('receivings', 'receiving_type')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD COLUMN `receiving_type` VARCHAR(12) NOT NULL DEFAULT 'receive'");
            $this->classifyExisting();
        }

        if (! $this->hasColumn('receivings', 'return_of_receiving_id')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD COLUMN `return_of_receiving_id` INT(10) NULL DEFAULT NULL");
        }

        if (! $this->hasColumn('receivings', 'branch_id')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1");
            $this->locateExisting();
        }

        if (! $this->hasIndex('receivings', 'receivings_return_of')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD INDEX `" . $this->db->prefixTable('receivings_return_of') . '` (`return_of_receiving_id`)');
        }

        if (! $this->hasIndex('receivings', 'receivings_branch')) {
            $this->db->query("ALTER TABLE `{$receivings}` ADD INDEX `" . $this->db->prefixTable('receivings_branch') . '` (`branch_id`, `receiving_time`)');
        }

        if (! $this->hasForeignKey('receivings', 'receivings_fk_return_of')) {
            $this->db->query(
                "ALTER TABLE `{$receivings}` ADD CONSTRAINT `" . $this->db->prefixTable('receivings_fk_return_of') . "` FOREIGN KEY (`return_of_receiving_id`) REFERENCES `{$receivings}` (`receiving_id`) ON DELETE RESTRICT",
            );
        }

        if (! $this->hasForeignKey('receivings', 'receivings_fk_branch')) {
            $this->db->query(
                "ALTER TABLE `{$receivings}` ADD CONSTRAINT `" . $this->db->prefixTable('receivings_fk_branch') . '` FOREIGN KEY (`branch_id`) REFERENCES `'
                . $this->db->prefixTable('branches') . '` (`branch_id`) ON DELETE RESTRICT',
            );
        }
    }

    /**
     * A purchase whose lines all take stock out is a return, and one that moves stock both ways
     * is a transfer between locations. Everything else is a purchase.
     */
    private function classifyExisting(): void
    {
        $receivings = $this->db->prefixTable('receivings');
        $lines      = $this->db->prefixTable('receivings_items');

        $this->db->query(
            "UPDATE `{$receivings}` r SET r.receiving_type = 'requisition'
             WHERE EXISTS (SELECT 1 FROM `{$lines}` l WHERE l.receiving_id = r.receiving_id AND l.quantity_purchased < 0)
               AND EXISTS (SELECT 1 FROM `{$lines}` l WHERE l.receiving_id = r.receiving_id AND l.quantity_purchased > 0)",
        );

        $this->db->query(
            "UPDATE `{$receivings}` r SET r.receiving_type = 'return'
             WHERE r.receiving_type = 'receive'
               AND EXISTS (SELECT 1 FROM `{$lines}` l WHERE l.receiving_id = r.receiving_id AND l.quantity_purchased < 0)",
        );
    }

    private function locateExisting(): void
    {
        $receivings = $this->db->prefixTable('receivings');

        $this->db->query(
            "UPDATE `{$receivings}` r
             JOIN (
                 SELECT l.receiving_id, MIN(sl.branch_id) AS branch_id
                 FROM `" . $this->db->prefixTable('receivings_items') . '` l
                 JOIN `' . $this->db->prefixTable('stock_locations') . '` sl ON sl.location_id = l.item_location
                 GROUP BY l.receiving_id
             ) located ON located.receiving_id = r.receiving_id
             SET r.branch_id = located.branch_id
             WHERE r.branch_id <> located.branch_id',
        );
    }

    private function addLineTax(): void
    {
        if (! $this->hasColumn('receivings_items', 'tax_percent')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('receivings_items') . '` ADD COLUMN `tax_percent` DECIMAL(15,3) NOT NULL DEFAULT 0.000');
        }
    }

    private function createStockEntries(): void
    {
        $table     = $this->db->prefixTable('accounting_stock_entries');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $items     = $this->db->prefixTable('items');
        $locations = $this->db->prefixTable('stock_locations');
        $journals  = $this->db->prefixTable('accounting_journals');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `entry_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `source_type` VARCHAR(32) NOT NULL,
                `source_id` INT UNSIGNED NOT NULL,
                `entry_type` VARCHAR(16) NOT NULL DEFAULT 'general',
                `revision` INT UNSIGNED NOT NULL DEFAULT 1,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `kind` VARCHAR(8) NOT NULL DEFAULT 'posting',
                `entry_date` DATE NOT NULL,
                `item_id` INT(10) NOT NULL,
                `location_id` INT(11) NOT NULL,
                `quantity` DECIMAL(15,3) NOT NULL,
                `value` DECIMAL(15,4) NOT NULL,
                `journal_id` INT UNSIGNED NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`entry_id`),
                UNIQUE KEY `{$table}_source` (`source_type`, `source_id`, `entry_type`, `revision`, `line_no`, `kind`),
                KEY `{$table}_stock` (`item_id`, `location_id`),
                KEY `{$table}_journal` (`journal_id`),
                KEY `{$table}_branch` (`branch_id`, `entry_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_item` FOREIGN KEY (`item_id`) REFERENCES `{$items}` (`item_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_journal` FOREIGN KEY (`journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_kind` CHECK (`kind` IN ('posting', 'reversal'))
            ) {$options}",
        );
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($index)],
        )->getRow()->found > 0;
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($name), 'FOREIGN KEY'],
        )->getRow()->found > 0;
    }
}
