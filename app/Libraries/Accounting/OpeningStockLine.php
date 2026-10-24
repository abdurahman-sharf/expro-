<?php

namespace App\Libraries\Accounting;

/**
 * Units of one item that stand on the shelves and that no movement accounts for, at the cost
 * the item carries.
 */
final readonly class OpeningStockLine
{
    public function __construct(
        public int $itemId,
        public string $itemName,
        public string $units,
        public string $unitCost,
        public string $value,
    ) {
    }
}
