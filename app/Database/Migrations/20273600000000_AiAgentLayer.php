<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the AI agent layer needs: the proposals table that the foundation created and nothing used is
 * given the title, the rationale, the proposer, the date and the state the proposal was made against,
 * a key that keeps one live proposal for one thing, and the rules that a status is one of five and that
 * a proposal is approved exactly when it points to a journal; a table for the findings of the anomaly
 * scan is created; the module and the two permissions are registered and granted to nobody; and the
 * master switch is written as off. Only columns, a table, keys, a setting and permissions are added: no
 * journal and no balance is touched. Safe to run again.
 */
class AiAgentLayer extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_ai';
    private const MODULE_SORT   = 138;
    private const PERMISSIONS   = ['accounting_ai', 'acc_ai_approve'];
    private const SETTING       = 'accounting_ai_enabled';
    private const COLUMNS       = [
        'title'         => "VARCHAR(255) NOT NULL DEFAULT ''",
        'rationale'     => 'TEXT NULL',
        'template_args' => 'TEXT NULL',
        'proposer'      => "VARCHAR(64) NOT NULL DEFAULT ''",
        'proposal_date' => 'DATE NULL DEFAULT NULL',
        'fingerprint'   => 'CHAR(64) NULL DEFAULT NULL',
        'dedupe_key'    => 'CHAR(64) NULL DEFAULT NULL',
        'expires_at'    => 'DATETIME NULL DEFAULT NULL',
        'reject_reason' => 'VARCHAR(255) NULL DEFAULT NULL',
        'stale_reason'  => 'VARCHAR(64) NULL DEFAULT NULL',
    ];

    public function up(): void
    {
        $this->extendProposals();
        $this->createFindings();

        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::MODULE_SORT,
        ]);
        $this->db->table('permissions')->ignore(true)->insertBatch(array_map(
            static fn (string $permission): array => ['permission_id' => $permission, 'module_id' => self::MODULE_ID],
            self::PERMISSIONS,
        ));
        $this->db->table('app_config')->ignore(true)->insert(['key' => self::SETTING, 'value' => '0']);
    }

    public function down(): void
    {
        $proposals = $this->db->prefixTable('accounting_ai_proposals');

        if (
            ($this->hasColumn('accounting_ai_proposals', 'title') && $this->db->table('accounting_ai_proposals')->countAllResults() > 0)
            || ($this->db->tableExists($this->db->prefixTable('accounting_ai_findings'), false) && $this->db->table('accounting_ai_findings')->countAllResults() > 0)
        ) {
            throw new RuntimeException('A proposal or a finding of the AI agent layer exists; restore from a backup instead of rolling this migration back.');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('accounting_ai_findings') . '`');

        foreach (['accounting_ai_proposals_status_check', 'accounting_ai_proposals_approved_check'] as $constraint) {
            if ($this->hasCheck('accounting_ai_proposals', $constraint)) {
                $this->db->query("ALTER TABLE `{$proposals}` DROP CONSTRAINT `" . $this->db->prefixTable($constraint) . '`');
            }
        }

        foreach (['accounting_ai_proposals_dedupe', 'accounting_ai_proposals_journal_once'] as $index) {
            if ($this->hasIndex('accounting_ai_proposals', $index)) {
                $this->db->query("ALTER TABLE `{$proposals}` DROP KEY `" . $this->db->prefixTable($index) . '`');
            }
        }

        foreach (array_reverse(array_keys(self::COLUMNS)) as $column) {
            if ($this->hasColumn('accounting_ai_proposals', $column)) {
                $this->db->query("ALTER TABLE `{$proposals}` DROP COLUMN `{$column}`");
            }
        }

        $this->db->query("ALTER TABLE `{$proposals}` ALTER COLUMN `status` SET DEFAULT 'pending'");

        $this->db->table('grants')->whereIn('permission_id', [self::MODULE_ID, ...self::PERMISSIONS])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE_ID, ...self::PERMISSIONS])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();
        $this->db->table('app_config')->where('key', self::SETTING)->delete();
    }

    private function extendProposals(): void
    {
        $proposals = $this->db->prefixTable('accounting_ai_proposals');

        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->hasColumn('accounting_ai_proposals', $column)) {
                $this->db->query("ALTER TABLE `{$proposals}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        $this->db->query("ALTER TABLE `{$proposals}` ALTER COLUMN `status` SET DEFAULT 'proposed'");
        $this->db->query("UPDATE `{$proposals}` SET `status` = 'proposed' WHERE `status` = 'pending'");

        if (! $this->hasIndex('accounting_ai_proposals', 'accounting_ai_proposals_dedupe')) {
            $this->db->query("ALTER TABLE `{$proposals}` ADD UNIQUE KEY `{$proposals}_dedupe` (`dedupe_key`)");
        }

        if (! $this->hasIndex('accounting_ai_proposals', 'accounting_ai_proposals_journal_once')) {
            $this->db->query("ALTER TABLE `{$proposals}` ADD UNIQUE KEY `{$proposals}_journal_once` (`journal_id`)");
        }

        if (! $this->hasCheck('accounting_ai_proposals', 'accounting_ai_proposals_status_check')) {
            $this->db->query("ALTER TABLE `{$proposals}` ADD CONSTRAINT `{$proposals}_status_check` CHECK (`status` IN ('proposed', 'approved', 'rejected', 'expired', 'superseded'))");
        }

        if (! $this->hasCheck('accounting_ai_proposals', 'accounting_ai_proposals_approved_check')) {
            $this->db->query("ALTER TABLE `{$proposals}` ADD CONSTRAINT `{$proposals}_approved_check` CHECK ((`status` = 'approved') = (`journal_id` IS NOT NULL))");
        }
    }

    private function createFindings(): void
    {
        $table    = $this->db->prefixTable('accounting_ai_findings');
        $branches = $this->db->prefixTable('branches');
        $options  = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `finding_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `rule_code` VARCHAR(32) NOT NULL,
                `severity` VARCHAR(8) NOT NULL,
                `finding_key` CHAR(64) NOT NULL,
                `branch_id` INT UNSIGNED NULL,
                `subject_type` VARCHAR(16) NOT NULL,
                `subject_id` INT UNSIGNED NULL,
                `occurred_on` DATE NULL,
                `template_args` TEXT NULL,
                `evidence` MEDIUMTEXT NOT NULL,
                `status` VARCHAR(12) NOT NULL DEFAULT 'open',
                `decided_by` INT NULL,
                `decided_at` DATETIME NULL,
                `decision_reason` VARCHAR(255) NULL,
                `detected_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`finding_id`),
                UNIQUE KEY `{$table}_key` (`finding_key`),
                KEY `{$table}_status` (`status`, `rule_code`),
                KEY `{$table}_branch` (`branch_id`),
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_status_check` CHECK (`status` IN ('open', 'dismissed', 'confirmed')),
                CONSTRAINT `{$table}_severity_check` CHECK (`severity` IN ('low', 'medium', 'high')),
                CONSTRAINT `{$table}_decision_check` CHECK ((`status` = 'open') = (`decided_by` IS NULL AND `decided_at` IS NULL AND `decision_reason` IS NULL))
            ) {$options}",
        );
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($index)],
        )->getRow() !== null;
    }

    private function hasCheck(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = \'CHECK\'',
            [$this->db->prefixTable($table), $this->db->prefixTable($constraint)],
        )->getRow() !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
