<?php

namespace App\Libraries\Accounting;

/**
 * A purchase of a supplier as the books hold it: what it came to, what was paid into it, what
 * credit was applied to it and what was taken back by debit notes. Every amount is read from
 * journals that stand, so it cannot disagree with the account of the supplier. An opening
 * balance owed to the supplier is a purchase without lines and is read the same way.
 */
final readonly class PurchaseFigures
{
    public function __construct(
        public int $receivingId,
        public string $date,
        public string $total,
        public string $paid,
        public string $applied,
        public string $returned,
        public bool $opening = false,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function settled(): string
    {
        return bcadd($this->paid, $this->applied, SaleAmounts::SCALE);
    }

    /**
     * What is still owed on it.
     *
     * @return numeric-string
     */
    public function open(): string
    {
        $left = bcsub($this->total, $this->settled(), SaleAmounts::SCALE);

        return bccomp($left, '0', SaleAmounts::SCALE) > 0 ? $left : '0.0000';
    }

    /**
     * What was paid beyond what it came to.
     *
     * @return numeric-string
     */
    public function excess(): string
    {
        $beyond = bcsub($this->settled(), $this->total, SaleAmounts::SCALE);

        return bccomp($beyond, '0', SaleAmounts::SCALE) > 0 ? $beyond : '0.0000';
    }

    public function paymentState(): PaymentState
    {
        if (bccomp($this->settled(), '0', SaleAmounts::SCALE) <= 0) {
            return PaymentState::Unpaid;
        }

        return match (bccomp($this->settled(), $this->total, SaleAmounts::SCALE)) {
            -1      => PaymentState::Partial,
            0       => PaymentState::Paid,
            default => PaymentState::Excess,
        };
    }

    public function returnState(): ReturnState
    {
        if (bccomp($this->returned, '0', SaleAmounts::SCALE) <= 0) {
            return ReturnState::None;
        }

        return bccomp($this->returned, $this->total, SaleAmounts::SCALE) >= 0 ? ReturnState::Full : ReturnState::Partial;
    }
}
