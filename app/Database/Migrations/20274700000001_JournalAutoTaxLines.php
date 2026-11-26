<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Marks the journal lines the books add for the tax of another line of a manual journal, so that
 * they can be told from the lines somebody typed. Only a column is added, and every line that is
 * there already keeps it at 0: no journal and no balance is touched. Safe to run again.
 */
class JournalAutoTaxLines extends Migration
{
    private const TABLE  = 'accounting_journal_lines';
    private const COLUMN = 'is_auto_tax';

    public function up(): void
    {
        if ($this->hasTable() && ! $this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable(self::TABLE) . '` ADD COLUMN `' . self::COLUMN . '` TINYINT(1) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        if ($this->hasTable() && $this->hasColumn()) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable(self::TABLE) . '` DROP COLUMN `' . self::COLUMN . '`');
        }
    }

    private function hasTable(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable(self::TABLE)],
        )->getRow() !== null;
    }

    private function hasColumn(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable(self::TABLE), self::COLUMN],
        )->getRow() !== null;
    }
}
