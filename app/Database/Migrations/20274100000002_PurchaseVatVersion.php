<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * Marks a purchase saved while the Saudi rules applied with the version of the VAT rule it is read by: its input
 * VAT is rounded to the halala once for each rate, on what the lines at that rate come to, instead of being kept to
 * four decimals line by line. A purchase is worked out again from its lines every time it is read, by its journal,
 * the cash count and the reports, so the mark is what keeps a purchase saved before the rules applied reading as it
 * always did. Every existing purchase is left without the mark. Safe to run again; down() refuses once a purchase
 * carries it.
 */
class PurchaseVatVersion extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('receivings') . '` ADD COLUMN `vat_calc_version` TINYINT UNSIGNED NULL DEFAULT NULL');
        }
    }

    public function down(): void
    {
        if (! $this->hasColumn()) {
            return;
        }

        if ($this->db->table('receivings')->where('vat_calc_version IS NOT NULL', null, false)->countAllResults() > 0) {
            throw new RuntimeException('Purchases were saved under the Saudi rules; taking the migration down would make them read as they were not.');
        }

        $this->db->query('ALTER TABLE `' . $this->db->prefixTable('receivings') . '` DROP COLUMN `vat_calc_version`');
    }

    private function hasColumn(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('receivings'), 'vat_calc_version'],
        )->getRow() !== null;
    }
}
