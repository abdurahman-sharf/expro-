<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The drafts of the VAT returns, one for each period of the company, with the boxes as they were worked out and
 * typed, and the approval that freezes them once the books of the period are closed (T13). Nothing is sent. Safe to
 * run again; down() refuses once a return was approved.
 */
class VatReturns extends Migration
{
    public function up(): void
    {
        $table = $this->db->prefixTable('vat_returns');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `return_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `period_start` DATE NOT NULL,
                `period_end` DATE NOT NULL,
                `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
                `boxes` TEXT NOT NULL,
                `estimated` DECIMAL(15,2) NOT NULL DEFAULT 0,
                `created_by` INT(10) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `approved_by` INT(10) NULL DEFAULT NULL,
                `approved_at` DATETIME NULL DEFAULT NULL,
                PRIMARY KEY (`return_id`),
                UNIQUE KEY `{$table}_period` (`company_id`, `period_start`, `period_end`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );
    }

    public function down(): void
    {
        $exists = $this->db->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->db->prefixTable('vat_returns')])->getRow() !== null;

        if ($exists && $this->db->table('vat_returns')->where('status', 'approved')->countAllResults() > 0) {
            throw new RuntimeException('A VAT return was approved; taking the migration down would lose it.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('vat_returns') . '`');
    }
}
