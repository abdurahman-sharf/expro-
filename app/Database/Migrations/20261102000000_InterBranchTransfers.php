<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * A transfer between treasuries of two branches is two journals, one in each branch, that meet in
 * two accounts of the chart: what the sending branch is owed by the other branches and what the
 * receiving branch owes them. The transfer keeps the branch it goes to, and the chart of a company
 * that already has one gets the two accounts and their routing rows. Only columns, accounts and
 * routing rows are added: no journal, no document and no balance is touched. Safe to run again.
 */
class InterBranchTransfers extends Migration
{
    private const ACCOUNTS = [
        'branches_receivable' => ['routing' => 'branches.receivable', 'name' => 'ذمم بين الفروع (مدينة)', 'type' => 'asset', 'normal' => 'debit', 'parent' => '1204', 'codes' => ['12044', '12045', '12046', '12047', '12048', '12049']],
        'branches_payable'    => ['routing' => 'branches.payable', 'name' => 'ذمم بين الفروع (دائنة)', 'type' => 'liability', 'normal' => 'credit', 'parent' => '211', 'codes' => ['2214', '2215', '2216', '2217', '2218', '2219']],
    ];

    public function up(): void
    {
        $transfers = $this->db->prefixTable('treasury_transfers');
        $branches  = $this->db->prefixTable('branches');

        if ($this->hasTable('treasury_transfers') && ! $this->hasColumn('treasury_transfers', 'to_branch_id')) {
            $this->db->query("ALTER TABLE `{$transfers}` ADD COLUMN `to_branch_id` INT UNSIGNED NULL DEFAULT NULL AFTER `branch_id`");
            $this->db->query("ALTER TABLE `{$transfers}` ADD KEY `{$transfers}_to_branch` (`to_branch_id`)");
            $this->db->query("ALTER TABLE `{$transfers}` ADD CONSTRAINT `{$transfers}_fk_to_branch` FOREIGN KEY (`to_branch_id`) REFERENCES `{$branches}` (`branch_id`) ON DELETE RESTRICT");
        }

        if ($this->hasTable('accounting_accounts') && $this->db->table('accounting_accounts')->countAllResults() > 0) {
            foreach (self::ACCOUNTS as $systemKey => $account) {
                $this->openAccount($systemKey, $account);
            }
        }
    }

    public function down(): void
    {
        $transfers = $this->db->prefixTable('treasury_transfers');

        if ($this->hasTable('treasury_transfers') && $this->hasColumn('treasury_transfers', 'to_branch_id')) {
            $this->db->query("ALTER TABLE `{$transfers}` DROP FOREIGN KEY `{$transfers}_fk_to_branch`");
            $this->db->query("ALTER TABLE `{$transfers}` DROP KEY `{$transfers}_to_branch`");
            $this->db->query("ALTER TABLE `{$transfers}` DROP COLUMN `to_branch_id`");
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

    /**
     * @param array{routing: string, name: string, type: string, normal: string, parent: string, codes: list<string>} $account
     */
    private function openAccount(string $systemKey, array $account): void
    {
        $existing  = $this->db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
        $accountId = $existing === null ? null : (int) $existing->account_id;

        if ($accountId === null) {
            $parent = $this->db->table('accounting_accounts')->where('code', $account['parent'])->get()->getRow();

            if ($parent === null) {
                log_message('notice', "InterBranchTransfers found no account {$account['parent']}, so it did not open {$account['name']}.");

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
                log_message('notice', "InterBranchTransfers found no free code for {$account['name']}.");

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

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }
}
