<?php

namespace App\Libraries\Stock;

/**
 * A line of a stock permit as posting reads it: the item, the units, the cost typed on an add permit
 * and the cost the item carries, which is what stock is worth when the books cannot tell.
 */
final readonly class StockPermitLine
{
    public function __construct(
        public int $itemId,
        public int $line,
        public string $quantity,
        public string $unitCost,
        public string $itemCost,
    ) {
    }
}
