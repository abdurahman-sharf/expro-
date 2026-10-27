<?php

namespace App\Libraries;

use App\Libraries\Accounting\SaleAmounts;

/**
 * The discount on the whole of a sale, taken after the discounts of its lines. It is a percentage of
 * what the lines come to or an amount, rounded to the decimals the shop works in, and it is shared
 * out over the lines in proportion to what each comes to, with what rounding leaves over on the last
 * line that carries any. Every figure that depends on it (the tax of each line, the journal of the
 * invoice, the reports) reads these shares and never works the discount out again, so they cannot
 * disagree. A return takes back the share of what it returns, and the last return of an item takes
 * all that is left of it, so the returns of a sale give back exactly what it was given.
 */
final class InvoiceDiscount
{
    public const SCALE  = 4;
    private const WORK  = 8;
    private const UNITS = 3;

    public static function problem(int $type, string $value, string $base, int $decimals): ?InvoiceDiscountProblem
    {
        if ($type !== PERCENT && $type !== FIXED) {
            return InvoiceDiscountProblem::UnknownType;
        }

        if (preg_match('/^-?\d+(\.\d+)?$/', $value) !== 1) {
            return InvoiceDiscountProblem::NotANumber;
        }

        if (bccomp($value, '0', self::WORK) < 0) {
            return InvoiceDiscountProblem::Negative;
        }

        if ($type === PERCENT && bccomp($value, '100', self::WORK) > 0) {
            return InvoiceDiscountProblem::PercentOver100;
        }

        return bccomp(self::amount($type, $value, $base, $decimals), $base, self::SCALE) > 0 ? InvoiceDiscountProblem::ExceedsTotal : null;
    }

    /**
     * What the discount comes to on lines that come to the base, never more than the base.
     *
     * @return numeric-string
     */
    public static function amount(int $type, string $value, string $base, int $decimals): string
    {
        $amount = $type === PERCENT
            ? bcmul($base, bcdiv($value, '100', self::WORK), self::WORK)
            : $value;

        return bcadd(SaleAmounts::round($amount, $decimals), '0', self::SCALE);
    }

    /**
     * What a line sells for after its own discount, or nothing when it is not a sale of something
     * that has a price.
     *
     * @return numeric-string
     */
    public static function lineBase(string $quantity, string $price, string $discount, int $discountType, int $decimals): string
    {
        if (bccomp($quantity, '0', self::WORK) <= 0 || bccomp($price, '0', self::WORK) <= 0) {
            return '0.0000';
        }

        $gross = bcmul($quantity, $price, self::WORK);
        $off   = $discountType === PERCENT
            ? bcmul($gross, bcdiv($discount, '100', self::WORK), self::WORK)
            : bcmul($quantity, $discount, self::WORK);
        $base = bcsub($gross, SaleAmounts::round($off, $decimals), self::SCALE);

        return bccomp($base, '0', self::SCALE) > 0 ? $base : '0.0000';
    }

    /**
     * The share of the discount each line carries: in proportion to what the line comes to, the rest
     * on the last line that comes to anything, and nothing on a line that comes to nothing.
     *
     * @param array<int|string, string> $bases
     *
     * @return array<int|string, string>
     */
    public static function allocate(array $bases, string $amount): array
    {
        $open = array_keys(array_filter($bases, static fn (string $base): bool => bccomp($base, '0', self::SCALE) > 0));

        return $open === [] ? self::proportional($bases, '0.0000') : self::keptWithin(self::proportional($bases, $amount), $bases, $open);
    }

