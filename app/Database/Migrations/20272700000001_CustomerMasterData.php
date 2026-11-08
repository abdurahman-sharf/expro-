<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;
use RuntimeException;

/**
 * The fields of a customer that Daftra shows and filters by, and the country of a supplier: a
 * classification (a small list of its own), the manual status of the follow-up (five values that
 * nothing posts from), the employee the customer is assigned to, an area, a country code, the type
 * (individual or commercial), the government flag and the billing method. Every column may be empty or
 * holds the value that changes nothing (no classification, no status, nobody assigned, print), so a
 * customer that exists reads as it did and no document or journal is touched. The creation date is the
 * `date` the customer already carries. Safe to run again; down() refuses once a value is stored.
 */
class CustomerMasterData extends Migration
{
    private const TABLE_OPTIONS    = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const STATUSES         = "'requires_follow_up', 'interested', 'served', 'under_review', 'archived'";
    private const CUSTOMER_COLUMNS = [
        'classification_id'    => 'INT UNSIGNED NULL DEFAULT NULL',
        'manual_status'        => 'VARCHAR(20) NULL DEFAULT NULL',
        'assigned_employee_id' => 'INT(10) NULL DEFAULT NULL',
        'area'                 => 'VARCHAR(100) NULL DEFAULT NULL',
        'country_code'         => 'CHAR(2) NULL DEFAULT NULL',
        'customer_type'        => 'VARCHAR(10) NULL DEFAULT NULL',
        'is_government_entity' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'billing_method'       => "VARCHAR(5) NOT NULL DEFAULT 'print'",
    ];

    public function up(): void
    {
        $this->createClassifications();
        $this->addCustomerColumns();
        $this->addSupplierColumn();
    }

    public function down(): void
    {
        $this->refuseWhileEntered();

        $customers = $this->db->prefixTable('customers');

        foreach ([['classification_id', 'fk_classification'], ['assigned_employee_id', 'fk_assigned_employee']] as [$column, $suffix]) {
            $name = $customers . '_' . $suffix;

            if ($this->hasForeignKey($customers, $name)) {
                $this->db->query("ALTER TABLE `{$customers}` DROP FOREIGN KEY `{$name}`");
            }
        }

        foreach (['status', 'type', 'billing'] as $suffix) {
            $name = $customers . '_' . $suffix . '_check';

            if ($this->hasConstraint($customers, $name)) {
                $this->db->query("ALTER TABLE `{$customers}` DROP CONSTRAINT `{$name}`");
            }
        }

        foreach (['classification_id' => 'classification', 'manual_status' => 'manual_status', 'assigned_employee_id' => 'assigned', 'country_code' => 'country_code', 'area' => 'area'] as $column => $suffix) {
            $name = $customers . '_' . $suffix;

            if ($this->hasIndex($customers, $name)) {
                $this->db->query("ALTER TABLE `{$customers}` DROP INDEX `{$name}`");
            }
        }

        foreach (array_reverse(array_keys(self::CUSTOMER_COLUMNS)) as $column) {
            if ($this->hasColumn('customers', $column)) {
                $this->db->query("ALTER TABLE `{$customers}` DROP COLUMN `{$column}`");
            }
        }

        if ($this->hasColumn('suppliers', 'country_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('suppliers') . '` DROP COLUMN `country_code`');
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('customer_classifications') . '`');
    }

    private function refuseWhileEntered(): void
    {
        $entered = [
            'classifications' => $this->hasTable('customer_classifications') && $this->db->table('customer_classifications')->countAllResults() > 0,
            'customers'       => $this->hasColumn('customers', 'classification_id') && $this->db->table('customers')
                ->groupStart()
                ->where('classification_id IS NOT NULL')->orWhere('manual_status IS NOT NULL')->orWhere('assigned_employee_id IS NOT NULL')
                ->orWhere('area IS NOT NULL')->orWhere('country_code IS NOT NULL')->orWhere('customer_type IS NOT NULL')
                ->orWhere('is_government_entity !=', 0)->orWhere('billing_method !=', 'print')
                ->groupEnd()->countAllResults() > 0,
            'suppliers' => $this->hasColumn('suppliers', 'country_code') && $this->db->table('suppliers')->where('country_code IS NOT NULL')->countAllResults() > 0,
        ];

        $names = array_keys(array_filter($entered));

        if ($names !== []) {
            throw new RuntimeException('The customer master data holds values (' . implode(', ', $names) . '); taking the migration down would drop them.');
        }
    }

    private function createClassifications(): void
    {
        $table = $this->db->prefixTable('customer_classifications');

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `classification_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(100) NOT NULL,
                `status` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`classification_id`),
                UNIQUE KEY `{$table}_name` (`name`),
                CONSTRAINT `{$table}_status` CHECK (`status` IN (0, 1))
            ) " . self::TABLE_OPTIONS,
        );
    }

    private function addCustomerColumns(): void
    {
        $customers       = $this->db->prefixTable('customers');
        $classifications = $this->db->prefixTable('customer_classifications');
        $employees       = $this->db->prefixTable('employees');

        foreach (self::CUSTOMER_COLUMNS as $column => $definition) {
            if (! $this->hasColumn('customers', $column)) {
                $this->db->query("ALTER TABLE `{$customers}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        $indexes = ['classification_id' => 'classification', 'manual_status' => 'manual_status', 'assigned_employee_id' => 'assigned', 'country_code' => 'country_code', 'area' => 'area'];

        foreach ($indexes as $column => $suffix) {
            $name = $customers . '_' . $suffix;

            if (! $this->hasIndex($customers, $name)) {
                $this->db->query("ALTER TABLE `{$customers}` ADD INDEX `{$name}` (`{$column}`)");
            }
        }

        $classificationKey = $customers . '_fk_classification';
        $employeeKey       = $customers . '_fk_assigned_employee';

        if (! $this->hasForeignKey($customers, $classificationKey)) {
            $this->db->query("ALTER TABLE `{$customers}` ADD CONSTRAINT `{$classificationKey}` FOREIGN KEY (`classification_id`) REFERENCES `{$classifications}` (`classification_id`) ON DELETE RESTRICT");
        }

        if (! $this->hasForeignKey($customers, $employeeKey)) {
            $this->db->query("ALTER TABLE `{$customers}` ADD CONSTRAINT `{$employeeKey}` FOREIGN KEY (`assigned_employee_id`) REFERENCES `{$employees}` (`person_id`) ON DELETE RESTRICT");
        }

        $checks = [
            'status'  => '`manual_status` IS NULL OR `manual_status` IN (' . self::STATUSES . ')',
            'type'    => "`customer_type` IS NULL OR `customer_type` IN ('individual', 'commercial')",
            'billing' => "`billing_method` IN ('print', 'email') AND `is_government_entity` IN (0, 1)",
        ];

        foreach ($checks as $suffix => $condition) {
            $name = $customers . '_' . $suffix . '_check';

            if (! $this->hasConstraint($customers, $name)) {
                $this->db->query("ALTER TABLE `{$customers}` ADD CONSTRAINT `{$name}` CHECK ({$condition})");
            }
        }
    }

    private function addSupplierColumn(): void
    {
        if (! $this->hasColumn('suppliers', 'country_code')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('suppliers') . '` ADD COLUMN `country_code` CHAR(2) NULL DEFAULT NULL');
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index],
        )->getRow() !== null;
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$table, $constraint, 'FOREIGN KEY'],
        )->getRow() !== null;
    }

    private function hasConstraint(string $table, string $constraint): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $constraint],
        )->getRow() !== null;
    }
}
