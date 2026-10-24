<?php

namespace App\Libraries\Accounting;

/**
 * One thing a person has to look at. The amount is a value for a problem of the stock location
 * and a quantity for a problem of an item.
 */
final readonly class OpeningStockFinding
{
    public function __construct(
        public OpeningStockProblem $problem,
        public string $amount,
        public ?int $itemId = null,
        public ?string $itemName = null,
    ) {
    }
}
