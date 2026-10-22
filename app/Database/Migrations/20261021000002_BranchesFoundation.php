<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * Adds the company and branch layer. Everything that exists today keeps working: the current
 * data belongs to one default company and one default branch, every employee keeps the access
 * they have, and every stock location belongs to the default branch. Additive only, and safe to
 * run again.
 */
class BranchesFoundation extends Migration
{
    private const OPTIONS         = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULE          = ['module_id' => 'branches', 'sort' => 85];
    private const SUB_PERMISSIONS = ['org_branches_manage', 'org_scope_manage', 'org_all_branches'];
    private const COMPANY_TABLES  = [
        'accounting_accounts',
        'accounting_cost_centers',
        'accounting_fiscal_years',
        'accounting_closed_periods',
        'accounting_sequences',
        'accounting_routing',
        'accounting_backfill_batches',
        'accounting_journals',
    ];
    private const BRANCH_TABLES = [
        'accounting_journals'         => false,
        'accounting_closed_periods'   => true,
        'accounting_audit_log'        => true,
        'accounting_posting_failures' => true,
        'accounting_ai_proposals'     => true,
    ];

    public function up(): void
    {
        $this->createTables();
        $this->seedDefaults();
        $this->addCompanyColumns();
        $this->addBranchColumns();
        $this->addStockLocationBranch();
        $this->replaceUniqueKeys();
        $this->registerModule();
        $this->grantExistingEmployees();
    }

