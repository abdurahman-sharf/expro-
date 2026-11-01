<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * What the header of a sale can carry apart from its lines: an adjustment (a label and a signed amount)
 * and a shipping charge with the tax on it. The columns are nullable and nothing is written to them
 * here, so every sale that exists stays as it was. The chart of a company that already has one gets
 * the two revenue accounts the charges post to (adjustments and shipping) and their routing rows: the
 * row of adjustments, which was cancelled and pointed at discounts allowed, now points at its own
 * account when nobody had changed it, and the row of shipping is added. Only columns, accounts and
 * routing rows are added: no journal, no sale and no balance is touched. Safe to run again.
 */
class InvoiceHeaderCharges extends Migration
{
    private const COLUMNS = [
        'adjustment_label'     => 'VARCHAR(100) NULL DEFAULT NULL',
        'adjustment_amount'    => 'DECIMAL(15,4) NULL DEFAULT NULL',
        'shipping_amount'      => 'DECIMAL(15,4) NULL DEFAULT NULL',
        'shipping_options'     => 'TINYINT UNSIGNED NULL DEFAULT NULL',
        'shipping_tax_option'  => 'VARCHAR(10) NULL DEFAULT NULL',
        'shipping_tax_percent' => 'DECIMAL(9,4) NULL DEFAULT NULL',
        'shipping_tax_amount'  => 'DECIMAL(15,4) NULL DEFAULT NULL',
    ];
    private const ACCOUNTS = [
        'sales_adjustment' => ['routing' => 'sales.adjustment', 'name' => 'تسويات المبيعات', 'parent' => '42', 'codes' => ['4204', '4209', '4210', '4211']],
        'sales_shipping'   => ['routing' => 'sales.shipping', 'name' => 'إيرادات الشحن', 'parent' => '42', 'codes' => ['4208', '4212', '4213', '4214']],
    ];

    public function up(): void
    {
        $table = $this->db->prefixTable('sales');

        foreach (self::COLUMNS as $column => $definition) {
            if (! $this->hasColumn('sales', $column)) {
                $this->db->query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
            }
        }

        if (! $this->hasTable('accounting_accounts') || $this->db->table('accounting_accounts')->countAllResults() === 0) {
            return;
        }

        foreach (self::ACCOUNTS as $systemKey => $account) {
            $this->openAccount($systemKey, $account);
        }
    }

    public function down(): void
    {
        $table = $this->db->prefixTable('sales');

        foreach (array_keys(self::COLUMNS) as $column) {
            if ($this->hasColumn('sales', $column)) {
                $this->db->query("ALTER TABLE `{$table}` DROP COLUMN `{$column}`");
            }
        }

        if (! $this->hasTable('accounting_accounts')) {
            return;
        }

        $discount = $this->db->table('accounting_accounts')->where('system_key', 'sales_discount')->get()->getRow();

        $this->db->table('accounting_routing')->where('routing_key', 'sales.shipping')->delete();

        if ($discount !== null) {
            $this->db->table('accounting_routing')->where('routing_key', 'sales.adjustment')->update(['routing_type' => 'cancelled', 'account_id' => (int) $discount->account_id]);
        }

        foreach (array_keys(self::ACCOUNTS) as $systemKey) {
            $row = $this->db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();

            if ($row !== null && $this->db->table('accounting_journal_lines')->where('account_id', $row->account_id)->countAllResults() === 0) {
                $this->db->table('accounting_routing')->where('account_id', $row->account_id)->update(['account_id' => null]);
                $this->db->table('accounting_accounts')->where('account_id', $row->account_id)->delete();
            }
        }
    }

    /**
     * @param array{routing: string, name: string, parent: string, codes: list<string>} $account
     */
    private function openAccount(string $systemKey, array $account): void
    {
        $existing  = $this->db->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
        $accountId = $existing === null ? null : (int) $existing->account_id;

        if ($accountId === null) {
            $parent = $this->db->table('accounting_accounts')->where('code', $account['parent'])->get()->getRow();

            if ($parent === null) {
                log_message('notice', "InvoiceHeaderCharges found no account {$account['parent']}, so it did not open {$account['name']}.");

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
                    'account_type'   => 'revenue',
                    'normal_balance' => 'credit',
                    'is_group'       => 0,
                    'is_system'      => 1,
                    'system_key'     => $systemKey,
                    'active'         => 1,
                ]);

                $accountId = (int) $this->db->insertID();

                break;
            }

            if ($accountId === null) {
                log_message('notice', "InvoiceHeaderCharges found no free code for {$account['name']}.");

                return;
            }
        }

        $route = $this->db->table('accounting_routing')->where('routing_key', $account['routing'])->get()->getRow();

        if ($route === null) {
            $this->db->table('accounting_routing')->insert(['routing_key' => $account['routing'], 'routing_type' => 'auto', 'account_id' => $accountId]);

            return;
        }

        if ($account['routing'] === 'sales.adjustment' && $this->untouchedAdjustment($route)) {
            $this->db->table('accounting_routing')->where('routing_key', $account['routing'])->update(['routing_type' => 'auto', 'account_id' => $accountId]);
        } elseif ($route->account_id === null) {
            $this->db->table('accounting_routing')->where('routing_key', $account['routing'])->update(['routing_type' => 'auto', 'account_id' => $accountId]);
        }
    }

    private function untouchedAdjustment(object $route): bool
    {
        if ($route->routing_type !== 'cancelled') {
            return false;
        }

        if ($route->account_id === null) {
            return true;
        }

        $account = $this->db->table('accounting_accounts')->where('account_id', $route->account_id)->get()->getRow();

        return $account !== null && $account->system_key === 'sales_discount';
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->db->prefixTable($table), $column],
        )->getRow() !== null;
    }

    private function hasTable(string $table): bool
    {
        return $this->db->query(
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->db->prefixTable($table)],
        )->getRow() !== null;
    }
}
