<?php

namespace App\Libraries\Accounting;

/**
 * A line of a saved sale as posting reads it. Quantities and amounts are strings so nothing is
 * rounded before posting does it.
 */
final readonly class SaleLine
{
    public function __construct(
        public int $itemId,
        public int $line,
        public string $quantity,
        public string $unitPrice,
        public string $discount,
        public int $discountType,
        public string $costPrice,
        public int $locationId,
        public bool $hasStock,
        public string $includedTax = '0',
        public string $addedTax = '0',
    ) {
    }
}
