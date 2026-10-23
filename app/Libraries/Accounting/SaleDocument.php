<?php

namespace App\Libraries\Accounting;

/**
 * A saved sale, with what posting needs to know about it. A return carries the sale it was
 * taken back from, because the cost of a returned item is the cost it was sold at.
 */
final readonly class SaleDocument
{
    /**
     * @param list<SaleLine>    $lines
     * @param list<SalePayment> $payments
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
    ) {
    }

    public function isReturn(): bool
    {
        return $this->saleType === SALE_TYPE_RETURN;
    }
}
