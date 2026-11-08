<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * What the extra fields of products and invoices need: the definition of a field (its name, its type,
 * the options of a list, whether it is required and whether it prints) and the value a product or an
 * invoice holds for a field. The screen that manages the definitions is registered as a module with its
 * permission, and nothing is granted. Only two tables, a module and a permission are added: no journal,
 * no document, no amount and no balance is touched, and a value is a row of its own that no posting
 * reads. Safe to run again.
 */
class CustomFieldsTables extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE        = 'custom_fields';
    private const PERMISSION    = 'acc_custom_fields_manage';

    public function up(): void
    {
        $this->createTables();
        $this->registerModule();
    }

    public function down(): void
    {
        $this->refuseWhileEntered();

        $permissionIds = [self::MODULE, self::PERMISSION];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE)->delete();

        foreach (['custom_field_values', 'custom_fields'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function refuseWhileEntered(): void
    {
        if ($this->hasTable('custom_fields') && $this->db->table('custom_fields')->countAllResults() > 0) {
            throw new RuntimeException('The extra fields hold definitions; taking the migration down would drop them and their values.');
        }
    }

    private function createTables(): void
    {
        $companies = $this->db->prefixTable('companies');
        $fields    = $this->db->prefixTable('custom_fields');
        $values    = $this->db->prefixTable('custom_field_values');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$fields}` (
                `field_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `entity` VARCHAR(8) NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `field_type` VARCHAR(12) NOT NULL,
                `options` TEXT NULL,
                `required` TINYINT(1) NOT NULL DEFAULT 0,
                `printable` TINYINT(1) NOT NULL DEFAULT 0,
                `sort_order` INT NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`field_id`),
                UNIQUE KEY `{$fields}_name` (`company_id`, `entity`, `name`),
                KEY `{$fields}_entity` (`company_id`, `entity`, `active`, `sort_order`),
                CONSTRAINT `{$fields}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$fields}_entity_kind` CHECK (`entity` IN ('item', 'sale')),
                CONSTRAINT `{$fields}_type` CHECK (`field_type` IN ('text', 'textarea', 'number', 'date', 'select', 'checkbox', 'url', 'email', 'phone')),
                CONSTRAINT `{$fields}_flags` CHECK (`required` IN (0, 1) AND `printable` IN (0, 1) AND `active` IN (0, 1))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$values}` (
                `field_id` INT UNSIGNED NOT NULL,
                `entity_id` INT(10) NOT NULL,
                `field_value` TEXT NOT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`field_id`, `entity_id`),
                KEY `{$values}_entity` (`entity_id`),
                CONSTRAINT `{$values}_fk_field` FOREIGN KEY (`field_id`) REFERENCES `{$fields}` (`field_id`) ON DELETE RESTRICT
            ) {$options}",
        );
    }

    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE,
            'name_lang_key' => 'module_' . self::MODULE,
            'desc_lang_key' => 'module_' . self::MODULE . '_desc',
            'sort'          => 134,
        ]);

        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => self::MODULE, 'module_id' => self::MODULE],
            ['permission_id' => self::PERMISSION, 'module_id' => self::MODULE],
        ]);
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
