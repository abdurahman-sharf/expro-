<?php

namespace App\Libraries\Accounting;

/**
 * The arithmetic of amounts in a currency: rounding to the decimals the currency is kept in, the
 * conversion between a currency and the base currency at a rate, and the text of an amount. A half
 * rounds away from zero, which is the rule the sales figures already round by.
 */
final class Money
{
    private const WORK_SCALE = 12;

    /**
     * @param numeric-string $amount
     *
     * @return numeric-string
     */
    public static function round(string $amount, int $decimals): string
    {
        $negative  = str_starts_with($amount, '-');
        $magnitude = SaleAmounts::round(SaleAmounts::absolute($amount), $decimals);

        return $negative && bccomp($magnitude, '0', $decimals) !== 0 ? '-' . $magnitude : $magnitude;
    }

    /**
     * What an amount in a currency comes to in the base currency, rounded to the decimals of the base.
     *
     * @param numeric-string $amount
     * @param numeric-string $rate   The base currency per unit of the currency.
     *
     * @return numeric-string
     */
    public static function toBase(string $amount, string $rate, int $baseDecimals): string
    {
        return self::round(bcmul($amount, $rate, self::WORK_SCALE), $baseDecimals);
    }

    /**
     * What an amount in the base currency comes to in a currency, rounded to the decimals of that one.
     *
     * @param numeric-string $amount
     * @param numeric-string $rate
     *
     * @return numeric-string
     */
    public static function fromBase(string $amount, string $rate, int $decimals): string
    {
        return self::round(bcdiv($amount, $rate, self::WORK_SCALE), $decimals);
    }

    /**
     * An amount as plain text with the decimals of its currency, which a form shows and takes back.
     *
     * @param numeric-string $amount
     */
    public static function plain(string $amount, int $decimals): string
    {
        return self::round($amount, $decimals);
    }

    /**
     * An amount with the thousands separator and the decimals of its currency, with the code after it.
     *
     * @param numeric-string $amount
     */
    public static function format(string $amount, int $decimals, ?string $code = null): string
    {
        $rounded            = self::round($amount, $decimals);
        $negative           = str_starts_with($rounded, '-');
        [$whole, $fraction] = array_pad(explode('.', ltrim($rounded, '-'), 2), 2, '');
        $text               = preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $whole);

        return ($negative ? '-' : '') . $text . ($decimals > 0 ? '.' . $fraction : '') . ($code === null ? '' : ' ' . $code);
    }
}
