<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The date typed on an invoice, the payment terms in days and the due date they give, on sales and
 * on purchases. Only nullable columns are added and nothing is read, written or recomputed: a
 * document that exists has none of them, so its date stays the day it was saved and its due date
 * stays that same day, and every aging and balance of the books reads as it did. Safe to run
 * again. It cannot be taken back once a document carries a typed date, because the journal of that
 * document is dated by it; restore from a backup instead.
 */
class InvoiceDatesAndPaymentTerms extends Migration
{
    private const TABLES = ['sales', 'receivings'];

    private const COLUMNS = [
        'invoice_date'       => 'DATE NULL DEFAULT NULL',
        'payment_terms_days' => 'SMALLINT UNSIGNED NULL DEFAULT NULL',
        'due_date'           => 'DATE NULL DEFAULT NULL',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            $prefixed = $this->db->prefixTable($table);

            foreach (self::COLUMNS as $column => $definition) {
                if (! $this->hasColumn($table, $column)) {
                    $this->db->query("ALTER TABLE `{$prefixed}` ADD COLUMN `{$column}` {$definition}");
                }
            }
        }
    }

    public function down(): void
    {
        if ($this->inUse()) {
            throw new RuntimeException('A document carries a typed invoice date, which dates its journal; restore from a backup instead of rolling this migration back.');
        }

        foreach (self::TABLES as $table) {
            $prefixed = $this->db->prefixTable($table);

            foreach (array_keys(self::COLUMNS) as $column) {
                if ($this->hasColumn($table, $column)) {
                    $this->db->query("ALTER TABLE `{$prefixed}` DROP COLUMN `{$column}`");
                }
            }
        }
    }

    private function inUse(): bool
    {
        foreach (self::TABLES as $table) {
            if ($this->hasColumn($table, 'invoice_date') && $this->db->query('SELECT 1 FROM `' . $this->db->prefixTable($table) . '` WHERE `invoice_date` IS NOT NULL LIMIT 1')->getRow() !== null) {
                return true;
            }
        }

        return false;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
