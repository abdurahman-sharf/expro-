<?php

namespace App\Libraries\Accounting;

/**
 * Stock of one item that came into a stock location (a positive quantity) or left it (a negative
 * one), with what it was worth when it did. The value has the sign of the quantity, and may be
 * nothing: goods that cost nothing are still goods.
 */
final readonly class StockMovement
{
    public function __construct(
        public int $itemId,
        public int $locationId,
        public string $quantity,
        public string $value,
    ) {
    }
}
