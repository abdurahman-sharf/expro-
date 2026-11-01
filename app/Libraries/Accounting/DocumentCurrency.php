<?php

namespace App\Libraries\Accounting;

/**
 * The currency a document is in and the rate it was captured at: the base currency is worth the rate
 * of this one. A document in the base currency has none, so a document without one is in the base
 * currency, and the books of such a document do not change.
 */
final readonly class DocumentCurrency
{
    /**
     * @param numeric-string $rate
     */
    public function __construct(
        public string $code,
        public string $rate,
        public int $decimals,
    ) {
    }

    /**
     * @param numeric-string $amount
     *
     * @return numeric-string
     */
    public function toBase(string $amount, int $baseDecimals): string
    {
        return Money::toBase($amount, $this->rate, $baseDecimals);
    }
}
