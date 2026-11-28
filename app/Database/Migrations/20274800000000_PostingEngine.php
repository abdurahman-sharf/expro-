<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The tables of the posting engine: the posting rules of each operation, by version and the day each
 * version starts to apply, the lines of each version, the mode each operation is posted in (by the code
 * of its document, by the engine, or by the code with the engine checked beside it), and what the engine
 * would have posted differently while it is checked. It seeds, for every company, the rules of the
 * expense as the expense is posted today, and sets the expense to be checked beside the code. No journal
 * and no balance is touched.
 */
class PostingEngine extends Migration
{
    public const EXPENSE_LINES = [
        [1, 'credit', '{"first":[{"when":"is_due","system":"other_payables"},{"when":"has_treasury","role":"treasuries.accounts","entity":"treasury_id"},{"when":"no_treasury","payment":"payment_type"}],"else":"payment_type_unknown"}', 'gross'],
        [2, 'debit', '{"first":[{"field":"category_account_id"},{"role":"expenses.default"}]}', 'net'],
        [3, 'debit', '{"first":[{"role":"tax.input"}]}', 'tax'],
    ];

    public function up(): void
    {
        $p = $this->db->DBPrefix;

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}accounting_posting_rule_sets` (
                `rule_set_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `operation` VARCHAR(32) NOT NULL,
                `version` INT UNSIGNED NOT NULL,
                `effective_from` DATE NOT NULL,
                `source_type` VARCHAR(32) NOT NULL,
                `entry_type` VARCHAR(16) NOT NULL DEFAULT 'general',
                `description` VARCHAR(255) NOT NULL DEFAULT '',
                `cross_branch` TINYINT(1) NOT NULL DEFAULT 0,
                `foreign_rounding` VARCHAR(255) NULL,
                `note` VARCHAR(500) NULL,
                `created_by` INT(10) NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`rule_set_id`),
                UNIQUE KEY `{$p}accounting_posting_rule_sets_version` (`company_id`, `operation`, `version`),
                KEY `{$p}accounting_posting_rule_sets_effective` (`company_id`, `operation`, `effective_from`),
                CONSTRAINT `{$p}accounting_posting_rule_sets_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$p}companies` (`company_id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}accounting_posting_rules` (
                `rule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `rule_set_id` INT UNSIGNED NOT NULL,
                `line_no` SMALLINT UNSIGNED NOT NULL,
                `side` VARCHAR(6) NOT NULL,
                `account` TEXT NOT NULL,
                `amount` VARCHAR(255) NOT NULL,
                `repeat_over` VARCHAR(32) NULL,
                `foreign_amount` VARCHAR(255) NULL,
                `cost_center` VARCHAR(255) NULL,
                `auto_tax` TINYINT(1) NOT NULL DEFAULT 0,
                `matrix_ref` VARCHAR(16) NULL,
                PRIMARY KEY (`rule_id`),
                UNIQUE KEY `{$p}accounting_posting_rules_line` (`rule_set_id`, `line_no`),
                CONSTRAINT `{$p}accounting_posting_rules_side` CHECK (`side` IN ('debit', 'credit')),
                CONSTRAINT `{$p}accounting_posting_rules_fk_set` FOREIGN KEY (`rule_set_id`) REFERENCES `{$p}accounting_posting_rule_sets` (`rule_set_id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}accounting_posting_modes` (
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `operation` VARCHAR(32) NOT NULL,
                `mode` VARCHAR(8) NOT NULL DEFAULT 'legacy',
                `changed_by` INT(10) NULL,
                `changed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`company_id`, `operation`),
                CONSTRAINT `{$p}accounting_posting_modes_mode` CHECK (`mode` IN ('legacy', 'shadow', 'engine')),
                CONSTRAINT `{$p}accounting_posting_modes_fk_company` FOREIGN KEY (`company_id`) REFERENCES `{$p}companies` (`company_id`) ON DELETE RESTRICT
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );

        $this->db->query(
            "CREATE TABLE IF NOT EXISTS `{$p}accounting_posting_shadow_diffs` (
                `diff_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `company_id` INT UNSIGNED NOT NULL DEFAULT 1,
                `operation` VARCHAR(32) NOT NULL,
                `source_type` VARCHAR(32) NOT NULL,
                `source_id` INT NULL,
                `revision` INT UNSIGNED NOT NULL DEFAULT 1,
                `branch_id` INT UNSIGNED NULL,
                `rule_set_id` INT UNSIGNED NULL,
                `legacy_lines` TEXT NULL,
                `engine_lines` TEXT NULL,
                `differences` TEXT NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`diff_id`),
                KEY `{$p}accounting_posting_shadow_diffs_operation` (`company_id`, `operation`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",
        );

        foreach ($this->db->table('companies')->select('company_id')->get()->getResult() as $company) {
            $this->seedExpense((int) $company->company_id);
        }
    }

    public function down(): void
    {
        $p = $this->db->DBPrefix;

        foreach (['accounting_posting_shadow_diffs', 'accounting_posting_modes', 'accounting_posting_rules', 'accounting_posting_rule_sets'] as $table) {
            $this->db->query("DROP TABLE IF EXISTS `{$p}{$table}`");
        }
    }

    private function seedExpense(int $companyId): void
    {
        if ($this->db->table('accounting_posting_rule_sets')->where(['company_id' => $companyId, 'operation' => 'expense'])->countAllResults() === 0) {
            $this->db->table('accounting_posting_rule_sets')->insert([
                'company_id'     => $companyId,
                'operation'      => 'expense',
                'version'        => 1,
                'effective_from' => '1900-01-01',
                'source_type'    => 'expense',
                'entry_type'     => 'general',
                'description'    => 'Expense #{source_id}',
                'cross_branch'   => 1,
                'note'           => 'As the expense was posted when the engine came in.',
            ]);

            $setId = (int) $this->db->insertID();

            foreach (self::EXPENSE_LINES as [$lineNo, $side, $account, $amount]) {
                $this->db->table('accounting_posting_rules')->insert([
                    'rule_set_id' => $setId,
                    'line_no'     => $lineNo,
                    'side'        => $side,
                    'account'     => $account,
                    'amount'      => $amount,
                    'matrix_ref'  => $lineNo === 3 ? 'P-46' : 'P-45',
                ]);
            }
        }

        $this->db->table('accounting_posting_modes')->ignore(true)->insert(['company_id' => $companyId, 'operation' => 'expense', 'mode' => 'shadow']);
    }
}
