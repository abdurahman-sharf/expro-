<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The supporting files of a journal. A row says whose file it is, what it was called, what it holds
 * and who put it there; the file itself lives outside the public web root under a name nobody
 * chose. Additive only: no journal and no amount is touched, and a journal that has a file cannot
 * be deleted by accident because the key restricts it. Safe to run again.
 */
class JournalAttachments extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $table    = $this->db->prefixTable('accounting_journal_attachments');
        $journals = $this->db->prefixTable('accounting_journals');
        $options  = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `attachment_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `journal_id` INT UNSIGNED NOT NULL,
                `original_name` VARCHAR(255) NOT NULL,
                `stored_name` CHAR(32) NOT NULL,
                `mime_type` VARCHAR(100) NOT NULL,
                `size_bytes` INT UNSIGNED NOT NULL,
                `sha256` CHAR(64) NOT NULL,
                `uploaded_by` INT NULL,
                `uploaded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`attachment_id`),
                UNIQUE KEY `{$table}_stored` (`stored_name`),
                KEY `{$table}_journal` (`journal_id`),
                CONSTRAINT `{$table}_fk_journal` FOREIGN KEY (`journal_id`) REFERENCES `{$journals}` (`journal_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_size` CHECK (`size_bytes` > 0)
            ) {$options}",
        );
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('accounting_journal_attachments') . '`');
    }
}
