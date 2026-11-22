<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The kinds of invoices a unit issues, as its certificate request names them (`1100`: tax invoices and simplified
 * ones), which the compliance checks of its onboarding send a sample of each of. Safe to run again.
 */
class EInvoiceUnitInvoiceTypes extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('einvoice_units') . "` ADD COLUMN `invoice_types` VARCHAR(4) NOT NULL DEFAULT '1100' AFTER `egs_serial`");
        }
    }

    public function down(): void
    {
        if ($this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('einvoice_units') . '` DROP COLUMN `invoice_types`');
        }
    }

    private function hasColumn(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('einvoice_units'), 'invoice_types'],
        )->getRow() !== null;
    }
}
