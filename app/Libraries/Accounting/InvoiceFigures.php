<?php

namespace App\Libraries\Accounting;

/**
 * An invoice of a customer as the books hold it: what it came to, what was paid into it, what
 * credit was applied to it and what was taken back by credit notes. Every amount is read from
 * journals that stand, so it cannot disagree with the account of the customer. It has the day it
 * is dated, which is where its age counts from, and the day it falls due, which is its own day
 * when it has no payment terms. An opening balance the customer owes is read the same way, as an
 * invoice without lines: it has no sale, so its number is minus the number of the opening balance. A sales
 * debit note is read the same way, numbered in its own range past the openings (SalesDebitNotes::keyOf).
 */
final readonly class InvoiceFigures
{
    public function __construct(
        public int $saleId,
        public string $date,
        public int $saleType,
        public string $total,
        public string $paid,
        public string $applied,
        public string $returned,
        public string $dueDate,
        public ?int $termsDays = null,
        public bool $opening = false,
        public bool $debitNote = false,
    ) {
    }

    public function isOverdue(string $today): bool
    {
        return InvoiceTiming::isOverdue($this->dueDate, $today, bccomp($this->open(), '0', SaleAmounts::SCALE) > 0);
    }

    public function daysOverdue(string $today): int
    {
        return $this->isOverdue($today) ? AgingBuckets::ageInDays($this->dueDate, $today) : 0;
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
