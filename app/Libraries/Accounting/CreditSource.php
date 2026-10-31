<?php

namespace App\Libraries\Accounting;

/**
 * Credit a customer or a supplier holds that no journal of their own spends: a credit note or a
 * debit note (what it came to less what was paid back), what a payment came to beyond its
 * invoice, a payment to a supplier that belongs to no purchase, or a cheque received from a customer
 * or delivered to a supplier. Applying it to an invoice
 * writes no journal, because the account of the customer or of the supplier already carries it.
 */
final readonly class CreditSource
{
    public const CREDIT_NOTE    = 'credit_note';
    public const DEBIT_NOTE     = 'debit_note';
    public const INVOICE_EXCESS = 'invoice_excess';
    public const ADVANCE        = 'supplier_advance';
    public const CHEQUE         = 'cheque';

    public function __construct(
        public string $type,
        public int $sourceId,
        public ?int $originId,
        public string $date,
        public string $amount,
        public string $applied,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function available(): string
    {
        $left = bcsub($this->amount, $this->applied, SaleAmounts::SCALE);

        return bccomp($left, '0', SaleAmounts::SCALE) > 0 ? $left : '0.0000';
    }
}
