<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What goes back of an advance that carries VAT under the Saudi rules, paid back by a refund or left when the
 * advance is cancelled, with the VAT that goes back with it (R-04 §9.5): its credit note, a document of its own,
 * refers to the prepayment invoice. The checks of the documents are widened to take that source; a server that
 * keeps no checks (MySQL 5.7) has nothing to widen. Safe to run again; down() refuses once something went back.
 */
class AdvanceVatReturns extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const WIDE          = "`source_type` IN ('sale', 'sales_debit_note', 'customer_advance', 'advance_return')";
    private const NARROW        = "`source_type` IN ('sale', 'sales_debit_note', 'customer_advance')";

    public function up(): void
    {
        $returns = $this->db->prefixTable('customer_advance_vat_returns');
        $options = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$returns}` (
                `return_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `advance_id` INT UNSIGNED NOT NULL,
                `refund_id` INT UNSIGNED NULL DEFAULT NULL,
                `gross` DECIMAL(15,2) NOT NULL,
                `taxable` DECIMAL(15,2) NOT NULL,
                `vat` DECIMAL(15,2) NOT NULL,
                `released` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL,
                PRIMARY KEY (`return_id`),
                KEY `{$returns}_advance` (`advance_id`, `released`),
                KEY `{$returns}_refund` (`refund_id`)
            ) {$options}",
        );

        $this->replaceCheck(self::WIDE);
    }

    public function down(): void
    {
        if ($this->hasTable() && $this->db->table('customer_advance_vat_returns')->countAllResults() > 0) {
            throw new RuntimeException('VAT of advances went back to customers; taking the migration down would lose what their journals and credit notes stand on.');
        }

        $this->replaceCheck(self::NARROW);
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('customer_advance_vat_returns') . '`');
    }

    private function replaceCheck(string $check): void
    {
        $table = $this->db->prefixTable('einvoice_documents');
        $name  = $table . '_source_kind';

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

    private function hasTable(): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable('customer_advance_vat_returns')],
        )->getRow() !== null;
    }
}
