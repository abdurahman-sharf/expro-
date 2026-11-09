<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the read-only portal of the customers needs: the portal accounts (one per customer, made by an
 * employee, with a hashed password and the count of failed attempts kept apart), the ledger of failed
 * attempts that locks a user name or an address for a while, the permission to manage the portal, which
 * is registered under the customers module and granted to nobody, and the setting that switches the portal
 * on, which is added off. Only two tables, one permission and one setting are added: no journal, no
 * document, no amount and no balance is touched, and a portal account is a row of its own that no posting
 * reads. Safe to run again.
 */
class CustomerPortal extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const PERMISSION    = 'customers_portal_manage';
    private const SETTING       = 'portal_enabled';

    public function up(): void
    {
        $this->createTables();

        $this->db->table('permissions')->ignore(true)->insert(['permission_id' => self::PERMISSION, 'module_id' => 'customers']);
        $this->db->table('app_config')->ignore(true)->insert(['key' => self::SETTING, 'value' => '0']);
    }

    public function down(): void
    {
        if ($this->hasTable('portal_accounts') && $this->db->table('portal_accounts')->countAllResults() > 0) {
            throw new RuntimeException('The portal holds accounts of customers; taking the migration down would drop them.');
        }

        $this->db->table('grants')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('permissions')->where('permission_id', self::PERMISSION)->delete();
        $this->db->table('app_config')->where('key', self::SETTING)->delete();

        foreach (['portal_login_failures', 'portal_accounts'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function createTables(): void
    {
        $accounts  = $this->db->prefixTable('portal_accounts');
        $failures  = $this->db->prefixTable('portal_login_failures');
        $customers = $this->db->prefixTable('customers');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$accounts}` (
                `account_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `customer_id` INT(10) NOT NULL,
                `username` VARCHAR(50) NOT NULL,
                `password_hash` VARCHAR(255) NOT NULL,
                `must_change` TINYINT(1) NOT NULL DEFAULT 1,
                `temporary_expires_at` DATETIME NULL,
                `credential_version` INT UNSIGNED NOT NULL DEFAULT 1,
                `status` VARCHAR(8) NOT NULL DEFAULT 'active',
                `last_login_at` DATETIME NULL,
                `password_changed_at` DATETIME NULL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`account_id`),
                UNIQUE KEY `{$accounts}_customer` (`customer_id`),
                UNIQUE KEY `{$accounts}_username` (`username`),
                CONSTRAINT `{$accounts}_fk_customer` FOREIGN KEY (`customer_id`) REFERENCES `{$customers}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$accounts}_status` CHECK (`status` IN ('active', 'disabled')),
                CONSTRAINT `{$accounts}_must_change` CHECK (`must_change` IN (0, 1))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$failures}` (
                `failure_key` CHAR(64) NOT NULL,
                `failures` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `lockouts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                `locked_until` DATETIME NULL,
                `last_failure_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`failure_key`)
            ) {$options}",
        );
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
