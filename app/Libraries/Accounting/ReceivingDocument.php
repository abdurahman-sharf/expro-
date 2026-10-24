<?php

namespace App\Libraries\Accounting;

/**
 * A saved purchase, with what posting needs to know about it. A return carries the purchase it
 * takes goods back from, because it can only give back what that purchase brought in.
 */
final readonly class ReceivingDocument
{
    /**
     * @param list<ReceivingLine> $lines
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
    ) {
    }
}
