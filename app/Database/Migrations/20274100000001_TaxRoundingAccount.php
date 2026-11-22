<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The account the rounding of the VAT of a sale goes to under the Saudi rules: the VAT of each category is
 * rounded on its taxable amount (BR-CO-17) while the customer pays what the register charged, so the two can part
 * by a few halalas, which are posted here and printed on the electronic document as its rounding amount (BT-114)
 * rather than hidden in the revenue. Opened under other revenues in the chart of a company that already has one,
 * with its routing row. Only an account and a routing row are added: no journal, no document and no balance is
 * touched. Safe to run again.
 */
class TaxRoundingAccount extends Migration
{
    private const ACCOUNTS = [
        'tax_rounding' => ['routing' => 'tax.rounding', 'name' => 'فروق تقريب ضريبية', 'type' => 'revenue', 'normal' => 'credit', 'parent' => '42', 'codes' => ['4213', '4214', '4215', '4216']],
    ];

    public function up(): void
    {
        if (! $this->hasTable('accounting_accounts') || $this->db->table('accounting_accounts')->countAllResults() === 0) {
            return;
        }

        foreach (self::ACCOUNTS as $systemKey => $account) {
            $this->openAccount($systemKey, $account);
        }
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
                log_message('notice', "TaxRoundingAccount found no account {$account['parent']}, so it did not open {$account['name']}.");

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
                log_message('notice', "TaxRoundingAccount found no free code for {$account['name']}.");

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
