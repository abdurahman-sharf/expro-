<?php

namespace App\Libraries\Accounting;

/**
 * Splits the goods of an invoice over the accounts its items name, so that the parts add up to the
 * goods of the journal to the last digit. Each part is the share of its account rounded to the scale
 * amounts are kept at, and what the rounding leaves, together with the rounding of the cent the invoice
 * total carries, falls on the largest part, as the rounding of a purchase does.
 */
final class GoodsSplit
{
    private const SHARE_SCALE = 8;

    /**
     * @param array<string, numeric-string> $shares What each part comes to before rounding, by key, in the order the parts are written.
     * @param numeric-string                $total  What the parts must add up to, which is not negative.
     *
     * @return array<string, numeric-string>|null The parts by key, or null when the largest part cannot carry what is left.
     */
    public static function parts(array $shares, string $total): ?array
    {
        if ($shares === []) {
            return [];
        }

        $parts = [];
        $sum   = '0';

        foreach ($shares as $key => $share) {
            $parts[$key] = SaleAmounts::round(bccomp($share, '0', self::SHARE_SCALE) > 0 ? $share : '0', SaleAmounts::SCALE);
            $sum         = bcadd($sum, $parts[$key], SaleAmounts::SCALE);
        }

        $largest = array_key_first($parts);

        foreach ($parts as $key => $part) {
            if (bccomp($part, $parts[$largest], SaleAmounts::SCALE) > 0) {
                $largest = $key;
            }
        }

        $parts[$largest] = bcadd($parts[$largest], bcsub($total, $sum, SaleAmounts::SCALE), SaleAmounts::SCALE);

        return bccomp($parts[$largest], '0', SaleAmounts::SCALE) < 0 ? null : $parts;
    }
}
