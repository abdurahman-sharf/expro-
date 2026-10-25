<?php

namespace App\Libraries\Accounting;

/**
 * A saved purchase, with what posting needs to know about it. A return carries the purchase it
 * takes goods back from, because it can only give back what that purchase brought in.
 */
final readonly class ReceivingDocument
{
    /**
     * @param list<ReceivingLine>   $lines
     * @param list<SupplierPayment> $payments   The payments made against the purchase after it was saved.
     * @param int|null              $treasuryId The treasury the purchase was paid from at once, when it was.
     */
    public function __construct(
        public int $receivingId,
        public string $date,
        public ReceivingType $type,
        public ?int $supplierId,
        public int $branchId,
        public ?string $paymentType,
        public ?int $returnOfReceivingId,
        public array $lines,
        public ?self $origin = null,
        public array $payments = [],
        public ?int $treasuryId = null,
    ) {
    }
}
