<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the cheque cycle and the receipt voucher need: the cheque books, the cheques with the
 * history of their status changes, the revenue categories and the receipt vouchers. The chart of a
 * company that already has one gets the leaf accounts the cheque cycle posts to under the two
 * groups of notes (receivable and payable) and under other expenses (the deposit fees), and their
 * four routing rows. The screens are registered as modules and the permission to change cheques as
 * a permission, and nothing is granted. Only tables, accounts, routing rows, modules and permissions
 * are added: no journal, no document and no balance is touched. Safe to run again.
 */
class ChequesAndRevenues extends Migration
{
    private const TABLE_OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    private const MODULES       = [
        ['module_id' => 'accounting_cheques', 'sort' => 129],
        ['module_id' => 'revenues', 'sort' => 130],
        ['module_id' => 'revenues_categories', 'sort' => 131],
    ];
    private const PERMISSIONS = [
        'acc_cheques_manage' => 'accounting_cheques',
    ];
    private const ACCOUNTS = [
        'notes_receivable'         => ['routing' => 'cheques.receivable', 'name' => 'أوراق القبض', 'type' => 'asset', 'normal' => 'debit', 'parent' => '1206', 'codes' => ['120601', '120603', '120604', '120605']],
        'cheques_under_collection' => ['routing' => 'cheques.collection', 'name' => 'مبالغ تحت التحصيل', 'type' => 'asset', 'normal' => 'debit', 'parent' => '1206', 'codes' => ['120602', '120606', '120607', '120608']],
        'notes_payable'            => ['routing' => 'cheques.payable', 'name' => 'أوراق الدفع', 'type' => 'liability', 'normal' => 'credit', 'parent' => '212', 'codes' => ['212001', '212002', '212003', '212004']],
        'cheque_deposit_fees'      => ['routing' => 'cheques.fees', 'name' => 'رسوم الإيداع', 'type' => 'expense', 'normal' => 'debit', 'parent' => '54', 'codes' => ['546', '547', '548', '549']],
        'revenues_default'         => ['routing' => 'revenues.default', 'name' => 'إيرادات أخرى', 'type' => 'revenue', 'normal' => 'credit', 'parent' => '42', 'codes' => ['4201', '4205', '4206', '4207'], 'adopt' => '4201'],
    ];

    public function up(): void
    {
        $this->createTables();
        $this->registerModules();

        if ($this->hasTable('accounting_accounts') && $this->db->table('accounting_accounts')->countAllResults() > 0) {
            foreach (self::ACCOUNTS as $systemKey => $account) {
                $this->openAccount($systemKey, $account);
            }
        }
    }

