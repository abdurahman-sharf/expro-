<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;
use Throwable;

/**
 * Two more kinds of notification rule, for the integration with ZATCA: a certificate of a unit that runs out soon,
 * and a simplified document still not reported half a day after it was queued. The kind takes up to 32 characters,
 * and the check of the kinds (kept by MariaDB and MySQL 8, read and ignored by MySQL 5.7) names them. Safe to run
 * again; down() refuses while a rule of a new kind exists.
 */
class EInvoiceNotificationKinds extends Migration
{
    private const BEFORE = ['invoice_overdue', 'low_stock', 'cheque_due'];
    private const AFTER  = ['invoice_overdue', 'low_stock', 'cheque_due', 'einvoice_certificate_expiry', 'einvoice_reporting_late'];

    public function up(): void
    {
        $this->rebuild(32, self::AFTER);
    }

    public function down(): void
    {
        if ($this->db->table('notification_rules')->whereNotIn('kind', self::BEFORE)->countAllResults() > 0) {
            throw new RuntimeException('Rules of the kinds of ZATCA exist; taking the migration down would break them.');
        }

        $this->rebuild(20, self::BEFORE);
    }

    /**
     * @param list<string> $kinds
     */
    private function rebuild(int $length, array $kinds): void
    {
        $table      = $this->db->prefixTable('notification_rules');
        $constraint = $table . '_kind';

        if ($this->hasConstraint($table, $constraint)) {
            try {
                $this->db->query("ALTER TABLE `{$table}` DROP CONSTRAINT `{$constraint}`");
            } catch (Throwable) {
                $this->db->query("ALTER TABLE `{$table}` DROP CHECK `{$constraint}`");
            }
        }

        $this->db->query("ALTER TABLE `{$table}` MODIFY `kind` VARCHAR({$length}) NOT NULL");
        $this->db->query("ALTER TABLE `{$table}` ADD CONSTRAINT `{$constraint}` CHECK (`kind` IN ('" . implode("', '", $kinds) . "'))");
    }

    private function hasConstraint(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        )->getRow() !== null;
    }
}
