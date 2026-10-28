<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A payment made against a purchase afterwards has the date the person who made it entered, next to
 * the time it was saved, which it has always kept. Every payment that exists has none, so each of
 * them goes on showing, and being posted at, the day it was saved: no journal, no payment and no
 * balance is touched. Only a nullable column is added. Safe to run again.
 */
class SupplierPaymentDate extends Migration
{
    public function up(): void
    {
        $payments = $this->db->prefixTable('receivings_payments');

        if (! $this->hasColumn('receivings_payments', 'payment_date')) {
            $this->db->query("ALTER TABLE `{$payments}` ADD COLUMN `payment_date` DATE NULL DEFAULT NULL AFTER `payment_time`");
        }
    }

    public function down(): void
    {
        $payments = $this->db->prefixTable('receivings_payments');

        if ($this->hasColumn('receivings_payments', 'payment_date')) {
            $this->db->query("ALTER TABLE `{$payments}` DROP COLUMN `payment_date`");
        }
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
