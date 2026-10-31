<?php

namespace App\Libraries\Accounting;

/**
 * A saved sale, with what posting needs to know about it. Its date is the day its journals take:
 * the date typed on the invoice, or else the day it was saved. A return carries the sale it was
 * taken back from, because the cost of a returned item is the cost it was sold at, unless a manager
 * let it through with no sale to take it back from, which is then costed at the cost of the day.
 * The units of an invoice that came back before its goods were delivered are kept apart, because
 * the delivery takes out only what stayed sold.
 */
final readonly class SaleDocument
{
    /**
     * @param list<SaleLine>        $lines
     * @param list<SalePayment>     $payments
     * @param array<int, string>    $returnedUnits Units by item that came back before the goods were delivered.
     */
    public function __construct(
        public int $saleId,
        public string $date,
        public int $saleType,
        public int $status,
        public ?int $customerId,
        public int $branchId,
        public bool $delivered,
        public ?int $returnOfSaleId,
        public array $lines,
        public array $payments,
        public ?self $origin = null,
        public ?string $savedOn = null,
        public bool $returnOverride = false,
        public bool $refundInvoice = false,
        public array $returnedUnits = [],
    ) {
    }

    /**
     * @param array<int, string> $units
     */
    public function withReturnedUnits(array $units): self
    {
        return new self(
            $this->saleId,
            $this->date,
            $this->saleType,
            $this->status,
            $this->customerId,
            $this->branchId,
            $this->delivered,
            $this->returnOfSaleId,
            $this->lines,
            $this->payments,
            $this->origin,
            $this->savedOn,
            $this->returnOverride,
            $this->refundInvoice,
            $units,
        );
    }

    public function isReturn(): bool
    {
        return $this->saleType === SALE_TYPE_RETURN;
    }
}
