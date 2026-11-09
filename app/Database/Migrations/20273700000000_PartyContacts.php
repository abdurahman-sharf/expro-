<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the contact list of a customer or a supplier needs: one table of contacts, each belonging to a
 * party by its kind and its number, with a first and a last name, an e-mail address, a phone and a
 * mobile number, a note, a position and an order, and a flag that hides a contact the party no longer
 * keeps. A contact is a row of its own that no posting, no ledger and no report of the books reads. Only
 * a table is added: no existing table, document, journal or balance is touched. Safe to run again.
 */
class PartyContacts extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $table   = $this->db->prefixTable('party_contacts');
        $options = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `contact_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `party_kind` VARCHAR(10) NOT NULL,
                `party_id` INT(10) NOT NULL,
                `first_name` VARCHAR(100) NOT NULL,
                `last_name` VARCHAR(100) NOT NULL DEFAULT '',
                `email` VARCHAR(255) NOT NULL DEFAULT '',
                `phone` VARCHAR(30) NOT NULL DEFAULT '',
                `mobile` VARCHAR(30) NOT NULL DEFAULT '',
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `position` VARCHAR(100) NOT NULL DEFAULT '',
                `sort` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`contact_id`),
                KEY `{$table}_party` (`party_kind`, `party_id`, `deleted`, `sort`),
                CONSTRAINT `{$table}_kind` CHECK (`party_kind` IN ('customer', 'supplier')),
                CONSTRAINT `{$table}_deleted` CHECK (`deleted` IN (0, 1))
            ) {$options}",
        );
    }

    public function down(): void
    {
        if ($this->hasTable('party_contacts') && $this->db->table('party_contacts')->countAllResults() > 0) {
            throw new RuntimeException('The contact lists hold contacts; taking the migration down would drop them.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('party_contacts') . '`');
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
