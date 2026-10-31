<?php

namespace App\Libraries\Accounting;

final readonly class OpeningStockRepriceLine
{
    public function __construct(
        public int $itemId,
        public string $itemName,
        public string $units,
        public string $costBefore,
        public string $costAfter,
        public string $valueBefore,
        public string $valueAfter,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function change(): string
    {
        return bcsub($this->valueAfter, $this->valueBefore, StockLedger::VALUE_SCALE);
    }
}
