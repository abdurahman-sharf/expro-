<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The accounts the register of fixed assets posts to when a category or an asset names none, in the
 * chart of a company that already has one, with their routing rows: the cost of fixed assets under the
 * group of their accounts, the accumulated depreciation under the group of the depreciation
 * accumulations, the depreciation expense under the group of the depreciation expenses, the gain on the
 * disposal of an asset (the account of capital gains and losses the chart already holds when it has no
 * key yet) and the loss, and the account of what is owed for an asset bought on credit, which is the
 * account of other payables the chart already has. Only accounts and routing rows are added: no
 * journal, no asset and no balance is touched. Safe to run again.
 */
class AssetAccounts extends Migration
{
    private const ACCOUNTS = [
        'fixed_assets'             => ['routing' => 'assets.cost', 'name' => 'أصول ثابتة', 'type' => 'asset', 'normal' => 'debit', 'parent' => '111', 'codes' => ['111006', '111007', '111008', '111009']],
        'accumulated_depreciation' => ['routing' => 'assets.accumulated', 'name' => 'مجمع إهلاك - عام', 'type' => 'asset', 'normal' => 'credit', 'parent' => '112', 'codes' => ['112006', '112007', '112008', '112009']],
        'depreciation_expense'     => ['routing' => 'assets.expense', 'name' => 'مصروف الإهلاك', 'type' => 'expense', 'normal' => 'debit', 'parent' => '53', 'codes' => ['5301', '5302', '5303', '5304']],
        'asset_gain'               => ['routing' => 'assets.gain', 'name' => 'أرباح وخسائر رأسمالية', 'type' => 'revenue', 'normal' => 'credit', 'parent' => '42', 'codes' => ['4202', '4213', '4214', '4215'], 'adopt' => '4202'],
        'asset_loss'               => ['routing' => 'assets.loss', 'name' => 'خسائر بيع وشطب الأصول', 'type' => 'expense', 'normal' => 'debit', 'parent' => '54', 'codes' => ['5420', '5421', '5422', '5423']],
    ];
    private const PAYABLE = ['routing' => 'assets.payable', 'system_key' => 'other_payables'];

    public function up(): void
    {
        if (! $this->hasTable('accounting_accounts') || $this->db->table('accounting_accounts')->countAllResults() === 0) {
            return;
        }

        foreach (self::ACCOUNTS as $systemKey => $account) {
            $this->openAccount($systemKey, $account);
        }

        $this->routePayable();
    }

    public function down(): void
    {
        if (! $this->hasTable('accounting_accounts')) {
            return;
        }

        foreach (self::ACCOUNTS as $systemKey => $account) {
            $row = $this->db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();

            if ($row === null) {
                continue;
            }

            $this->db->table('accounting_routing')->where('routing_key', $account['routing'])->delete();

            if (isset($account['adopt']) && $row->code === $account['adopt']) {
                $this->db->table('accounting_accounts')->where('account_id', $row->account_id)->update(['system_key' => null]);

                continue;
            }

            if (! $this->isUsed((int) $row->account_id)) {
                $this->db->table('accounting_accounts')->where('account_id', $row->account_id)->delete();
            }
        }

        $this->db->table('accounting_routing')->where('routing_key', self::PAYABLE['routing'])->delete();
    }

    /**
     * Whether a journal line, an asset or a category of assets names the account.
     */
    private function isUsed(int $accountId): bool
    {
        if ($this->db->table('accounting_journal_lines')->where('account_id', $accountId)->countAllResults() > 0) {
            return true;
        }

        if ($this->hasTable('assets') && $this->db->table('assets')->groupStart()->where('cost_account_id', $accountId)->orWhere('accumulated_account_id', $accountId)->orWhere('expense_account_id', $accountId)->groupEnd()->countAllResults() > 0) {
            return true;
        }

        return $this->hasTable('asset_categories') && $this->db->table('asset_categories')->groupStart()->where('cost_account_id', $accountId)->orWhere('accumulated_account_id', $accountId)->orWhere('expense_account_id', $accountId)->groupEnd()->countAllResults() > 0;
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
                log_message('notice', "AssetAccounts found no account {$account['parent']}, so it did not open {$account['name']}.");

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
                log_message('notice', "AssetAccounts found no free code for {$account['name']}.");

                return;
            }
        }

        $this->route($account['routing'], $accountId);
    }

    private function routePayable(): void
    {
        $payable = $this->db->table('accounting_accounts')->where('system_key', self::PAYABLE['system_key'])->get()->getRow();

        if ($payable !== null) {
            $this->route(self::PAYABLE['routing'], (int) $payable->account_id);
        }
    }

    private function route(string $routingKey, int $accountId): void
    {
        $route = $this->db->table('accounting_routing')->where('routing_key', $routingKey)->get()->getRow();

        if ($route === null) {
            $this->db->table('accounting_routing')->insert(['routing_key' => $routingKey, 'routing_type' => 'auto', 'account_id' => $accountId]);
        } elseif ($route->account_id === null) {
            $this->db->table('accounting_routing')->where('routing_key', $routingKey)->update(['account_id' => $accountId]);
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
