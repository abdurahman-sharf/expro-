<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Lets the electronic documents hold the prepayment invoice an advance of a customer is issued on under the Saudi
 * rules (type 386): its source, the advance, and its kind, which refers to no original document. Only the checks of
 * the table are widened; no document is touched. A server that keeps no checks (MySQL 5.7) has nothing to change.
 * Safe to run again.
 */
class EInvoicePrepayments extends Migration
{
    private const WIDE = [
        'source_kind'   => "`source_type` IN ('sale', 'sales_debit_note', 'customer_advance')",
        'kind'          => "`doc_kind` IN ('invoice', 'credit_note', 'debit_note', 'prepayment')",
        'note_original' => "`doc_kind` IN ('invoice', 'prepayment') OR `original_document_id` IS NOT NULL",
    ];
    private const NARROW = [
        'source_kind'   => "`source_type` IN ('sale', 'sales_debit_note')",
        'kind'          => "`doc_kind` IN ('invoice', 'credit_note', 'debit_note')",
        'note_original' => "`doc_kind` = 'invoice' OR `original_document_id` IS NOT NULL",
    ];

    public function up(): void
    {
        $this->replace(self::WIDE);
    }

    public function down(): void
    {
        $this->replace(self::NARROW);
    }

    /**
     * @param array<string, string> $checks
     */
    private function replace(array $checks): void
    {
        $table = $this->db->prefixTable('einvoice_documents');

        foreach ($checks as $suffix => $check) {
            $name = $table . '_' . $suffix;

            if (! $this->hasCheck($table, $name)) {
                continue;
            }

            $this->db->query("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
            $this->db->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$check})");
        }
    }

    private function hasCheck(string $table, string $name): bool
    {
        return $this->db->query(
            "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table, $name],
        )->getRow() !== null;
    }
}
