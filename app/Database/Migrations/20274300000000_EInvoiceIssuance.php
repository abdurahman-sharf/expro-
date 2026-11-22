<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What issuing a document with every completed sale under the Saudi rules needs:
 *
 * - The state of a document. Every document issued so far is `issued`.
 * - The printed number of a sale made before the mode, which a credit note refers to when that sale has no
 *   document, and the sale itself.
 * - The record of a document that could not be issued. It is kept apart from the sale, since the sale was
 *   undone and its number may come back.
 *
 * The rule that a note refers to an original is widened to take the printed number. A server that keeps no
 * checks (MySQL 5.7) has nothing to widen, and the code holds the rule there. No document is touched. Safe
 * to run again; down() refuses once a note refers to a printed number.
 */
class EInvoiceIssuance extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const WIDE          = "`doc_kind` IN ('invoice', 'prepayment') OR `original_document_id` IS NOT NULL OR `original_reference` IS NOT NULL";
    private const NARROW        = "`doc_kind` IN ('invoice', 'prepayment') OR `original_document_id` IS NOT NULL";

    public function up(): void
    {
        $documents = $this->db->prefixTable('einvoice_documents');
        $sales     = $this->db->prefixTable('sales');

        if (! $this->hasColumn('einvoice_documents', 'status')) {
            $this->db->query("ALTER TABLE `{$documents}` ADD COLUMN `status` VARCHAR(24) NOT NULL DEFAULT 'issued' AFTER `profile`");
        }

        if (! $this->hasColumn('einvoice_documents', 'original_reference')) {
            $this->db->query("ALTER TABLE `{$documents}` ADD COLUMN `original_reference` VARCHAR(127) NULL DEFAULT NULL AFTER `original_document_id`");
        }

        if (! $this->hasColumn('einvoice_documents', 'original_sale_id')) {
            $this->db->query("ALTER TABLE `{$documents}` ADD COLUMN `original_sale_id` INT(10) NULL DEFAULT NULL AFTER `original_reference`, ADD KEY `{$documents}_original_sale` (`original_sale_id`), ADD CONSTRAINT `{$documents}_fk_original_sale` FOREIGN KEY (`original_sale_id`) REFERENCES `{$sales}` (`sale_id`)");
        }

        $this->replaceCheck(self::WIDE);

        $failures = $this->db->prefixTable('einvoice_failures');
        $options  = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$failures}` (
                `failure_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `branch_id` INT UNSIGNED NULL DEFAULT NULL,
                `employee_id` INT(10) NULL DEFAULT NULL,
                `source` VARCHAR(20) NOT NULL,
                `sale_type` TINYINT NULL DEFAULT NULL,
                `error` VARCHAR(64) NOT NULL,
                `message` VARCHAR(500) NOT NULL DEFAULT '',
                `fingerprint` CHAR(64) NULL DEFAULT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`failure_id`),
                KEY `{$failures}_company` (`company_id`, `created_at`)
            ) {$options}",
        );
    }

    public function down(): void
    {
        $documents = $this->db->prefixTable('einvoice_documents');

        if ($this->hasColumn('einvoice_documents', 'original_reference') && $this->db->table('einvoice_documents')->where('original_reference IS NOT NULL')->countAllResults() > 0) {
            throw new RuntimeException('A note refers to the printed number of a sale made before the Saudi rules; taking the migration down would leave it without its original.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('einvoice_failures') . '`');

        $this->replaceCheck(self::NARROW);

        if ($this->hasColumn('einvoice_documents', 'original_sale_id')) {
            $this->db->query("ALTER TABLE `{$documents}` DROP FOREIGN KEY `{$documents}_fk_original_sale`");
            $this->db->query("ALTER TABLE `{$documents}` DROP KEY `{$documents}_original_sale`, DROP COLUMN `original_sale_id`");
        }

        if ($this->hasColumn('einvoice_documents', 'original_reference')) {
            $this->db->query("ALTER TABLE `{$documents}` DROP COLUMN `original_reference`");
        }

        if ($this->hasColumn('einvoice_documents', 'status')) {
            $this->db->query("ALTER TABLE `{$documents}` DROP COLUMN `status`");
        }
    }

    private function replaceCheck(string $check): void
    {
        $table = $this->db->prefixTable('einvoice_documents');
        $name  = $table . '_note_original';

        $kept = $this->db->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        )->getRow() !== null;

        if (! $kept) {
            return;
        }

        $this->db->query("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        $this->db->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$check})");
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
