<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the integration settings that need no outside service keep: which manual payment methods the
 * register offers and under what name, the second-language name of a customer, a supplier, an item or a
 * category, the keys of the read-only API (only the hash of a secret is stored), the windows that count
 * the calls of a key, and the rules that make in-app notifications with the notifications themselves.
 * Two modules and three permissions are registered and nothing is granted; the permission to manage the
 * keys is sensitive. No journal, no document, no amount and no balance is touched and no existing table
 * changes. Safe to run again.
 */
class IntegrationSettingsTables extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULES       = ['integrations' => 135, 'notifications' => 136];
    private const PERMISSIONS   = [
        'integrations'            => 'integrations',
        'acc_integrations_manage' => 'integrations',
        'api_keys_manage'         => 'integrations',
        'notifications'           => 'notifications',
    ];
    private const TABLES = ['notification_reads', 'notifications', 'notification_rules', 'api_rate_windows', 'api_keys', 'entity_translations', 'payment_methods'];

    public function up(): void
    {
        $this->createTables();
        $this->registerModules();
    }

    public function down(): void
    {
        $this->refuseWhileEntered();

        $this->db->table('grants')->whereIn('permission_id', array_keys(self::PERMISSIONS))->delete();
        $this->db->table('permissions')->whereIn('permission_id', array_keys(self::PERMISSIONS))->delete();
        $this->db->table('modules')->whereIn('module_id', array_keys(self::MODULES))->delete();

        foreach (self::TABLES as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function refuseWhileEntered(): void
    {
        foreach (['api_keys', 'entity_translations', 'notification_rules', 'payment_methods'] as $table) {
            if ($this->hasTable($table) && $this->db->table($table)->countAllResults() > 0) {
                throw new RuntimeException("The table {$table} holds rows; taking the migration down would drop them.");
            }
        }
    }

    private function createTables(): void
    {
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $methods   = $this->db->prefixTable('payment_methods');
        $texts     = $this->db->prefixTable('entity_translations');
        $keys      = $this->db->prefixTable('api_keys');
        $windows   = $this->db->prefixTable('api_rate_windows');
        $rules     = $this->db->prefixTable('notification_rules');
        $notices   = $this->db->prefixTable('notifications');
        $reads     = $this->db->prefixTable('notification_reads');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$methods}` (
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `method_code` VARCHAR(20) NOT NULL,
                `enabled` TINYINT(1) NOT NULL DEFAULT 1,
                `display_name` VARCHAR(60) NULL,
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`company_id`, `method_code`),
                CONSTRAINT `{$methods}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$methods}_code` CHECK (`method_code` IN ('cash', 'debit', 'credit', 'check', 'bank_transfer', 'upi', 'wallet')),
                CONSTRAINT `{$methods}_enabled` CHECK (`enabled` IN (0, 1))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$texts}` (
                `translation_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `entity_kind` VARCHAR(12) NOT NULL,
                `entity_key` VARCHAR(190) NOT NULL,
                `language` VARCHAR(10) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `address_note` VARCHAR(255) NOT NULL DEFAULT '',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_by` INT(10) NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`translation_id`),
                UNIQUE KEY `{$texts}_entity` (`company_id`, `entity_kind`, `entity_key`, `language`),
                CONSTRAINT `{$texts}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$texts}_kind` CHECK (`entity_kind` IN ('customer', 'supplier', 'item', 'category'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$keys}` (
                `key_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `key_prefix` CHAR(8) NOT NULL,
                `secret_hash` CHAR(64) NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `scopes` VARCHAR(255) NOT NULL,
                `branch_id` INT UNSIGNED NULL,
                `rate_limit` INT UNSIGNED NOT NULL DEFAULT 60,
                `expires_at` DATETIME NULL,
                `revoked` TINYINT(1) NOT NULL DEFAULT 0,
                `revoked_at` DATETIME NULL,
                `revoked_by` INT(10) NULL,
                `last_used_at` DATETIME NULL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`key_id`),
                UNIQUE KEY `{$keys}_prefix` (`key_prefix`),
                KEY `{$keys}_company` (`company_id`, `revoked`),
                CONSTRAINT `{$keys}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$keys}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$keys}_rate` CHECK (`rate_limit` BETWEEN 1 AND 6000),
                CONSTRAINT `{$keys}_revoked` CHECK (`revoked` IN (0, 1))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$windows}` (
                `bucket` VARCHAR(64) NOT NULL,
                `window_start` INT UNSIGNED NOT NULL,
                `hits` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`bucket`, `window_start`),
                KEY `{$windows}_window` (`window_start`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$rules}` (
                `rule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `kind` VARCHAR(20) NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `threshold` INT UNSIGNED NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`rule_id`),
                UNIQUE KEY `{$rules}_name` (`company_id`, `name`),
                CONSTRAINT `{$rules}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$rules}_kind` CHECK (`kind` IN ('invoice_overdue', 'low_stock', 'cheque_due')),
                CONSTRAINT `{$rules}_active` CHECK (`active` IN (0, 1))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$notices}` (
                `notification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `rule_id` INT UNSIGNED NOT NULL,
                `subject_key` VARCHAR(64) NOT NULL,
                `open_key` VARCHAR(64) NULL,
                `branch_id` INT UNSIGNED NULL,
                `params` TEXT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `resolved_at` DATETIME NULL,
                PRIMARY KEY (`notification_id`),
                UNIQUE KEY `{$notices}_open` (`rule_id`, `open_key`),
                KEY `{$notices}_list` (`company_id`, `resolved_at`, `branch_id`),
                CONSTRAINT `{$notices}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$notices}_fk_rule` FOREIGN KEY (`rule_id`) REFERENCES `{$rules}` (`rule_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$notices}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$reads}` (
                `notification_id` INT UNSIGNED NOT NULL,
                `person_id` INT(10) NOT NULL,
                `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`notification_id`, `person_id`),
                KEY `{$reads}_person` (`person_id`),
                CONSTRAINT `{$reads}_fk_notification` FOREIGN KEY (`notification_id`) REFERENCES `{$notices}` (`notification_id`) ON DELETE RESTRICT
            ) {$options}",
        );
    }

    private function registerModules(): void
    {
        foreach (self::MODULES as $moduleId => $sort) {
            $this->db->table('modules')->ignore(true)->insert([
                'module_id'     => $moduleId,
                'name_lang_key' => 'module_' . $moduleId,
                'desc_lang_key' => 'module_' . $moduleId . '_desc',
                'sort'          => $sort,
            ]);
        }

        $this->db->table('permissions')->ignore(true)->insertBatch(array_map(
            static fn (string $permissionId, string $moduleId): array => ['permission_id' => $permissionId, 'module_id' => $moduleId],
            array_keys(self::PERMISSIONS),
            array_values(self::PERMISSIONS),
        ));
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
