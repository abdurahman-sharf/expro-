<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Who may put money into a treasury and who may take money out of it. Each treasury has a scope for
 * deposits and a scope for withdrawals: everybody, a list of employees, or the employees of a list
 * of branches. Every treasury that exists keeps the scope "everybody", so nothing that works today
 * is refused after it. Additive only, and safe to run again.
 */
class TreasuryScopes extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $treasuries = $this->db->prefixTable('treasuries');

        foreach (['deposit_scope' => 'description', 'withdraw_scope' => 'deposit_scope'] as $column => $after) {
            if (! $this->hasColumn('treasuries', $column)) {
                $this->db->query("ALTER TABLE `{$treasuries}` ADD COLUMN `{$column}` VARCHAR(8) NOT NULL DEFAULT 'all' AFTER `{$after}`");
            }
        }

        $this->createMembers();
    }

    public function down(): void
    {
        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('treasury_scope_members') . '`');

        $treasuries = $this->db->prefixTable('treasuries');

        foreach (['withdraw_scope', 'deposit_scope'] as $column) {
            if ($this->hasColumn('treasuries', $column)) {
                $this->db->query("ALTER TABLE `{$treasuries}` DROP COLUMN `{$column}`");
            }
        }
    }

    private function createMembers(): void
    {
        $table      = $this->db->prefixTable('treasury_scope_members');
        $treasuries = $this->db->prefixTable('treasuries');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `treasury_id` INT UNSIGNED NOT NULL,
                `direction` VARCHAR(8) NOT NULL,
                `member_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`treasury_id`, `direction`, `member_id`),
                KEY `{$table}_member` (`direction`, `member_id`),
                CONSTRAINT `{$table}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE CASCADE,
                CONSTRAINT `{$table}_direction` CHECK (`direction` IN ('deposit', 'withdraw'))
            ) {$options}",
        );
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
