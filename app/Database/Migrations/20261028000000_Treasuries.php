<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the money held in a treasury or a bank account needs: the treasury itself, the treasury a
 * payment was made into or out of (on sale payments, on payments made against a purchase and on the
 * purchase paid at once), the treasury an employee works from, and the transfers of money between
 * two treasuries. The account of a treasury is opened under the main treasury account when the
 * treasury is created, and the main treasury is the account every payment already went to, so no
 * journal that exists changes. Additive only, and safe to run again.
 */
class Treasuries extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE_ID     = 'accounting_treasuries';
    private const PERMISSION    = 'acc_treasuries_manage';
    private const MODULE_SORT   = 127;

    /**
     * Where a treasury is written down on a document, with the name of the key that holds it.
     */
    private const STAMPED = [
        'sales_payments'      => 'sales_payments_fk_treasury',
        'receivings_payments' => 'receivings_payments_fk_treasury',
        'receivings'          => 'receivings_fk_treasury',
    ];

    public function up(): void
    {
        $this->createTreasuries();
        $this->stampDocuments();
        $this->addEmployeeDefault();
        $this->createTransfers();
        $this->registerModule();
    }

    public function down(): void
    {
        $this->db->table('grants')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('permissions')->whereIn('permission_id', [self::MODULE_ID, self::PERMISSION])->delete();
        $this->db->table('modules')->where('module_id', self::MODULE_ID)->delete();

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('treasury_transfers') . '`');

        if ($this->hasForeignKey('employees', 'employees_fk_treasury')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('employees') . '` DROP FOREIGN KEY `' . $this->db->prefixTable('employees_fk_treasury') . '`');
        }

        if ($this->hasColumn('employees', 'default_treasury_id')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('employees') . '` DROP COLUMN `default_treasury_id`');
        }

        foreach (self::STAMPED as $table => $constraint) {
            $name = $this->db->prefixTable($table);

            if ($this->hasForeignKey($table, $constraint)) {
                $this->db->query("ALTER TABLE `{$name}` DROP FOREIGN KEY `" . $this->db->prefixTable($constraint) . '`');
            }

            if ($this->hasColumn($table, 'treasury_id')) {
                $this->db->query("ALTER TABLE `{$name}` DROP COLUMN `treasury_id`");
            }
        }

        $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable('treasuries') . '`');
    }

    private function createTreasuries(): void
    {
        $table     = $this->db->prefixTable('treasuries');
        $companies = $this->db->prefixTable('companies');
        $branches  = $this->db->prefixTable('branches');
        $options   = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `treasury_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `name` VARCHAR(255) NOT NULL,
                `kind` VARCHAR(8) NOT NULL DEFAULT 'cash',
                `description` VARCHAR(500) NOT NULL DEFAULT '',
                `is_main` TINYINT(1) NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`treasury_id`),
                UNIQUE KEY `{$table}_company_name` (`company_id`, `name`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `active`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_kind` CHECK (`kind` IN ('cash', 'bank'))
            ) {$options}",
        );
    }

    private function stampDocuments(): void
    {
        $treasuries = $this->db->prefixTable('treasuries');

        foreach (self::STAMPED as $table => $constraint) {
            $name = $this->db->prefixTable($table);

            if (! $this->hasColumn($table, 'treasury_id')) {
                $this->db->query("ALTER TABLE `{$name}` ADD COLUMN `treasury_id` INT UNSIGNED NULL DEFAULT NULL");
            }

            if (! $this->hasIndex($table, $table . '_treasury')) {
                $this->db->query("ALTER TABLE `{$name}` ADD INDEX `" . $this->db->prefixTable($table . '_treasury') . '` (`treasury_id`)');
            }

            if (! $this->hasForeignKey($table, $constraint)) {
                $this->db->query(
                    "ALTER TABLE `{$name}` ADD CONSTRAINT `" . $this->db->prefixTable($constraint) . "` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT",
                );
            }
        }
    }

    private function addEmployeeDefault(): void
    {
        $employees  = $this->db->prefixTable('employees');
        $treasuries = $this->db->prefixTable('treasuries');

        if (! $this->hasColumn('employees', 'default_treasury_id')) {
            $this->db->query("ALTER TABLE `{$employees}` ADD COLUMN `default_treasury_id` INT UNSIGNED NULL DEFAULT NULL");
        }

        if (! $this->hasForeignKey('employees', 'employees_fk_treasury')) {
            $this->db->query(
                "ALTER TABLE `{$employees}` ADD CONSTRAINT `" . $this->db->prefixTable('employees_fk_treasury') . "` FOREIGN KEY (`default_treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE SET NULL",
            );
        }
    }

    private function createTransfers(): void
    {
        $table      = $this->db->prefixTable('treasury_transfers');
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $treasuries = $this->db->prefixTable('treasuries');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$table}` (
                `transfer_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `from_treasury_id` INT UNSIGNED NOT NULL,
                `to_treasury_id` INT UNSIGNED NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `transfer_date` DATE NOT NULL,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `status` VARCHAR(10) NOT NULL DEFAULT 'completed',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `cancelled_by` INT(10) NULL,
                `cancelled_at` DATETIME NULL,
                `cancel_reason` VARCHAR(255) NOT NULL DEFAULT '',
                PRIMARY KEY (`transfer_id`),
                KEY `{$table}_from` (`from_treasury_id`),
                KEY `{$table}_to` (`to_treasury_id`),
                KEY `{$table}_branch` (`company_id`, `branch_id`, `transfer_date`),
                CONSTRAINT `{$table}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_from` FOREIGN KEY (`from_treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_fk_to` FOREIGN KEY (`to_treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$table}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$table}_distinct` CHECK (`from_treasury_id` <> `to_treasury_id`),
                CONSTRAINT `{$table}_status` CHECK (`status` IN ('completed', 'cancelled'))
            ) {$options}",
        );
    }

    /**
     * The screen of treasuries and the permission to change them. Nothing is granted: the owner
     * grants them from the employees screen, as with the other accounting screens.
     */
    private function registerModule(): void
    {
        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => self::MODULE_ID,
            'name_lang_key' => 'module_' . self::MODULE_ID,
            'desc_lang_key' => 'module_' . self::MODULE_ID . '_desc',
            'sort'          => self::MODULE_SORT,
        ]);

        $this->db->table('permissions')->ignore(true)->insertBatch([
            ['permission_id' => self::MODULE_ID, 'module_id' => self::MODULE_ID],
            ['permission_id' => self::PERMISSION, 'module_id' => self::MODULE_ID],
        ]);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function hasIndex(string $table, string $index): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($index)],
        )->getRow()->found > 0;
    }

    private function hasForeignKey(string $table, string $name): bool
    {
        return $this->db->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->db->prefixTable($table), $this->db->prefixTable($name), 'FOREIGN KEY'],
        )->getRow()->found > 0;
    }
}
