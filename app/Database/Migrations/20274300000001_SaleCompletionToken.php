<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The token the register sends with the sale it completes, unique, so that a second request for the same cart (a
 * double click, or a request sent again after the first was saved) gives back the sale the first made instead of
 * making a second sale and a second document. Every existing sale has none. Safe to run again; down() refuses once
 * a sale carries one.
 */
class SaleCompletionToken extends Migration
{
    public function up(): void
    {
        $sales = $this->db->prefixTable('sales');

        if (! $this->hasColumn()) {
            $this->db->query("ALTER TABLE `{$sales}` ADD COLUMN `completion_token` VARCHAR(64) NULL DEFAULT NULL, ADD UNIQUE KEY `{$sales}_completion_token` (`completion_token`)");
        }
    }

    public function down(): void
    {
        if (! $this->hasColumn()) {
            return;
        }

        if ($this->db->table('sales')->where('completion_token IS NOT NULL')->countAllResults() > 0) {
            throw new RuntimeException('Sales carry the token they were completed with; taking the migration down would let a repeated request make a second sale.');
        }

        $sales = $this->db->prefixTable('sales');

        $this->db->query("ALTER TABLE `{$sales}` DROP KEY `{$sales}_completion_token`, DROP COLUMN `completion_token`");
    }

    private function hasColumn(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable('sales'), 'completion_token'],
        )->getRow() !== null;
    }
}
