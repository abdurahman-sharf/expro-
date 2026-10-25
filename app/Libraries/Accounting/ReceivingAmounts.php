<?php

namespace App\Libraries\Accounting;

/**
 * The amounts of a purchase line, by the rules of the purchase screen: the units of the line
 * times the unit price, less a discount that is a percentage of that or a fixed amount taken
 * from every unit. The tax is a percentage of what is left. All amounts are positive: whether
 * the purchase brings goods in or sends them back says which side of the journal they go on.
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
        $amount = $line->discountType === PERCENT
            ? bcmul(self::gross($line), bcdiv($line->discount, '100', self::WORK_SCALE), self::WORK_SCALE)
            : bcmul(self::units($line), $line->discount, self::WORK_SCALE);

        return SaleAmounts::round($amount, self::SCALE);
    }

    /**
     * What the line costs, after its discount and before its tax.
     *
     * @return numeric-string
     */
    public static function net(ReceivingLine $line): string
    {
        $net = bcsub(self::gross($line), self::discount($line), self::WORK_SCALE);

        return bccomp($net, '0', self::WORK_SCALE) < 0 ? '0.0000' : SaleAmounts::round($net, self::SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function tax(ReceivingLine $line): string
    {
        return SaleAmounts::round(bcmul(self::net($line), bcdiv($line->taxPercent, '100', self::WORK_SCALE), self::WORK_SCALE), self::SCALE);
    }
}
