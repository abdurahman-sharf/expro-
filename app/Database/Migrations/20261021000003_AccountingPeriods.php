<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds what fiscal years, period closing, opening balances and the settings screen need: the
 * lock rows posting and closing queue on, the reopening columns of a closed period, a
 * uniqueness rule for fiscal years, the opening balance drafts, and the two modules with
 * their permissions. Additive only, and safe to run again.
 */
class AccountingPeriods extends Migration
{
    private const OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULES = [
        ['module_id' => 'accounting_settings', 'sort' => 123],
        ['module_id' => 'accounting_periods', 'sort' => 124],
    ];
    private const SUB_PERMISSIONS = [
        'acc_settings_manage' => 'accounting_settings',
        'acc_periods_manage'  => 'accounting_periods',
        'acc_periods_close'   => 'accounting_periods',
        'acc_periods_reopen'  => 'accounting_periods',
        'acc_opening_manage'  => 'accounting_periods',
    ];

    public function up(): void
    {
        $this->createTables();
        $this->seedLocks();
        $this->alterFiscalYears();
        $this->alterClosedPeriods();

        $this->db->table('app_config')->ignore(true)->insert(['key' => 'accounting_auto_fiscal_year', 'value' => '1']);

        $this->registerModules();
        $this->grantConfigHolders();
    }

    public function down(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        $this->dropIndex('accounting_closed_periods', 'accounting_closed_periods_scope');

        foreach (['reopened_by', 'reopened_at', 'reopen_reason'] as $column) {
            $this->dropColumn('accounting_closed_periods', $column);
        }

        $this->dropIndex('accounting_fiscal_years', 'accounting_fiscal_years_company_start');
        $this->dropColumn('accounting_fiscal_years', 'description');

        foreach (['accounting_opening_drafts', 'accounting_period_locks'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        $moduleIds     = array_column(self::MODULES, 'module_id');
        $permissionIds = [...$moduleIds, ...array_keys(self::SUB_PERMISSIONS)];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->whereIn('module_id', $moduleIds)->delete();
        $this->db->table('app_config')->where('key', 'accounting_auto_fiscal_year')->delete();
    }

    private function createTables(): void
    {
        $options = self::OPTIONS;

        $statements = [
            "CREATE TABLE IF NOT EXISTS `{p}accounting_period_locks` (
                `company_id` INT UNSIGNED NOT NULL,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 0,
                PRIMARY KEY (`company_id`, `branch_id`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}accounting_opening_drafts` (
                `branch_id` INT UNSIGNED NOT NULL,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `lines` MEDIUMTEXT NOT NULL,
                `updated_by` INT NULL,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`branch_id`),
                CONSTRAINT `{p}accounting_opening_drafts_fk_branch` FOREIGN KEY (`branch_id`)
                    REFERENCES `{p}branches` (`branch_id`) ON DELETE RESTRICT
            ) {$options}",
        ];

        foreach ($statements as $statement) {
            $this->db->query(str_replace('{p}', $this->db->getPrefix(), $statement));
        }
    }

    /**
     * One row for the company (branch 0) and one per branch, so a lock always has a row to hold.
     */
    private function seedLocks(): void
    {
        $this->db->query(
            'INSERT IGNORE INTO ' . $this->db->prefixTable('accounting_period_locks') . ' (company_id, branch_id)
             SELECT company_id, 0 FROM ' . $this->db->prefixTable('companies'),
        );
        $this->db->query(
            'INSERT IGNORE INTO ' . $this->db->prefixTable('accounting_period_locks') . ' (company_id, branch_id)
             SELECT company_id, branch_id FROM ' . $this->db->prefixTable('branches'),
        );
    }

    private function alterFiscalYears(): void
    {
        $table = $this->db->prefixTable('accounting_fiscal_years');

        if (! $this->hasColumn('accounting_fiscal_years', 'description')) {
            $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `description` VARCHAR(255) NULL");
        }

        if (! $this->hasIndex('accounting_fiscal_years', 'accounting_fiscal_years_company_start')) {
            $this->db->query("ALTER TABLE `{$table}` ADD UNIQUE KEY `" . $this->db->prefixTable('accounting_fiscal_years_company_start') . '` (`company_id`, `start_date`)');
        }
    }

    private function alterClosedPeriods(): void
    {
        $table = $this->db->prefixTable('accounting_closed_periods');

        foreach (['reopened_by' => 'INT NULL', 'reopened_at' => 'DATETIME NULL', 'reopen_reason' => 'VARCHAR(255) NULL'] as $column => $definition) {
            if (! $this->hasColumn('accounting_closed_periods', $column)) {
                $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        if (! $this->hasIndex('accounting_closed_periods', 'accounting_closed_periods_scope')) {
            $this->db->query("ALTER TABLE `{$table}` ADD INDEX `" . $this->db->prefixTable('accounting_closed_periods_scope') . '` (`company_id`, `branch_id`, `active`, `date_to`)');
        }
    }

    private function registerModules(): void
    {
        $modules     = [];
        $permissions = [];

        foreach (self::MODULES as $module) {
            $id = $module['module_id'];

            $modules[] = [
                'module_id'     => $id,
                'name_lang_key' => "module_{$id}",
                'desc_lang_key' => "module_{$id}_desc",
                'sort'          => $module['sort'],
            ];
            $permissions[] = ['permission_id' => $id, 'module_id' => $id];
        }

        foreach (self::SUB_PERMISSIONS as $permissionId => $moduleId) {
            $permissions[] = ['permission_id' => $permissionId, 'module_id' => $moduleId];
        }

        $this->db->table('modules')->ignore(true)->insertBatch($modules);
        $this->db->table('permissions')->ignore(true)->insertBatch($permissions);
    }

    /**
     * The owner approved giving these to whoever can already change the application settings.
     * Nobody else gets them until the owner grants them from the employees screen.
     */
    private function grantConfigHolders(): void
    {
        $grants = [];

        foreach ($this->db->table('grants')->select('person_id, menu_group')->where('permission_id', 'config')->get()->getResult() as $holder) {
            foreach (self::MODULES as $module) {
                $grants[] = ['permission_id' => $module['module_id'], 'person_id' => $holder->person_id, 'menu_group' => $holder->menu_group];
            }

            foreach (array_keys(self::SUB_PERMISSIONS) as $permissionId) {
                $grants[] = ['permission_id' => $permissionId, 'person_id' => $holder->person_id, 'menu_group' => '--'];
            }
        }

        if ($grants !== []) {
            $this->db->table('grants')->ignore(true)->insertBatch($grants);
        }
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

    private function dropIndex(string $table, string $index): void
    {
        if ($this->hasIndex($table, $index)) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . '` DROP INDEX `' . $this->db->prefixTable($index) . '`');
        }
    }

    private function dropColumn(string $table, string $column): void
    {
        if ($this->hasColumn($table, $column)) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . "` DROP COLUMN `{$column}`");
        }
    }
}
