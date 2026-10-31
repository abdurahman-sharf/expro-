<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The state of a quote, kept apart from the status of the sale that holds it, and the quote an
 * invoice was made from. Only nullable columns and an index are added and nothing is read, written
 * or recomputed: a quote that exists has no state and is read as open, and every invoice and every
 * suspended sale reads as it did. Safe to run again. It cannot be taken back once a quote carries a
 * state or an invoice names its quote, because that is the only place the state is kept; restore
 * from a backup instead.
 */
class QuoteLifecycle extends Migration
{
    private const COLUMNS = [
        'quote_status'    => 'TINYINT NULL DEFAULT NULL',
        'source_quote_id' => 'INT NULL DEFAULT NULL',
    ];

    public function up(): void
    {
        $sales = $this->db->prefixTable('sales');

        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->hasColumn($column)) {
                $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        if (! $this->hasIndex()) {
            $this->db->query("ALTER TABLE `{$sales}` ADD KEY `{$sales}_source_quote` (`source_quote_id`)");
        }
    }

    public function down(): void
    {
        if ($this->inUse()) {
            throw new RuntimeException('A quote carries a state or an invoice names the quote it came from; restore from a backup instead of rolling this migration back.');
        }

        $sales = $this->db->prefixTable('sales');

        if ($this->hasIndex()) {
            $this->db->query("ALTER TABLE `{$sales}` DROP KEY `{$sales}_source_quote`");
        }

        foreach (array_keys(self::COLUMNS) as $column) {
            if ($this->hasColumn($column)) {
                $this->db->query("ALTER TABLE `{$sales}` DROP COLUMN `{$column}`");
            }
        }
    }

    private function inUse(): bool
    {
        foreach (array_keys(self::COLUMNS) as $column) {
            if ($this->hasColumn($column) && $this->db->query('SELECT 1 FROM `' . $this->db->prefixTable('sales') . "` WHERE `{$column}` IS NOT NULL LIMIT 1")->getRow() !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasIndex(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable('sales'), $this->db->prefixTable('sales') . '_source_quote'],
        )->getRow() !== null;
    }

    private function hasColumn(string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('sales'), $column],
        )->getRow() !== null;
    }
}
