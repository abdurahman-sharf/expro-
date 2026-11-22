<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a VAT return is worked out from besides the books: the boxes typed by hand and the review of what the books do
 * not classify (the sales without tax saved before the Saudi rules, shared out among the boxes with a note, and the
 * explanation of the documents whose lines do not match their journals), so that approving the return works it out
 * again from the books as they stand. `estimated` now holds what was not classified. Safe to run again.
 */
class VatReturnReview extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn('manual')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('vat_returns') . '` ADD COLUMN `manual` TEXT NULL DEFAULT NULL AFTER `boxes`');
        }
    }

    public function down(): void
    {
        if ($this->hasColumn('manual')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('vat_returns') . '` DROP COLUMN `manual`');
        }
    }

    private function hasColumn(string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('vat_returns'), $column],
        )->getRow() !== null;
    }
}
