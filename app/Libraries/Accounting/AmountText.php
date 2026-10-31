<?php

namespace App\Libraries\Accounting;

/**
 * An amount as a form field holds it. The books keep four decimals, so a field that showed only two
 * would hand back a different amount when the form was saved again.
 */
final class AmountText
{
    private const MIN_DECIMALS = 2;

    /**
     * @param numeric-string $amount Not negative.
     */
    public static function forField(string $amount): string
    {
        [$whole, $fraction] = explode('.', bcadd($amount, '0', SaleAmounts::SCALE));

        return $whole . '.' . str_pad(rtrim($fraction, '0'), self::MIN_DECIMALS, '0');
    }
}
