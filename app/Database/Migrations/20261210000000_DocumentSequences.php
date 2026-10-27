<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What a numbering of documents needs: one sequence per company and kind of document (its prefix,
 * its digits and the number it gives next), and the register of the numbers given. Nothing that
 * exists is touched: no document is renumbered, every sequence starts switched off so each
 * document keeps showing what it shows today, and the counters of invoices and quotes in the
 * settings and the series of manual journals stay as they are. Safe to run again.
 */
class DocumentSequences extends Migration
{
    public function up(): void
    {
        $companies = $this->db->prefixTable('companies');
        $sequences = $this->db->prefixTable('document_sequences');
        $numbers   = $this->db->prefixTable('document_numbers');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$sequences}` (
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `doc_type` VARCHAR(24) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 0,
                `prefix` VARCHAR(16) NOT NULL DEFAULT '',
                `padding` TINYINT UNSIGNED NOT NULL DEFAULT 0,
                `next_number` INT UNSIGNED NOT NULL DEFAULT 1,
                `prevent_duplicates` TINYINT(1) NOT NULL DEFAULT 1,
                `updated_by` INT(10) NULL DEFAULT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`company_id`, `doc_type`),
                CONSTRAINT `{$sequences}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$sequences}_padding` CHECK (`padding` <= 10),
                CONSTRAINT `{$sequences}_next` CHECK (`next_number` >= 1)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$numbers}` (
                `number_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `doc_type` VARCHAR(24) NOT NULL,
                `source_table` VARCHAR(32) NOT NULL,
                `source_id` INT(10) NOT NULL,
                `sequence_number` INT UNSIGNED NOT NULL,
                `number` VARCHAR(40) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`number_id`),
                UNIQUE KEY `{$numbers}_source` (`doc_type`, `source_table`, `source_id`),
                UNIQUE KEY `{$numbers}_number` (`company_id`, `doc_type`, `number`),
                KEY `{$numbers}_sequence` (`company_id`, `doc_type`, `sequence_number`),
                CONSTRAINT `{$numbers}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('document_numbers') . '`');
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('document_sequences') . '`');
    }
}