    /**
     * The share each line of a return takes back, from what the sale it comes from was given and what
     * earlier returns of it took back. Units that leave nothing of an item still to come back take
     * all its share that is left. Lines of an item the sale never had take none.
     *
     * @param array<int|string, array{int, string}>                                                   $lines  The item and the units of each line of the return, as positive numbers.
     * @param array<int, array{sold: string, share: string, returned: string, returnedShare: string}> $origin By item.
     *
     * @return array<int|string, string>
     */
    public static function returnShares(array $lines, array $origin): array
    {
        $shares = array_fill_keys(array_keys($lines), '0.0000');
        $byItem = [];

        foreach ($lines as $key => [$itemId, $units]) {
            $byItem[$itemId][$key] = $units;
        }

        foreach ($byItem as $itemId => $units) {
            $basis = $origin[$itemId] ?? null;

            if ($basis === null || bccomp($basis['sold'], '0', self::UNITS) <= 0) {
                continue;
            }

            $giving = '0';

            foreach ($units as $count) {
                $giving = bcadd($giving, $count, self::UNITS);
            }

            $leftShare = bcsub($basis['share'], $basis['returnedShare'], self::SCALE);
            $leftShare = bccomp($leftShare, '0', self::SCALE) > 0 ? $leftShare : '0.0000';
            $leftUnits = bcsub($basis['sold'], $basis['returned'], self::UNITS);

            if (bccomp($giving, $leftUnits, self::UNITS) >= 0) {
                $total = $leftShare;
            } else {
                $part  = SaleAmounts::round(bcdiv(bcmul($basis['share'], $giving, self::WORK), $basis['sold'], self::WORK), self::SCALE);
                $total = bccomp($part, $leftShare, self::SCALE) > 0 ? $leftShare : $part;
            }

            foreach (self::proportional($units, $total) as $key => $share) {
                $shares[$key] = $share;
            }
        }

        return $shares;
    }

    /**
     * @param array<int|string, string> $weights
     *
     * @return array<int|string, string>
     */
    private static function proportional(array $weights, string $amount): array
    {
        $shares = array_fill_keys(array_keys($weights), '0.0000');
        $open   = array_keys(array_filter($weights, static fn (string $weight): bool => bccomp($weight, '0', self::SCALE) > 0));

        if ($open === [] || bccomp($amount, '0', self::SCALE) <= 0) {
            return $shares;
        }

        $total = '0';

        foreach ($open as $key) {
            $total = bcadd($total, $weights[$key], self::WORK);
        }

        $given = '0.0000';
        $last  = array_key_last($open);

        foreach ($open as $position => $key) {
            $share = $position === $last
                ? bcsub($amount, $given, self::SCALE)
                : SaleAmounts::round(bcdiv(bcmul($amount, $weights[$key], self::WORK), $total, self::WORK), self::SCALE);

            $shares[$key] = $share;
            $given        = bcadd($given, $share, self::SCALE);
        }

        return $shares;
    }

    /**
     * Rounding can leave the last share a hair above what its line comes to, or below nothing. The
     * hair goes to the lines that have room, so no line is taxed on less than nothing.
     *
     * @param array<int|string, string> $shares
     * @param array<int|string, string> $bases
     * @param list<int|string>          $open
     *
     * @return array<int|string, string>
     */
    private static function keptWithin(array $shares, array $bases, array $open): array
    {
        $last   = $open[array_key_last($open)];
        $excess = '0';

        if (bccomp($shares[$last], $bases[$last], self::SCALE) > 0) {
            $excess        = bcsub($shares[$last], $bases[$last], self::SCALE);
            $shares[$last] = $bases[$last];
        } elseif (bccomp($shares[$last], '0', self::SCALE) < 0) {
            $excess        = $shares[$last];
            $shares[$last] = '0.0000';
        }

        foreach ($open as $key) {
            if (bccomp($excess, '0', self::SCALE) === 0) {
                break;
            }

            $moved        = bcadd($shares[$key], $excess, self::SCALE);
            $room         = bccomp($moved, $bases[$key], self::SCALE) > 0 ? $bases[$key] : (bccomp($moved, '0', self::SCALE) < 0 ? '0.0000' : $moved);
            $excess       = bcsub($moved, $room, self::SCALE);
            $shares[$key] = $room;
        }

        return $shares;
    }
}
