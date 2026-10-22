<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Opening a sub-account looks for another account of the same name under the same main
 * account. With the parent alone in the index that is a scan of every sibling, which grows with
 * the number of customers; with the name in it, it is a lookup. Additive only, and safe to run again.
 */
class AccountingAccountNameIndex extends Migration
{
    private const INDEX = 'accounting_accounts_parent_name';

    public function up(): void
    {
        if (! $this->hasIndex()) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('accounting_accounts') . '` ADD INDEX `' . $this->db->prefixTable(self::INDEX) . '` (`parent_id`, `name`)',
            );
        }
    }

    public function down(): void
    {
        if ($this->hasIndex()) {
            $this->db->query(
                'ALTER TABLE `' . $this->db->prefixTable('accounting_accounts') . '` DROP INDEX `' . $this->db->prefixTable(self::INDEX) . '`',
            );
        }
    }

    private function hasIndex(): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable('accounting_accounts'), $this->db->prefixTable(self::INDEX)],
        )->getRow()->found > 0;
    }
}
