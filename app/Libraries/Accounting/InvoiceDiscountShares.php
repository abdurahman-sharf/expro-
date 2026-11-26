<?php

namespace App\Libraries\Accounting;

/**
 * Splits the discount on the whole of a purchase over its lines, so that what each line costs, the
 * tax on it and the cost the stock is held at all come after the discount, and the lines add up to
 * what the supplier is owed. The rule: each line takes a share proportional to what it comes to after
 * its own discount and before its tax, rounded to the cent, and the last line that comes to
 * something takes what is left, so the shares add up to the discount exactly. A line never takes more
 * than it comes to.
 */
final class InvoiceDiscountShares
{
    private const SCALE      = ReceivingAmounts::SCALE;
    public const SHARE_SCALE = InvoiceDiscount::AMOUNT_SCALE;

    /**
     * What each line takes of the discount, in the order of the lines.
     *
     * @param list<ReceivingLine> $lines
     *
     * @return list<numeric-string>
     */
    public static function forPurchase(array $lines, InvoiceDiscount $discount): array
    {
        $nets = array_map(static fn (ReceivingLine $line): string => ReceivingAmounts::lineNet($line), $lines);

        return self::split($nets, $discount->amountOn(self::sum($nets)));
    }

    /**
     * @param list<numeric-string> $nets
     * @param numeric-string       $amount
     *
     * @return list<numeric-string>
     */
    public static function split(array $nets, string $amount): array
    {
        $shares = array_fill(0, count($nets), '0.0000');
        $total  = self::sum($nets);

        if ($nets === [] || bccomp($amount, '0', self::SCALE) <= 0 || bccomp($total, '0', self::SCALE) <= 0) {
            return $shares;
        }

        if (bccomp($amount, $total, self::SCALE) >= 0) {
            return array_map(static fn (string $net): string => bcadd($net, '0', self::SCALE), $nets);
        }

        $last  = null;
        $taken = '0';

        foreach ($nets as $index => $net) {
            if (bccomp($net, '0', self::SCALE) > 0) {
                $last = $index;
            }
        }

        foreach ($nets as $index => $net) {
            if (bccomp($net, '0', self::SCALE) <= 0) {
                continue;
            }

            $shares[$index] = $index === $last
                ? bcsub($amount, $taken, self::SCALE)
                : SaleAmounts::round(bcmul($amount, bcdiv($net, $total, 12), 12), self::SHARE_SCALE);
            $taken = bcadd($taken, $shares[$index], self::SCALE);
        }

        return array_map(static fn (string $share): string => bcadd($share, '0', self::SCALE), self::within($shares, $nets, (int) $last));
    }

    /**
     * Keeps every share between nothing and what its line comes to, handing what the last line cannot
     * take, or has to give back, to the lines before it.
     *
     * @param list<numeric-string> $shares
     * @param list<numeric-string> $nets
     *
     * @return list<numeric-string>
     */
    private static function within(array $shares, array $nets, int $last): array
    {
        if (bccomp($shares[$last], '0', self::SCALE) < 0) {
            $owed          = SaleAmounts::absolute($shares[$last]);
            $shares[$last] = '0.0000';

            for ($index = $last - 1; $index >= 0 && bccomp($owed, '0', self::SCALE) > 0; $index--) {
                $give           = bccomp($shares[$index], $owed, self::SCALE) < 0 ? $shares[$index] : $owed;
                $shares[$index] = bcsub($shares[$index], $give, self::SCALE);
                $owed           = bcsub($owed, $give, self::SCALE);
            }

            return $shares;
        }

        if (bccomp($shares[$last], $nets[$last], self::SCALE) > 0) {
            $extra         = bcsub($shares[$last], $nets[$last], self::SCALE);
            $shares[$last] = $nets[$last];

            for ($index = $last - 1; $index >= 0 && bccomp($extra, '0', self::SCALE) > 0; $index--) {
                $room           = bcsub($nets[$index], $shares[$index], self::SCALE);
                $take           = bccomp($room, $extra, self::SCALE) < 0 ? $room : $extra;
                $shares[$index] = bcadd($shares[$index], $take, self::SCALE);
                $extra          = bcsub($extra, $take, self::SCALE);
            }
        }

        return $shares;
    }

    /**
     * @param list<numeric-string> $amounts
     *
     * @return numeric-string
     */
    private static function sum(array $amounts): string
    {
        $sum = '0';

        foreach ($amounts as $amount) {
            $sum = bcadd($sum, $amount, self::SCALE);
        }

        return bcadd($sum, '0', self::SCALE);
    }
}
