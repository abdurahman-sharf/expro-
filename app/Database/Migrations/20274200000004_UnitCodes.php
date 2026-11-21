<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The UN/ECE code of the main unit of a unit template (Recommendation 20, such as KGM or LTR), which an electronic
 * document writes as the unit of a line in that unit. A template without one writes PCE, as every document did.
 * Safe to run again.
 */
class UnitCodes extends Migration
{
    public function up(): void
    {
        if (! $this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('unit_templates') . '` ADD COLUMN `main_unit_code` VARCHAR(3) NULL DEFAULT NULL AFTER `main_small_name`');
        }
    }

    public function down(): void
    {
        if ($this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('unit_templates') . '` DROP COLUMN `main_unit_code`');
        }
    }

    private function hasColumn(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('unit_templates'), 'main_unit_code'],
        )->getRow() !== null;
    }
}
