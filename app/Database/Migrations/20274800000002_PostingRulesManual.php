<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The posting rules of the manual journal, seeded for every company as a manual journal is posted
 * today: each typed line on its account and cost center, and right after a line that carries a tax
 * a line for the tax on its side, on the tax paid for a debit and the tax collected for a credit.
 * The manual journal is set to be checked beside its code. No journal and no balance is touched.
 */
class PostingRulesManual extends Migration
{
    private const OPERATION = 'manual';
    private const LINES     = [
        [1, 'debit', '{"first":[{"field":"account_id"}]}', 'net', 'lines', '{"field":"cost_center_id"}', 'P-04'],
        [2, 'debit', '{"first":[{"when":"on_debit","role":"tax.input"},{"when":"on_credit","role":"tax.output"}]}', 'tax', 'lines', null, 'P-05'],
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
                    'source_type'    => 'manual',
                    'entry_type'     => 'general',
                    'description'    => '',
                    'cross_branch'   => 0,
                    'note'           => 'As a manual journal was posted when the engine came in.',
                ]);

                $setId = (int) $this->db->insertID();

                foreach (self::LINES as [$lineNo, $side, $account, $amount, $over, $center, $reference]) {
                    $this->db->table('accounting_posting_rules')->insert(['rule_set_id' => $setId, 'line_no' => $lineNo, 'side' => $side, 'account' => $account, 'amount' => $amount, 'repeat_over' => $over, 'cost_center' => $center, 'matrix_ref' => $reference]);
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
