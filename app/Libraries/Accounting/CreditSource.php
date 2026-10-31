<?php

namespace App\Libraries\Accounting;

/**
 * Credit a customer or a supplier holds that no journal of their own spends: a credit note or a
 * debit note (what it came to less what was paid back), what a payment came to beyond its
 * invoice, a payment that belongs to no invoice or to no purchase, what a customer held with us
 * before the books began, or the advance of a customer. Applying it to an invoice writes no journal,
 * because the account of the customer or of the supplier already carries it. Paying it back in cash
 * writes a journal of its own and takes it out of what is available, as applying it to an invoice does.
 */
final readonly class CreditSource
{
    public const CREDIT_NOTE      = 'credit_note';
    public const DEBIT_NOTE       = 'debit_note';
    public const INVOICE_EXCESS   = 'invoice_excess';
    public const ADVANCE          = 'supplier_advance';
    public const CUSTOMER_ADVANCE = 'customer_advance';
    public const OPENING          = 'opening_credit';

    public function __construct(
        public string $type,
        public int $sourceId,
        public ?int $originId,
        public string $date,
        public string $amount,
        public string $applied,
        public string $refunded = '0.0000',
    ) {
    }

    /**
     * @return numeric-string
     */
    public function available(): string
    {
        $left = bcsub(bcsub($this->amount, $this->applied, SaleAmounts::SCALE), $this->refunded, SaleAmounts::SCALE);

        return bccomp($left, '0', SaleAmounts::SCALE) > 0 ? $left : '0.0000';
    }
}
