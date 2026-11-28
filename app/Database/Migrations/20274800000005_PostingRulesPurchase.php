<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The posting rules of a purchase and of a purchase return, seeded for every company as each is posted
 * today, and each set to be checked beside its code. A purchase is written in its own currency and brought
 * to the base with the rounding on the largest debit that is not the tax, as the code brings it. A return
 * is given in the base already. The stock movements stay with the code. No journal and no balance is
 * touched.
 */
class PostingRulesPurchase extends Migration
{
    private const CREDITOR   = '{"first":[{"when":"has_supplier","role":"purchases.suppliers","entity":"supplier_id"},{"when":"by_treasury","role":"treasuries.accounts","entity":"treasury_id"},{"when":"by_payment","payment":"payment_type"}],"else":"supplier_required"}';
    private const LOCATION   = '{"first":[{"role":"inventory.locations","entity":"location_id"}]}';
    private const OPERATIONS = [
        'purchase' => [
            'header' => ['source_type' => 'purchase', 'description' => 'Purchase #{source_id}', 'cross_branch' => 0, 'foreign_rounding' => '{"balancing":"largest_debit","except":[3]}', 'note' => 'As a purchase was posted when the engine came in.'],
            'lines'  => [
                [1, 'debit', self::LOCATION, 'value', 'locations', null, 'P-28'],
                [2, 'debit', '{"first":[{"role":"purchases.purchases"}]}', 'net', null, null, 'P-30'],
                [3, 'debit', '{"first":[{"role":"tax.input"}]}', 'tax', null, null, 'P-29'],
                [4, 'credit', self::CREDITOR, 'total', null, null, 'P-28'],
            ],
        ],
        'purchase_return' => [
            'header' => ['source_type' => 'purchase', 'description' => 'Purchase return #{source_id}', 'cross_branch' => 0, 'foreign_rounding' => null, 'note' => 'As a purchase return was posted when the engine came in.'],
            'lines'  => [
                [1, 'debit', self::CREDITOR, 'total', null, 'foreign_total', 'P-35'],
                [2, 'credit', '{"first":[{"role":"tax.input"}]}', 'tax', null, 'foreign_tax', 'P-35'],
                [3, 'credit', self::LOCATION, 'value', 'locations', null, 'P-35'],
                [4, 'credit', '{"first":[{"role":"purchases.purchases"}]}', 'net', null, null, 'P-35'],
                [5, 'credit', '{"first":[{"role":"purchases.price_variance"}]}', 'variance', null, null, 'P-35'],
            ],
        ],
    ];

    public function up(): void
    {
        foreach ($this->db->table('companies')->select('company_id')->get()->getResult() as $company) {
            $companyId = (int) $company->company_id;

            foreach (self::OPERATIONS as $operation => ['header' => $header, 'lines' => $lines]) {
                if ($this->db->table('accounting_posting_rule_sets')->where(['company_id' => $companyId, 'operation' => $operation])->countAllResults() === 0) {
                    $this->db->table('accounting_posting_rule_sets')->insert($header + [
                        'company_id'     => $companyId,
                        'operation'      => $operation,
                        'version'        => 1,
                        'effective_from' => '1900-01-01',
                        'entry_type'     => 'general',
                    ]);

                    $setId = (int) $this->db->insertID();

                    foreach ($lines as [$lineNo, $side, $account, $amount, $over, $foreign, $reference]) {
                        $this->db->table('accounting_posting_rules')->insert(['rule_set_id' => $setId, 'line_no' => $lineNo, 'side' => $side, 'account' => $account, 'amount' => $amount, 'repeat_over' => $over, 'foreign_amount' => $foreign, 'matrix_ref' => $reference]);
                    }
                }

                $this->db->table('accounting_posting_modes')->ignore(true)->insert(['company_id' => $companyId, 'operation' => $operation, 'mode' => 'shadow']);
            }
        }
    }

    public function down(): void
    {
        $this->db->table('accounting_posting_modes')->whereIn('operation', array_keys(self::OPERATIONS))->delete();
        $this->db->table('accounting_posting_rule_sets')->whereIn('operation', array_keys(self::OPERATIONS))->delete();
    }
}
