<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the VAT of a sale came to when it was saved under the Saudi rules: the net, VAT and amount with VAT of each
 * line next to the class it was sold under, and the taxable amount and VAT of each category of the sale, the VAT
 * rounded on the category and not summed from the lines. The printout, the journal and the electronic document of
 * the sale read them and never work the tax out again. Nothing existing is changed: the new columns are empty for
 * every line saved before, which is read as it always was. Safe to run again; down() refuses once a sale was saved
 * with them, since its documents need them.
 */
class VatSnapshot extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const LINE_COLUMNS  = [
        'net'          => 'DECIMAL(15,2) NULL DEFAULT NULL',
        'vat'          => 'DECIMAL(15,2) NULL DEFAULT NULL',
        'gross'        => 'DECIMAL(15,2) NULL DEFAULT NULL',
        'calc_version' => 'TINYINT UNSIGNED NULL DEFAULT NULL',
    ];

    public function up(): void
    {
        $lines   = $this->db->prefixTable('sales_items_vat');
        $totals  = $this->db->prefixTable('sales_vat_totals');
        $options = self::TABLE_OPTIONS;

        foreach (self::LINE_COLUMNS as $column => $definition) {
            if (! $this->hasColumn('sales_items_vat', $column)) {
                $this->db->query("ALTER TABLE `{$lines}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$totals}` (
                `sale_id` INT(10) NOT NULL,
                `category` CHAR(1) NOT NULL,
                `percent` DECIMAL(6,3) NOT NULL,
                `exemption_code` VARCHAR(20) NOT NULL DEFAULT '',
                `exemption_text` VARCHAR(1000) NULL DEFAULT NULL,
                `taxable` DECIMAL(15,2) NOT NULL,
                `vat` DECIMAL(15,2) NOT NULL,
                `included` TINYINT(1) NOT NULL DEFAULT 0,
                `calc_version` TINYINT UNSIGNED NOT NULL,
                PRIMARY KEY (`sale_id`, `category`, `percent`, `exemption_code`)
            ) {$options}",
        );
    }

    public function down(): void
    {
        if ($this->hasTable('sales_vat_totals') && $this->db->table('sales_vat_totals')->countAllResults() > 0) {
            throw new RuntimeException('Sales were saved with their VAT worked out once; taking the migration down would drop what their documents need.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('sales_vat_totals') . '`');

        foreach (array_keys(self::LINE_COLUMNS) as $column) {
            if ($this->hasColumn('sales_items_vat', $column)) {
                $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales_items_vat') . "` DROP COLUMN `{$column}`");
            }
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
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
