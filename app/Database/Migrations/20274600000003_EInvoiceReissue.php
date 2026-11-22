<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * A document issued again in place of one ZATCA refused (D-24 a) is a source of its own: `reissue`, numbered by the
 * refused document, so that each refusal is issued again once at most and the sale keeps its first document. The check
 * of the kinds of source (kept by MariaDB and MySQL 8, read and ignored by MySQL 5.7) names it. Safe to run again;
 * down() refuses once a document was issued again.
 */
class EInvoiceReissue extends Migration
{
    private const WITH    = "`source_type` IN ('sale', 'sales_debit_note', 'customer_advance', 'advance_return', 'reissue')";
    private const WITHOUT = "`source_type` IN ('sale', 'sales_debit_note', 'customer_advance', 'advance_return')";

    public function up(): void
    {
        $this->replaceCheck(self::WITH);
    }

    public function down(): void
    {
        if ($this->db->table('einvoice_documents')->where('source_type', 'reissue')->countAllResults() > 0) {
            throw new RuntimeException('A document was issued again in place of a refused one; taking the migration down would break it.');
        }

        $this->replaceCheck(self::WITHOUT);
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

        try {
            $this->db->query("ALTER TABLE `{$table}` DROP CONSTRAINT `{$name}`");
        } catch (Throwable) {
            $this->db->query("ALTER TABLE `{$table}` DROP CHECK `{$name}`");
        }

        $this->db->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$name}` CHECK ({$check})");
    }
}
