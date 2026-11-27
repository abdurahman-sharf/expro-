<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * The posting rules of the money paid to a supplier: what a purchase was paid when it was saved, a payment
 * made against a purchase later, the exchange difference it leaves on a foreign purchase, and an advance
 * with no purchase, seeded for every company as each is posted today, and each set to be checked beside its
 * code. The account the money goes through comes first where the code asks for it before the account of
 * the supplier. No journal and no balance is touched.
 */
class PostingRulesSupplierPayments extends Migration
{
    private const TREASURY_OR_PAYMENT = '{"first":[{"when":"by_treasury","role":"treasuries.accounts","entity":"treasury_id"},{"when":"by_payment","payment":"payment_type"}]';
    private const SUPPLIER            = '{"first":[{"role":"purchases.suppliers","entity":"supplier_id"}]}';
    private const OPERATIONS          = [
        'purchase_payment' => [
            'header' => ['source_type' => 'purchase_payment', 'description' => 'Payment of purchase #{source_id}', 'cross_branch' => 0, 'note' => 'As what a purchase was paid when it was saved was posted when the engine came in.'],
            'lines'  => [
                [1, 'credit', self::TREASURY_OR_PAYMENT . ',"else":"no_journal"}', 'paid', 'amount', 'P-33'],
                [2, 'debit', self::SUPPLIER, 'paid', 'amount', 'P-33'],
            ],
        ],
        'supplier_payment' => [
            'header' => ['source_type' => 'supplier_payment', 'description' => 'Payment #{source_id}', 'cross_branch' => 1, 'note' => 'As a payment against a purchase was posted when the engine came in.'],
            'lines'  => [
                [1, 'credit', self::TREASURY_OR_PAYMENT . ',"else":"payment_type_unknown"}', 'paid', 'amount', 'P-34'],
                [2, 'debit', self::SUPPLIER, 'paid', 'amount', 'P-34'],
            ],
        ],
        'supplier_payment_fx' => [
            'header' => ['source_type' => 'supplier_payment_fx', 'description' => 'Exchange difference of payment #{source_id}', 'cross_branch' => 0, 'note' => 'As the exchange difference of a payment against a purchase was posted when the engine came in.'],
            'lines'  => [
                [1, 'debit', self::SUPPLIER, 'fx_diff', null, 'P-21'],
                [2, 'credit', '{"first":[{"when":"is_gain","role":"fx.gain"},{"when":"is_loss","role":"fx.loss"}],"else":"fx_accounts_missing"}', 'fx_diff', null, 'P-21'],
            ],
        ],
        'supplier_advance' => [
            'header' => ['source_type' => 'supplier_advance', 'description' => 'Payment to a supplier #{source_id}', 'cross_branch' => 0, 'note' => 'As a payment to a supplier without a purchase was posted when the engine came in.'],
            'lines'  => [
                [1, 'credit', self::TREASURY_OR_PAYMENT . ',"else":"payment_type_unknown"}', 'amount', null, 'P-34'],
                [2, 'debit', '{"first":[{"role":"purchases.suppliers","entity":"party_id"}],"else":"entity_account_missing"}', 'amount', null, 'P-34'],
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
