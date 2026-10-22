<?php

namespace App\Libraries\Accounting;

/**
 * How the journal of an invoice rounds, copied from the reference system: the tax is kept as
 * it was calculated, the total the party owes is rounded to two decimals, and the revenue is
 * what is left of the total after the tax, so the journal balances without a rounding line.
 *
 * When the total falls exactly between two cents the reference system rounds in a direction no
 * formula we tried reproduces: four of the six recorded cases went up and two went down. This
 * rounds those up, and so may differ from it by one cent there and nowhere else.
 */
final class InvoiceRounding
{
    private const SCALE = 4;

    /**
     * @param numeric-string $net The invoice amount after discounts, before tax.
     * @param numeric-string $tax The tax as calculated, not rounded.
     *
     * @return array{total: numeric-string, tax: numeric-string, revenue: numeric-string}
     */
    public static function split(string $net, string $tax): array
    {
        $tax     = bcadd($tax, '0', self::SCALE);
        $total   = self::roundToCents(bcadd(bcadd($net, '0', self::SCALE), $tax, self::SCALE));
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
