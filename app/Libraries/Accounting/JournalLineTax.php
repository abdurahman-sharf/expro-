<?php

namespace App\Libraries\Accounting;

/**
 * The tax a journal line notes. It is a note and nothing more: it posts no tax line, changes no
 * balance and is read by no tax report. The figures follow the script of the reference system's
 * journal form (OBS-A FRM-R39): when the tax is in the amount, net is the amount over one plus the
 * rate, rounded, and the tax is what is left of the amount; when it is not, the tax is the amount
 * times the rate, rounded, and the total is the amount plus it.
 */
final class JournalLineTax
{
    public const CODE_PATTERN    = '/^[0-9A-Za-z][0-9A-Za-z\-]{0,15}$/';
    public const PERCENT_PATTERN = '/^\d{1,3}(\.\d{1,4})?$/';
    private const WORK_SCALE     = 12;

    /**
     * @param numeric-string $amount
     * @param numeric-string $percent
     *
     * @return array{net: numeric-string, tax: numeric-string, total: numeric-string}
     */
    public static function figures(string $amount, string $percent, bool $included, int $decimals): array
    {
        if ($included) {
            $net = Money::round(bcdiv($amount, bcadd('1', bcdiv($percent, '100', self::WORK_SCALE), self::WORK_SCALE), self::WORK_SCALE), $decimals);
            $tax = bcsub(Money::round($amount, $decimals), $net, $decimals);

            return ['net' => $net, 'tax' => $tax, 'total' => Money::round($amount, $decimals)];
        }

        $net = Money::round($amount, $decimals);
        $tax = Money::round(bcdiv(bcmul($amount, $percent, self::WORK_SCALE), '100', self::WORK_SCALE), $decimals);

        return ['net' => $net, 'tax' => $tax, 'total' => bcadd($net, $tax, $decimals)];
    }

    public static function isCode(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    public static function isPercent(string $percent): bool
    {
        return preg_match(self::PERCENT_PATTERN, $percent) === 1 && bccomp($percent, '100', 4) <= 0;
    }
}