    public function down(): void
    {
        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        $this->restoreUniqueKeys();
        $this->dropIndex('accounting_journals', 'accounting_journals_branch_date');
        $this->dropIndex('accounting_audit_log', 'accounting_audit_log_branch');

        foreach (array_keys(self::BRANCH_TABLES) as $table) {
            $this->dropColumn($table, 'branch_id', "{$table}_fk_branch");
        }

        foreach (self::COMPANY_TABLES as $table) {
            $this->dropColumn($table, 'company_id', "{$table}_fk_company");
        }

        $this->dropColumn('stock_locations', 'branch_id', 'stock_locations_fk_branch');

        $permissionIds = [self::MODULE['module_id'], ...self::SUB_PERMISSIONS];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->where('module_id', self::MODULE['module_id'])->delete();

        foreach (['employee_branches', 'branches', 'companies'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function createTables(): void
    {
        $options = self::OPTIONS;

        $statements = [
            "CREATE TABLE IF NOT EXISTS `{p}companies` (
                `company_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name` VARCHAR(255) NOT NULL,
                `tax_id` VARCHAR(64) NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`company_id`)
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}branches` (
                `branch_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL,
                `code` VARCHAR(16) NOT NULL,
                `name` VARCHAR(255) NOT NULL,
                `address` VARCHAR(255) NULL,
                `phone` VARCHAR(64) NULL,
                `tax_registration` VARCHAR(64) NULL,
                `opened_on` DATE NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`branch_id`),
                UNIQUE KEY `{p}branches_company_code` (`company_id`, `code`),
                CONSTRAINT `{p}branches_fk_company` FOREIGN KEY (`company_id`)
                    REFERENCES `{p}companies` (`company_id`) ON DELETE RESTRICT
            ) {$options}",

            "CREATE TABLE IF NOT EXISTS `{p}employee_branches` (
                `person_id` INT NOT NULL,
                `branch_id` INT UNSIGNED NOT NULL,
                `is_default` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`person_id`, `branch_id`),
                KEY `{p}employee_branches_branch` (`branch_id`),
                CONSTRAINT `{p}employee_branches_fk_person` FOREIGN KEY (`person_id`)
                    REFERENCES `{p}employees` (`person_id`) ON DELETE CASCADE,
                CONSTRAINT `{p}employee_branches_fk_branch` FOREIGN KEY (`branch_id`)
                    REFERENCES `{p}branches` (`branch_id`) ON DELETE RESTRICT
            ) {$options}",
        ];

        foreach ($statements as $statement) {
            $this->db->query($this->withPrefix($statement));
        }
    }

    private function seedDefaults(): void
    {
        $name = $this->db->table('app_config')->where('key', 'company')->get()->getRow();

        $this->db->table('companies')->ignore(true)->insert([
            'company_id' => 1,
            'name'       => $name !== null && trim((string) $name->value) !== '' ? $name->value : 'الشركة',
        ]);

        $this->db->table('branches')->ignore(true)->insert([
            'branch_id'  => 1,
            'company_id' => 1,
            'code'       => 'HQ',
            'name'       => 'الفرع الرئيسي',
            'is_default' => 1,
        ]);

        $this->db->query(
            'INSERT IGNORE INTO ' . $this->db->prefixTable('employee_branches') . ' (person_id, branch_id, is_default)
             SELECT person_id, 1, 1 FROM ' . $this->db->prefixTable('employees'),
        );
    }

    private function addCompanyColumns(): void
    {
        foreach (self::COMPANY_TABLES as $table) {
            if (! $this->hasColumn($table, 'company_id')) {
                $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . '` ADD COLUMN `company_id` INT UNSIGNED NOT NULL DEFAULT 1');
            }

            $this->addForeignKey($table, 'company_id', "{$table}_fk_company", 'companies', 'company_id');
        }
    }

    private function addBranchColumns(): void
    {
        foreach (self::BRANCH_TABLES as $table => $nullable) {
            if (! $this->hasColumn($table, 'branch_id')) {
                $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . '` ADD COLUMN `branch_id` INT UNSIGNED ' . ($nullable ? 'NULL' : 'NOT NULL DEFAULT 1'));
            }

            $this->addForeignKey($table, 'branch_id', "{$table}_fk_branch", 'branches', 'branch_id');
        }

        if (! $this->hasIndex('accounting_journals', 'accounting_journals_branch_date')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('accounting_journals') . '` ADD INDEX `' . $this->db->prefixTable('accounting_journals_branch_date') . '` (`branch_id`, `journal_date`)');
        }

        if (! $this->hasIndex('accounting_audit_log', 'accounting_audit_log_branch')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('accounting_audit_log') . '` ADD INDEX `' . $this->db->prefixTable('accounting_audit_log_branch') . '` (`branch_id`, `created_at`)');
        }
    }

    private function addStockLocationBranch(): void
    {
        if (! $this->hasColumn('stock_locations', 'branch_id')) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable('stock_locations') . '` ADD COLUMN `branch_id` INT UNSIGNED NOT NULL DEFAULT 1');
        }

        $this->addForeignKey('stock_locations', 'branch_id', 'stock_locations_fk_branch', 'branches', 'branch_id');
    }

    private function replaceUniqueKeys(): void
    {
        $this->replaceIndex('accounting_accounts', 'accounting_accounts_code', 'accounting_accounts_company_code', ['company_id', 'code']);
        $this->replaceIndex('accounting_accounts', 'accounting_accounts_system_key', 'accounting_accounts_company_system_key', ['company_id', 'system_key']);
        $this->replaceIndex('accounting_accounts', 'accounting_accounts_entity', 'accounting_accounts_company_entity', ['company_id', 'entity_type', 'entity_id']);
        $this->replaceIndex('accounting_cost_centers', 'accounting_cost_centers_code', 'accounting_cost_centers_company_code', ['company_id', 'code']);
        $this->replaceIndex('accounting_journals', 'accounting_journals_number', 'accounting_journals_company_number', ['company_id', 'series', 'number']);

        $this->replacePrimaryKey('accounting_sequences', ['company_id', 'series']);
        $this->replacePrimaryKey('accounting_routing', ['company_id', 'routing_key']);
    }

    private function restoreUniqueKeys(): void
    {
        $this->replaceIndex('accounting_accounts', 'accounting_accounts_company_code', 'accounting_accounts_code', ['code']);
        $this->replaceIndex('accounting_accounts', 'accounting_accounts_company_system_key', 'accounting_accounts_system_key', ['system_key']);
        $this->replaceIndex('accounting_accounts', 'accounting_accounts_company_entity', 'accounting_accounts_entity', ['entity_type', 'entity_id']);
        $this->replaceIndex('accounting_cost_centers', 'accounting_cost_centers_company_code', 'accounting_cost_centers_code', ['code']);
        $this->replaceIndex('accounting_journals', 'accounting_journals_company_number', 'accounting_journals_number', ['series', 'number']);

        $this->replacePrimaryKey('accounting_sequences', ['series']);
        $this->replacePrimaryKey('accounting_routing', ['routing_key']);
    }

    private function registerModule(): void
    {
        $id = self::MODULE['module_id'];

        $this->db->table('modules')->ignore(true)->insert([
            'module_id'     => $id,
            'name_lang_key' => "module_{$id}",
            'desc_lang_key' => "module_{$id}_desc",
            'sort'          => self::MODULE['sort'],
        ]);

        $permissions = [['permission_id' => $id, 'module_id' => $id]];

        foreach (self::SUB_PERMISSIONS as $permissionId) {
            $permissions[] = ['permission_id' => $permissionId, 'module_id' => $id];
        }

        $this->db->table('permissions')->ignore(true)->insertBatch($permissions);
    }

    /**
     * Every employee may work in every branch, as before. The branch screens go to those who can
     * already change the application settings.
     */
    private function grantExistingEmployees(): void
    {
        $grants = [];

        foreach ($this->db->table('employees')->select('person_id')->get()->getResult() as $employee) {
            $grants[] = ['permission_id' => 'org_all_branches', 'person_id' => $employee->person_id, 'menu_group' => '--'];
        }

        foreach ($this->db->table('grants')->select('person_id, menu_group')->where('permission_id', 'config')->get()->getResult() as $holder) {
            $grants[] = ['permission_id' => self::MODULE['module_id'], 'person_id' => $holder->person_id, 'menu_group' => $holder->menu_group];
            $grants[] = ['permission_id' => 'org_branches_manage', 'person_id' => $holder->person_id, 'menu_group' => '--'];
            $grants[] = ['permission_id' => 'org_scope_manage', 'person_id' => $holder->person_id, 'menu_group' => '--'];
        }

        if ($grants !== []) {
            $this->db->table('grants')->ignore(true)->insertBatch($grants);
        }
    }

    private function withPrefix(string $sql): string
    {
        return str_replace('{p}', $this->db->getPrefix(), $sql);
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

    private function addForeignKey(string $table, string $column, string $name, string $referencedTable, string $referencedColumn): void
    {
        if ($this->hasForeignKey($table, $name)) {
            return;
        }

        $this->db->query(
            'ALTER TABLE `' . $this->db->prefixTable($table) . '` ADD CONSTRAINT `' . $this->db->prefixTable($name) . "` FOREIGN KEY (`{$column}`) REFERENCES `"
            . $this->db->prefixTable($referencedTable) . "` (`{$referencedColumn}`) ON DELETE RESTRICT",
        );
    }

    private function dropIndex(string $table, string $index): void
    {
        if ($this->hasIndex($table, $index)) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . '` DROP INDEX `' . $this->db->prefixTable($index) . '`');
        }
    }

    private function dropColumn(string $table, string $column, string $foreignKey): void
    {
        if (! $this->hasColumn($table, $column)) {
            return;
        }

        if ($this->hasForeignKey($table, $foreignKey)) {
            $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . '` DROP FOREIGN KEY `' . $this->db->prefixTable($foreignKey) . '`');
        }

        $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . "` DROP COLUMN `{$column}`");
    }

    /**
     * @param list<string> $columns
     */
    private function replaceIndex(string $table, string $oldName, string $newName, array $columns): void
    {
        if (! $this->hasColumn($table, $columns[0])) {
            return;
        }

        $name  = $this->db->prefixTable($table);
        $parts = [];

        foreach ($columns as $column) {
            $parts[] = "`{$column}`";
        }

        $statements = [];

        if ($this->hasIndex($table, $oldName)) {
            $statements[] = 'DROP INDEX `' . $this->db->prefixTable($oldName) . '`';
        }

        if (! $this->hasIndex($table, $newName)) {
            $statements[] = 'ADD UNIQUE INDEX `' . $this->db->prefixTable($newName) . '` (' . implode(', ', $parts) . ')';
        }

        if ($statements !== []) {
            $this->db->query("ALTER TABLE `{$name}` " . implode(', ', $statements));
        }
    }

    /**
     * @param list<string> $columns
     */
    private function replacePrimaryKey(string $table, array $columns): void
    {
        $current = array_column(
            $this->db->query(
                "SELECT COLUMN_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY' ORDER BY SEQ_IN_INDEX",
                [$this->db->prefixTable($table)],
            )->getResultArray(),
            'name',
        );

        if ($current === $columns) {
            return;
        }

        $parts = array_map(static fn (string $column): string => "`{$column}`", $columns);

        $this->db->query('ALTER TABLE `' . $this->db->prefixTable($table) . '` DROP PRIMARY KEY, ADD PRIMARY KEY (' . implode(', ', $parts) . ')');
    }
}
