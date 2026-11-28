<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The posting rules of a sale and of the cost of its goods, seeded for every company as each is posted
 * today, and each set to be checked beside its code. A sale is written in its own currency and brought to
 * the base with the rounding on the first largest part of the goods, or on the shipping when there are no
 * goods, as the code brings it. The cost of goods that cost nothing posts no journal, and a cost of sales
 * row that names no account leaves the cost on the account the chart opened for it. The stock movements and
 * the returns of a sale stay with the code. No journal and no balance is touched.
 */
class PostingRulesSale extends Migration
{
    private const OPERATIONS = [
        'sale' => [
            'header' => ['source_type' => 'sale', 'entry_type' => 'revenue', 'description' => 'Sale #{source_id}', 'foreign_rounding' => '{"balancing":"largest_of","lines":[3],"else":[5]}', 'note' => 'As a sale was posted when the engine came in.'],
            'lines'  => [
                [1, 'debit', '{"first":[{"when":"has_customer","role":"sales.customers","entity":"customer_id"},{"when":"counter","role":"sales.counter"}]}', 'total', null, 'P-07'],
                [2, 'debit', '{"first":[{"role":"sales.discount"}]}', 'discount', null, 'P-11'],
                [3, 'credit', '{"first":[{"field":"item_account_id"},{"role":"sales.revenue"}]}', 'value', 'goods', 'P-07'],
                [4, 'credit', '{"first":[{"field":"account_id"},{"role":"tax.output"}]}', 'value', 'taxes', 'P-08'],
                [5, 'credit', '{"first":[{"field":"shipping_account_id"},{"role":"sales.shipping"}]}', 'shipping', null, 'P-13'],
                [6, 'credit', '{"first":[{"role":"sales.adjustment"}]}', 'adjustment', null, 'P-12'],
                [7, 'credit', '{"first":[{"role":"tax.rounding"}]}', 'rounding', null, 'P-14'],
            ],
        ],
        'sale_cost' => [
            'header' => ['source_type' => 'sale', 'entry_type' => 'cogs', 'description' => 'Cost of sale #{source_id}', 'foreign_rounding' => null, 'note' => 'As the cost of a sale was posted when the engine came in.'],
            'lines'  => [
                [1, 'debit', '{"first":[{"role":"sales.cogs"},{"system":"cogs"}]}', 'cost', null, 'P-10'],
                [2, 'credit', '{"first":[{"role":"inventory.locations","entity":"location_id"}]}', 'value', 'locations', 'P-10'],
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
                        'cross_branch'   => 0,
                    ]);

                    $setId = (int) $this->db->insertID();

                    foreach ($lines as [$lineNo, $side, $account, $amount, $over, $reference]) {
                        $this->db->table('accounting_posting_rules')->insert(['rule_set_id' => $setId, 'line_no' => $lineNo, 'side' => $side, 'account' => $account, 'amount' => $amount, 'repeat_over' => $over, 'matrix_ref' => $reference]);
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
