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

    /**
     * Whether a change asked of a document leaves its currency and its rate as they are: what the change does
     * not name stays what it was.
     *
     * @param array<string, ?string> $changes The currency_code and the currency_rate asked for, when they are.
     */
    public static function unchanged(?self $stored, array $changes): bool
    {
        $code = array_key_exists('currency_code', $changes) ? $changes['currency_code'] : $stored?->code;
        $rate = array_key_exists('currency_rate', $changes) ? $changes['currency_rate'] : $stored?->rate;

        if ($stored === null) {
            return $code === null && $rate === null;
        }

        return $code === $stored->code && $rate !== null && bccomp($rate, $stored->rate, 8) === 0;
    }
}
