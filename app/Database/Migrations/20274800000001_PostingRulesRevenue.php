<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The posting rules of the receipt voucher, seeded for every company as the voucher is posted today,
 * and the voucher set to be checked beside its code. No journal and no balance is touched.
 */
class PostingRulesRevenue extends Migration
{
    private const OPERATION = 'revenue';
    private const LINES     = [
        [1, 'debit', '{"first":[{"when":"has_treasury","role":"treasuries.accounts","entity":"treasury_id"},{"when":"no_treasury","payment":"payment_type"}],"else":"payment_type_unknown"}', 'gross', 'P-47'],
        [2, 'credit', '{"first":[{"field":"account_id"},{"field":"category_account_id"},{"role":"revenues.default"}]}', 'net', 'P-47'],
        [3, 'credit', '{"first":[{"role":"tax.output"}]}', 'tax', 'P-47'],
    ];

    public function up(): void
    {
        foreach ($this->db->table('companies')->select('company_id')->get()->getResult() as $company) {
            $companyId = (int) $company->company_id;

            if ($this->db->table('accounting_posting_rule_sets')->where(['company_id' => $companyId, 'operation' => self::OPERATION])->countAllResults() === 0) {
                $this->db->table('accounting_posting_rule_sets')->insert([
                    'company_id'     => $companyId,
                    'operation'      => self::OPERATION,
                    'version'        => 1,
                    'effective_from' => '1900-01-01',
                    'source_type'    => 'revenue',
                    'entry_type'     => 'general',
                    'description'    => 'Receipt voucher #{source_id}',
                    'cross_branch'   => 0,
                    'note'           => 'As the receipt voucher was posted when the engine came in.',
                ]);

                $setId = (int) $this->db->insertID();

                foreach (self::LINES as [$lineNo, $side, $account, $amount, $reference]) {
                    $this->db->table('accounting_posting_rules')->insert(['rule_set_id' => $setId, 'line_no' => $lineNo, 'side' => $side, 'account' => $account, 'amount' => $amount, 'matrix_ref' => $reference]);
                }
            }

            $this->db->table('accounting_posting_modes')->ignore(true)->insert(['company_id' => $companyId, 'operation' => self::OPERATION, 'mode' => 'shadow']);
        }
    }

    public function down(): void
    {
        $this->db->table('accounting_posting_modes')->where('operation', self::OPERATION)->delete();
        $this->db->table('accounting_posting_rule_sets')->where('operation', self::OPERATION)->delete();
    }
}
