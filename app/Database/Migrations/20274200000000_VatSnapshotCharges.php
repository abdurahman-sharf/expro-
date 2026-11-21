<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a category of a sale saved under the Saudi rules took from the charges of its header, its shipping, so that
 * the taxable amount of the category is its lines and its charges (R-04 §9.2) while what the lines come to can still
 * be told from it. Every category saved before took none. Safe to run again.
 */
class VatSnapshotCharges extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales_vat_totals') . '` ADD COLUMN `charges` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `taxable`');
        }
    }

    public function down(): void
    {
        if ($this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('sales_vat_totals') . '` DROP COLUMN `charges`');
        }
    }

    private function hasColumn(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('sales_vat_totals'), 'charges'],
        )->getRow() !== null;
    }
}
