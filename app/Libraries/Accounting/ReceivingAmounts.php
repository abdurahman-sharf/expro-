<?php

namespace App\Libraries\Accounting;

/**
 * The amounts of a purchase line, by the rules of the purchase screen: the units of the line
 * times the unit price, less a discount that is a percentage of that or a fixed amount taken
 * from every unit (or from the whole line, on a line saved before it was taken from every unit).
 * What the line takes of the discount on the whole purchase comes off after that, and the tax is a
 * percentage of what is left. All amounts are positive: whether the purchase brings goods in or
 * sends them back says which side of the journal they go on.
 */
final class ReceivingAmounts
{
    private const WORK_SCALE = 8;
    public const SCALE       = 4;

    /**
     * @return numeric-string
     */
    public static function units(ReceivingLine $line): string
    {
        return SaleAmounts::absolute($line->units());
    }

    /**
     * @return numeric-string
     */
    public static function gross(ReceivingLine $line): string
    {
        return bcmul(self::units($line), $line->unitPrice, self::WORK_SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function discount(ReceivingLine $line): string
    {
        $amount = match ($line->discountType) {
            PERCENT    => bcmul(self::gross($line), bcdiv($line->discount, '100', self::WORK_SCALE), self::WORK_SCALE),
            FIXED_LINE => $line->discount,
            default    => bcmul(self::units($line), $line->discount, self::WORK_SCALE),
        };

        return SaleAmounts::round($amount, self::SCALE);
    }

    /**
     * What the line costs after its own discount, before what it takes of the discount on the whole
     * purchase and before its tax.
     *
     * @return numeric-string
     */
    public static function lineNet(ReceivingLine $line): string
    {
        $net = bcsub(self::gross($line), self::discount($line), self::WORK_SCALE);

        return bccomp($net, '0', self::WORK_SCALE) < 0 ? '0.0000' : SaleAmounts::round($net, self::SCALE);
    }

    /**
     * What the line takes of the discount on the whole purchase, never more than it costs.
     *
     * @return numeric-string
     */
    public static function invoiceDiscount(ReceivingLine $line): string
    {
        $share = SaleAmounts::round(SaleAmounts::absolute($line->invoiceDiscount), self::SCALE);
        $net   = self::lineNet($line);

        return bccomp($share, $net, self::SCALE) > 0 ? $net : $share;
    }

    /**
     * What the line costs, after its discount and the share it takes of the discount on the whole
     * purchase, and before its tax.
     *
     * @return numeric-string
     */
    public static function net(ReceivingLine $line): string
    {
        return bcsub(self::lineNet($line), self::invoiceDiscount($line), self::SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function tax(ReceivingLine $line): string
    {
        return SaleAmounts::round(bcmul(self::net($line), bcdiv($line->taxPercent, '100', self::WORK_SCALE), self::WORK_SCALE), self::SCALE);
    }

    /**
     * What the lines come to: the cost, the tax on it, and the total the supplier is owed, which is
     * kept to two decimals while the tax is not rounded, as in the reference system.
     *
     * @param list<ReceivingLine> $lines
     * @param int                 $decimals The decimals the total is kept in: two, or those of the currency of a foreign purchase.
     *
     * @return array{net: numeric-string, tax: numeric-string, total: numeric-string}
     */
    public static function totals(array $lines, int $decimals = 2): array
    {
        $net = '0';
        $tax = '0';

        foreach ($lines as $line) {
            $net = bcadd($net, self::net($line), self::SCALE);
            $tax = bcadd($tax, self::tax($line), self::SCALE);
        }

        return ['net' => $net, 'tax' => $tax, 'total' => SaleAmounts::round(bcadd($net, $tax, self::SCALE), $decimals)];
    }
}
