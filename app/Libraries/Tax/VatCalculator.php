<?php

namespace App\Libraries\Tax;

use InvalidArgumentException;

/**
 * Works out the VAT of a document the way the e-invoicing standard does (R-04 §9.3 and §10): the net of every line
 * is rounded to two decimals, the VAT of a line is its net times its rate rounded to two decimals, and the VAT of
 * a category is its taxable amount times its rate rounded once, never the sum of the rounded VAT of its lines, so
 * that BR-CO-17 holds by construction. A price that holds the tax is first rounded to two decimals and the net is
 * taken out of it. Amounts are rounded half up by their size, so a return is rounded as its sale was. Nothing here
 * reads or writes the database.
 */
final class VatCalculator
{
    public const VERSION     = 1;
    private const WORK_SCALE = 10;

    /**
     * @param list<VatLine> $lines
     */
    public function calculate(array $lines): VatResult
    {
        $results    = [];
        $categories = [];

        foreach ($lines as $line) {
            $percent = self::percent($line->vat->percent);
            $result  = $this->line($line->base, $percent, $line->included);
            $key     = self::categoryKey($line->vat->category, $percent, $line->vat->exemptionCode);

            if (isset($categories[$key]) && $categories[$key]['included'] !== $line->included) {
                throw new InvalidArgumentException('A category mixes prices that hold the tax with prices that do not.');
            }

            $results[$line->key] = $result;
            $categories[$key] ??= ['vat' => $line->vat, 'percent' => $percent, 'included' => $line->included, 'taxable' => '0.00'];
            $categories[$key]['taxable'] = bcadd($categories[$key]['taxable'], $result->net, 2);
        }

        $totals = [];

        foreach ($categories as $key => $category) {
            $totals[$key] = new VatCategoryTotal(
                $category['vat']->category,
                $category['percent'],
                $category['vat']->exemptionCode,
                $category['vat']->exemptionText,
                $category['taxable'],
                self::round(bcmul($category['taxable'], bcdiv($category['percent'], '100', self::WORK_SCALE), self::WORK_SCALE), 2),
                $category['included'],
            );
        }

        return new VatResult($results, $totals);
    }

    /**
     * Holds the categories of a return to what is left of its sale: a return never gives back more taxable amount
     * or VAT than the sale charged less what earlier returns gave back, and the return that takes back the whole
     * of what is left of a category gives back the whole of its VAT, so a sale returned in parts gives back exactly
     * what it charged. The return's amounts are negative; what is left is positive.
     *
     * @param array<string, array{taxable: string, vat: string}> $left By category key.
     */
    public function capReturn(VatResult $return, array $left): VatResult
    {
        $categories = [];

        foreach ($return->categories as $key => $category) {
            $taxable = bcadd(self::absolute($category->taxable), '0', 2);
            $vat     = bcadd(self::absolute($category->vat), '0', 2);

            if (isset($left[$key])) {
                $leftTaxable = bcadd($left[$key]['taxable'], '0', 2);
                $leftVat     = bcadd($left[$key]['vat'], '0', 2);

                if (bccomp($taxable, $leftTaxable, 2) >= 0) {
                    $taxable = $leftTaxable;
                    $vat     = $leftVat;
                } elseif (bccomp($vat, $leftVat, 2) > 0) {
                    $vat = $leftVat;
                }
            }

            $sign             = bccomp($category->taxable, '0', 2) < 0 || bccomp($category->vat, '0', 2) < 0 ? '-' : '';
            $categories[$key] = $category->withAmounts(self::signed($sign, $taxable), self::signed($sign, $vat));
        }

        return new VatResult($return->lines, $categories);
    }

    public static function categoryKey(string $category, string $percent, ?string $exemptionCode): string
    {
        return $category . '|' . self::percent($percent) . '|' . ($exemptionCode ?? '');
    }

    /**
     * Half up to a number of decimals by the size of the amount.
     */
    public static function round(string $amount, int $decimals): string
    {
        $size = self::absolute($amount);
        $half = bcdiv('5', bcpow('10', (string) ($decimals + 1)), $decimals + 1);

        return self::signed(bccomp($amount, '0', self::WORK_SCALE) < 0 ? '-' : '', bcadd($size, $half, $decimals));
    }

    private function line(string $base, string $percent, bool $included): VatLineResult
    {
        $rate = bcdiv($percent, '100', self::WORK_SCALE);

        if ($included) {
            $gross = self::round($base, 2);
            $net   = self::round(bcdiv(bcmul($gross, '100', self::WORK_SCALE), bcadd('100', $percent, self::WORK_SCALE), self::WORK_SCALE), 2);
        } else {
            $net = self::round($base, 2);
        }

        $exact = bcmul($net, $rate, self::WORK_SCALE);
        $vat   = self::round($exact, 2);

        return new VatLineResult($net, $vat, bcadd($net, $vat, 2), self::trim($exact));
    }

    private static function percent(string $percent): string
    {
        return bcadd($percent, '0', 3);
    }

    private static function absolute(string $amount): string
    {
        return ltrim(bcadd($amount, '0', self::WORK_SCALE), '-');
    }

    private static function signed(string $sign, string $amount): string
    {
        return bccomp($amount, '0', self::WORK_SCALE) === 0 ? ltrim($amount, '-') : $sign . ltrim($amount, '-');
    }

    private static function trim(string $amount): string
    {
        return bcadd($amount, '0', 6);
    }
}
