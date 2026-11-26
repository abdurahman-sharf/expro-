<?php

namespace App\Libraries\Accounting;

/**
 * How the journal of an invoice rounds: the tax is kept as
 * it was calculated, the total the party owes is rounded to two decimals, and the revenue is
 * what is left of the total after the tax, so the journal balances without a rounding line.
 *
 * A total that falls exactly between two cents is rounded up.
 */
final class InvoiceRounding
{
    private const SCALE = 4;

    /**
     * @param numeric-string $net      The invoice amount after discounts, before tax.
     * @param numeric-string $tax      The tax as calculated, not rounded.
     * @param int            $decimals The decimals the total is kept in: two, as it always was, or those of the currency of a foreign document.
     *
     * @return array{total: numeric-string, tax: numeric-string, revenue: numeric-string}
     */
    public static function split(string $net, string $tax, int $decimals = 2): array
    {
        $tax     = bcadd($tax, '0', self::SCALE);
        $sum     = bcadd(bcadd($net, '0', self::SCALE), $tax, self::SCALE);
        $total   = $decimals === 2 ? self::roundToCents($sum) : bcadd(SaleAmounts::round($sum, $decimals), '0', self::SCALE);
        $revenue = bcsub($total, $tax, self::SCALE);

        return ['total' => $total, 'tax' => $tax, 'revenue' => $revenue];
    }

    /**
     * @param numeric-string $amount Not negative.
     *
     * @return numeric-string
     */
    public static function roundToCents(string $amount): string
    {
        $cents = bcadd(bcadd($amount, '0.005', 3), '0', 2);

        return bcadd($cents, '0', self::SCALE);
    }
}