    public function down(): void
    {
        $moduleIds     = array_column(self::MODULES, 'module_id');
        $permissionIds = [...$moduleIds, ...array_keys(self::PERMISSIONS)];

        $this->db->table('grants')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('permissions')->whereIn('permission_id', $permissionIds)->delete();
        $this->db->table('modules')->whereIn('module_id', $moduleIds)->delete();

        foreach (['revenues', 'revenue_categories', 'cheque_events', 'cheques', 'cheque_books'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS `' . $this->db->prefixTable($table) . '`');
        }

        if (! $this->hasTable('accounting_accounts')) {
            return;
        }

        foreach (self::ACCOUNTS as $systemKey => $account) {
            $row = $this->db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();

            if ($row === null) {
                continue;
            }

            $this->db->table('accounting_routing')->where('routing_key', $account['routing'])->delete();

            if ($this->db->table('accounting_journal_lines')->where('account_id', $row->account_id)->countAllResults() === 0) {
                $this->db->table('accounting_accounts')->where('account_id', $row->account_id)->delete();
            }
        }
    }

    private function createTables(): void
    {
        $companies  = $this->db->prefixTable('companies');
        $branches   = $this->db->prefixTable('branches');
        $treasuries = $this->db->prefixTable('treasuries');
        $accounts   = $this->db->prefixTable('accounting_accounts');
        $people     = $this->db->prefixTable('people');
        $books      = $this->db->prefixTable('cheque_books');
        $cheques    = $this->db->prefixTable('cheques');
        $events     = $this->db->prefixTable('cheque_events');
        $categories = $this->db->prefixTable('revenue_categories');
        $revenues   = $this->db->prefixTable('revenues');
        $options    = self::TABLE_OPTIONS;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$books}` (
                `book_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `treasury_id` INT UNSIGNED NOT NULL,
                `name` VARCHAR(100) NOT NULL,
                `first_serial` BIGINT UNSIGNED NOT NULL,
                `last_serial` BIGINT UNSIGNED NOT NULL,
                `active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`book_id`),
                KEY `{$books}_treasury` (`treasury_id`),
                KEY `{$books}_branch` (`company_id`, `branch_id`),
                CONSTRAINT `{$books}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$books}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$books}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$books}_range` CHECK (`last_serial` >= `first_serial`)
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$cheques}` (
                `cheque_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `direction` VARCHAR(8) NOT NULL,
                `status` VARCHAR(12) NOT NULL,
                `party_id` INT(10) NOT NULL,
                `treasury_id` INT UNSIGNED NOT NULL,
                `book_id` INT UNSIGNED NULL,
                `cheque_number` VARCHAR(64) NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `issue_date` DATE NOT NULL,
                `due_date` DATE NOT NULL,
                `drawer_name` VARCHAR(255) NOT NULL DEFAULT '',
                `endorsed_to` VARCHAR(255) NOT NULL DEFAULT '',
                `description` VARCHAR(500) NOT NULL DEFAULT '',
                `status_date` DATE NULL,
                `status_note` VARCHAR(500) NOT NULL DEFAULT '',
                `deposit_fees` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`cheque_id`),
                KEY `{$cheques}_party` (`direction`, `party_id`, `status`),
                KEY `{$cheques}_branch` (`company_id`, `branch_id`, `direction`, `issue_date`),
                KEY `{$cheques}_due` (`direction`, `status`, `due_date`),
                KEY `{$cheques}_book` (`book_id`, `cheque_number`),
                CONSTRAINT `{$cheques}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$cheques}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$cheques}_fk_party` FOREIGN KEY (`party_id`) REFERENCES `{$people}` (`person_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$cheques}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$cheques}_fk_book` FOREIGN KEY (`book_id`) REFERENCES `{$books}` (`book_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$cheques}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$cheques}_fees` CHECK (`deposit_fees` >= 0),
                CONSTRAINT `{$cheques}_direction` CHECK (`direction` IN ('received', 'paid')),
                CONSTRAINT `{$cheques}_status` CHECK (`status` IN ('received', 'deposited', 'collected', 'rejected', 'issued', 'delivered', 'cancelled'))
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$events}` (
                `event_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `cheque_id` INT UNSIGNED NOT NULL,
                `transition` VARCHAR(16) NOT NULL,
                `from_status` VARCHAR(12) NULL,
                `to_status` VARCHAR(12) NOT NULL,
                `event_date` DATE NOT NULL,
                `fees` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `note` VARCHAR(500) NOT NULL DEFAULT '',
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `undone_by` INT(10) NULL,
                `undone_at` DATETIME NULL,
                PRIMARY KEY (`event_id`),
                KEY `{$events}_cheque` (`cheque_id`, `undone_at`),
                CONSTRAINT `{$events}_fk_cheque` FOREIGN KEY (`cheque_id`) REFERENCES `{$cheques}` (`cheque_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$categories}` (
                `revenue_category_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `category_name` VARCHAR(255) NOT NULL,
                `category_description` VARCHAR(255) NOT NULL DEFAULT '',
                `account_id` INT UNSIGNED NULL DEFAULT NULL,
                `deleted` TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (`revenue_category_id`),
                UNIQUE KEY `{$categories}_name` (`category_name`),
                KEY `{$categories}_account` (`account_id`),
                CONSTRAINT `{$categories}_fk_account` FOREIGN KEY (`account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT
            ) {$options}",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$revenues}` (
                `revenue_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `date` DATE NOT NULL,
                `amount` DECIMAL(15,4) NOT NULL,
                `tax_amount` DECIMAL(15,4) NOT NULL DEFAULT 0,
                `payment_type` VARCHAR(40) NOT NULL DEFAULT '',
                `treasury_id` INT UNSIGNED NULL DEFAULT NULL,
                `treasury_branch_id` INT UNSIGNED NULL DEFAULT NULL,
                `revenue_category_id` INT UNSIGNED NULL DEFAULT NULL,
                `account_id` INT UNSIGNED NULL DEFAULT NULL,
                `description` VARCHAR(500) NOT NULL DEFAULT '',
                `employee_id` INT(10) NULL DEFAULT NULL,
                `deleted` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`revenue_id`),
                KEY `{$revenues}_branch` (`company_id`, `branch_id`, `date`),
                KEY `{$revenues}_category` (`revenue_category_id`),
                KEY `{$revenues}_treasury` (`treasury_id`),
                CONSTRAINT `{$revenues}_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$companies}` (`company_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$revenues}_fk_branch` FOREIGN KEY (`branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$revenues}_fk_treasury` FOREIGN KEY (`treasury_id`) REFERENCES `{$treasuries}` (`treasury_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$revenues}_fk_category` FOREIGN KEY (`revenue_category_id`) REFERENCES `{$categories}` (`revenue_category_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$revenues}_fk_account` FOREIGN KEY (`account_id`) REFERENCES `{$accounts}` (`account_id`) ON DELETE RESTRICT,
                CONSTRAINT `{$revenues}_amount` CHECK (`amount` > 0),
                CONSTRAINT `{$revenues}_tax` CHECK (`tax_amount` >= 0 AND `tax_amount` <= `amount`)
            ) {$options}",
        );
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

        foreach (self::PERMISSIONS as $permissionId => $moduleId) {
            $permissions[] = ['permission_id' => $permissionId, 'module_id' => $moduleId];
        }

        $this->db->table('modules')->ignore(true)->insertBatch($modules);
        $this->db->table('permissions')->ignore(true)->insertBatch($permissions);
    }

    /**
     * @param array{routing: string, name: string, type: string, normal: string, parent: string, codes: list<string>, adopt?: string} $account
     */
    private function openAccount(string $systemKey, array $account): void
    {
        $existing  = $this->db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
        $accountId = $existing === null ? null : (int) $existing->account_id;

        if ($accountId === null && isset($account['adopt'])) {
            $adopted = $this->db->table('accounting_accounts')->where(['code' => $account['adopt'], 'account_type' => $account['type'], 'is_group' => 0, 'active' => 1])->where('system_key', null)->get()->getRow();

            if ($adopted !== null) {
                $this->db->table('accounting_accounts')->where('account_id', $adopted->account_id)->update(['system_key' => $systemKey, 'is_system' => 1]);

                $accountId = (int) $adopted->account_id;
            }
        }

        if ($accountId === null) {
            $parent = $this->db->table('accounting_accounts')->where('code', $account['parent'])->get()->getRow();

            if ($parent === null) {
                log_message('notice', "ChequesAndRevenues found no account {$account['parent']}, so it did not open {$account['name']}.");

                return;
            }

            foreach ($account['codes'] as $code) {
                if ($this->db->table('accounting_accounts')->where('code', $code)->countAllResults() > 0) {
                    continue;
                }

                $this->db->table('accounting_accounts')->insert([
                    'parent_id'      => (int) $parent->account_id,
                    'code'           => $code,
                    'name'           => $account['name'],
                    'account_type'   => $account['type'],
                    'normal_balance' => $account['normal'],
                    'is_group'       => 0,
                    'is_system'      => 1,
                    'system_key'     => $systemKey,
                    'active'         => 1,
                ]);

                $accountId = (int) $this->db->insertID();

                break;
            }

            if ($accountId === null) {
                log_message('notice', "ChequesAndRevenues found no free code for {$account['name']}.");

                return;
            }
        }

        $route = $this->db->table('accounting_routing')->where('routing_key', $account['routing'])->get()->getRow();

        if ($route === null) {
            $this->db->table('accounting_routing')->insert(['routing_key' => $account['routing'], 'routing_type' => 'auto', 'account_id' => $accountId]);
        } elseif ($route->account_id === null) {
            $this->db->table('accounting_routing')->where('routing_key', $account['routing'])->update(['account_id' => $accountId]);
        }
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
