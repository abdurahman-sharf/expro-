<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The posting rules of the money a customer pays: a payment of a sale, the exchange difference it leaves
 * on a foreign sale, and an advance with no invoice, seeded for every company as each is posted today,
 * and each set to be checked beside its code. The account of the customer comes first, because the code
 * asks for it before it looks at how the money was paid. No journal and no balance is touched.
 */
class PostingRulesCustomerPayments extends Migration
{
    private const TREASURY_OR_PAYMENT = '{"first":[{"when":"by_treasury","role":"treasuries.accounts","entity":"treasury_id"},{"when":"by_payment","payment":"payment_type"}]';
    private const RECEIVABLE          = '{"first":[{"when":"has_customer","role":"sales.customers","entity":"customer_id"},{"when":"counter","role":"sales.counter"}]}';
    private const OPERATIONS          = [
        'sale_payment' => [
            'header' => ['source_type' => 'sale_payment', 'description' => 'Payment #{source_id}', 'cross_branch' => 1, 'note' => 'As a payment of a sale was posted when the engine came in.'],
            'lines'  => [
                [1, 'credit', self::RECEIVABLE, 'adjustment + paid', 'amount', 'P-17'],
                [2, 'debit', '{"first":[{"role":"cash.difference"}]}', 'adjustment', null, 'P-16'],
                [3, 'debit', self::TREASURY_OR_PAYMENT . ',"else":"no_journal"}', 'paid', 'amount', 'P-17'],
            ],
        ],
        'sale_payment_fx' => [
            'header' => ['source_type' => 'sale_payment_fx', 'description' => 'Exchange difference of payment #{source_id}', 'cross_branch' => 0, 'note' => 'As the exchange difference of a payment of a sale was posted when the engine came in.'],
            'lines'  => [
                [1, 'credit', self::RECEIVABLE, 'fx_diff', null, 'P-21'],
                [2, 'debit', '{"first":[{"when":"is_loss","role":"fx.loss"},{"when":"is_gain","role":"fx.gain"}],"else":"fx_accounts_missing"}', 'fx_diff', null, 'P-21'],
            ],
        ],
        'customer_advance' => [
            'header' => ['source_type' => 'customer_advance', 'description' => 'Advance payment #{source_id}', 'cross_branch' => 0, 'note' => 'As an advance from a customer was posted when the engine came in.'],
            'lines'  => [
                [1, 'debit', self::TREASURY_OR_PAYMENT . ',"else":"payment_type_unknown"}', 'amount', null, 'P-18'],
                [2, 'credit', '{"first":[{"role":"sales.customers","entity":"party_id"}],"else":"entity_account_missing"}', 'amount', null, 'P-18'],
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

                    foreach ($lines as [$lineNo, $side, $account, $amount, $foreign, $reference]) {
                        $this->db->table('accounting_posting_rules')->insert(['rule_set_id' => $setId, 'line_no' => $lineNo, 'side' => $side, 'account' => $account, 'amount' => $amount, 'foreign_amount' => $foreign, 'matrix_ref' => $reference]);
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
