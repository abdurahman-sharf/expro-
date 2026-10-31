<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the retroactive posting needs: the batch table that the foundation created and nothing used
 * is given the range, the branch, the families, the cursor and the figures a batch carries, one row
 * for each document a batch looked at is added, the setting that keeps the approval off is written as
 * off, and the permission to preview, approve and cancel a batch is registered for the periods
 * screen and granted to nobody. Only columns, a table, a setting and a permission are added: no
 * journal, no document and no balance is touched. Safe to run again.
 */
class RetroactiveBackfill extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_periods';
    private const PERMISSION    = 'acc_backfill_manage';
    private const SETTING       = 'accounting_backfill_enabled';
    private const COLUMNS       = [
        'company_id'     => 'INT UNSIGNED NOT NULL DEFAULT 1',
        'branch_id'      => 'INT UNSIGNED NULL DEFAULT NULL',
        'name'           => "VARCHAR(120) NOT NULL DEFAULT ''",
        'end_date'       => 'DATE NULL DEFAULT NULL',
        'families'       => "VARCHAR(120) NOT NULL DEFAULT ''",
        'chunk_size'     => 'SMALLINT UNSIGNED NOT NULL DEFAULT 200',
        'cursor_family'  => 'VARCHAR(16) NULL DEFAULT NULL',
        'cursor_id'      => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'fingerprint'    => 'CHAR(64) NULL DEFAULT NULL',
        'document_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'ready_count'    => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'refused_count'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'journal_count'  => 'INT UNSIGNED NOT NULL DEFAULT 0',
        'total_debit'    => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000',
        'approved_count' => 'INT UNSIGNED NOT NULL DEFAULT 0',
    ];

    public function up(): void
    {
        $this->extendBatches();
        $this->createItems();

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID]);
        $this->db->table('app_config')->ignore(true)->insert(['key' => self::SETTING, 'value' => '0']);
    }

    public function down(): void
    {
        $batches = $this->db->prefixTable('accounting_backfill_batches');

        if ($this->hasColumn('accounting_backfill_batches', 'families') && $this->db->table('accounting_backfill_batches')->countAllResults() > 0) {
            throw new RuntimeException('A batch of the retroactive posting exists; restore from a backup instead of rolling this migration back.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('accounting_backfill_items') . '`');

        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($this->hasColumn('accounting_backfill_batches', $column)) {
                $this->db->query("ALTER TABLE `{$batches}` DROP COLUMN `{$column}`");
            }
        }

        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('app_config')->where('key', self::SETTING)->delete();
    }

    private function extendBatches(): void
    {
        $batches = $this->db->prefixTable('accounting_backfill_batches');

        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->hasColumn('accounting_backfill_batches', $column)) {
                $this->db->query("ALTER TABLE `{$batches}` ADD COLUMN `{$column}` {$definition}");
            }
        }
    }

    private function createItems(): void
    {
        $table   = $this->db->prefixTable('accounting_backfill_items');
        $batches = $this->db->prefixTable('accounting_backfill_batches');
        $options = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `item_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `batch_id` INT UNSIGNED NOT NULL,
                `family` VARCHAR(16) NOT NULL,
                `document_id` INT UNSIGNED NOT NULL,
                `document_date` DATE NOT NULL,
                `branch_id` INT UNSIGNED NULL,
                `outcome` VARCHAR(8) NOT NULL,
                `reason` VARCHAR(32) NULL,
                `detail` VARCHAR(255) NULL,
                `journal_count` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `total_debit` DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
                `digest` CHAR(64) NOT NULL,
                `payload` MEDIUMTEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`item_id`),
                UNIQUE KEY `{$table}_document` (`batch_id`, `family`, `document_id`),
                KEY `{$table}_outcome` (`batch_id`, `outcome`),
                CONSTRAINT `{$table}_fk_batch` FOREIGN KEY (`batch_id`) REFERENCES `{$batches}` (`batch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_outcome_check` CHECK (`outcome` IN ('ready', 'refused'))
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
