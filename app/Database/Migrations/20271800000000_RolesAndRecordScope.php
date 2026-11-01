<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Roles: a named set of the permission ids the grants table already uses, a link from an employee
 * to the roles they hold, the option of seeing only the records one created (for a role and for an
 * employee), and the default stock location of an employee. Only tables are created: no grant is
 * copied, no employee is given a role and every employee keeps the scope "all", so nothing that
 * works today changes. Safe to run again.
 */
class RolesAndRecordScope extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';

    public function up(): void
    {
        $this->createRoles();
        $this->createRolePermissions();
        $this->createEmployeeRoles();
        $this->createEmployeeRecordScopes();
        $this->createEmployeeDefaultLocations();
    }

    public function down(): void
    {
        foreach (['employee_default_locations', 'employee_record_scopes', 'employee_roles', 'role_permissions', 'roles'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }
    }

    private function createRoles(): void
    {
        $table   = $this->db->prefixTable('roles');
        $options = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `role_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL,
                `description` VARCHAR(255) NULL DEFAULT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `record_scope` VARCHAR(8) NOT NULL DEFAULT 'all',
                `created_by` INT(10) NULL DEFAULT NULL,
                `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`role_id`),
                UNIQUE KEY `{$table}_name` (`name`),
                CONSTRAINT `{$table}_record_scope` CHECK (`record_scope` IN ('all', 'own'))
            ) {$options}",
        );
    }

    private function createRolePermissions(): void
    {
        $table       = $this->db->prefixTable('role_permissions');
        $roles       = $this->db->prefixTable('roles');
        $permissions = $this->db->prefixTable('permissions');
        $options     = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `role_id` INT UNSIGNED NOT NULL,
                `permission_id` VARCHAR(255) NOT NULL,
                PRIMARY KEY (`role_id`, `permission_id`),
                KEY `{$table}_permission` (`permission_id`),
                CONSTRAINT `{$table}_fk_role` FOREIGN KEY (`role_id`) REFERENCES `{$roles}` (`role_id`) ON DELETE CASCADE,
                CONSTRAINT `{$table}_fk_permission` FOREIGN KEY (`permission_id`) REFERENCES `{$permissions}` (`permission_id`) ON DELETE CASCADE
            ) {$options}",
        );
    }

    private function createEmployeeRoles(): void
    {
        $table     = $this->db->prefixTable('employee_roles');
        $roles     = $this->db->prefixTable('roles');
        $employees = $this->db->prefixTable('employees');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `person_id` INT(10) NOT NULL,
                `role_id` INT UNSIGNED NOT NULL,
                PRIMARY KEY (`person_id`, `role_id`),
                KEY `{$table}_role` (`role_id`),
                CONSTRAINT `{$table}_fk_employee` FOREIGN KEY (`person_id`) REFERENCES `{$employees}` (`person_id`) ON DELETE CASCADE,
                CONSTRAINT `{$table}_fk_role` FOREIGN KEY (`role_id`) REFERENCES `{$roles}` (`role_id`) ON DELETE CASCADE
            ) {$options}",
        );
    }

    private function createEmployeeRecordScopes(): void
    {
        $table     = $this->db->prefixTable('employee_record_scopes');
        $employees = $this->db->prefixTable('employees');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `person_id` INT(10) NOT NULL,
                `record_scope` VARCHAR(8) NOT NULL DEFAULT 'all',
                PRIMARY KEY (`person_id`),
                CONSTRAINT `{$table}_fk_employee` FOREIGN KEY (`person_id`) REFERENCES `{$employees}` (`person_id`) ON DELETE CASCADE,
                CONSTRAINT `{$table}_record_scope` CHECK (`record_scope` IN ('all', 'own'))
            ) {$options}",
        );
    }

    private function createEmployeeDefaultLocations(): void
    {
        $table     = $this->db->prefixTable('employee_default_locations');
        $employees = $this->db->prefixTable('employees');
        $locations = $this->db->prefixTable('stock_locations');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `person_id` INT(10) NOT NULL,
                `location_id` INT(11) NOT NULL,
                PRIMARY KEY (`person_id`),
                KEY `{$table}_location` (`location_id`),
                CONSTRAINT `{$table}_fk_employee` FOREIGN KEY (`person_id`) REFERENCES `{$employees}` (`person_id`) ON DELETE CASCADE,
                CONSTRAINT `{$table}_fk_location` FOREIGN KEY (`location_id`) REFERENCES `{$locations}` (`location_id`) ON DELETE CASCADE
            ) {$options}",
        );
    }
}
