<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\SaleAmounts;

final class Amounts
{
    public const ZERO = '0.0000';

    /**
     * @return numeric-string
     */
    public static function add(string $left, string $right): string
    {
        return bcadd(bcadd($left, $right, SaleAmounts::SCALE + 2), '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function sub(string $left, string $right): string
    {
        return bcadd(bcsub($left, $right, SaleAmounts::SCALE + 2), '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function negate(string $value): string
    {
        return bcadd(bcsub('0', $value, SaleAmounts::SCALE + 2), '0', SaleAmounts::SCALE);
    }

    /**
     * @param numeric-string $value
     *
     * @return numeric-string
     */
    public static function signed(string $value, int $sign): string
    {
        return $sign < 0 ? self::negate($value) : bcadd($value, '0', SaleAmounts::SCALE);
    }

    /**
     * @param iterable<string> $values
     *
     * @return numeric-string
     */
    public static function sum(iterable $values): string
    {
        $total = '0';

        foreach ($values as $value) {
            $total = bcadd($total, $value, SaleAmounts::SCALE + 2);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    public static function isZero(string $value): bool
    {
        return bccomp($value, '0', SaleAmounts::SCALE) === 0;
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp($left, $right, SaleAmounts::SCALE);
    }

    /**
     * A share of a whole as a percentage with two decimals, nothing for a whole of nothing.
     *
     * @return numeric-string
     */
    public static function percent(string $part, string $whole): string
    {
        if (self::isZero($whole)) {
            return '0.00';
        }

        $value    = bcdiv(bcmul($part, '100', 8), $whole, 8);
        $negative = str_starts_with($value, '-');
        $rounded  = bcadd(bcadd(ltrim($value, '-'), '0.005', 3), '0', 2);

        return $negative && bccomp($rounded, '0', 2) !== 0 ? '-' . $rounded : $rounded;
    }
}
