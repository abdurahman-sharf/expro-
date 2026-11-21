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
            $key     = self::categoryKey($line->vat->category, $percent, $line->vat->exemptionCode);
            $result  = $this->line($line->base, $percent, $line->included, $key);

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
     * than the sale charged less what earlier returns gave back, and the return that takes back the last units of
     * a category, or the whole of its taxable amount, gives back exactly what is left of it, its largest line
     * carrying what that moves. The VAT of each category stays its taxable amount times its rate, rounded
     * (BR-CO-17), which the credit note must hold: the standard rounds every document on its own, so returns in
     * parts can part from the VAT of their sale by a halala each, as they did before. The return's amounts are
     * negative; what is left is positive.
     *
     * @param array<string, array{taxable: string, vat: string}> $left      By category key.
     * @param list<string>                                       $exhausted The categories whose units the return takes back to the last, whatever its amounts round to.
     */
    public function capReturn(VatResult $return, array $left, array $exhausted = []): VatResult
    {
        $categories = [];
        $lines      = $return->lines;

        foreach ($return->categories as $key => $category) {
            $taxable = bcadd(self::absolute($category->taxable), '0', 2);
            $sign    = '-';

            if (isset($left[$key])) {
                $leftTaxable = bcadd($left[$key]['taxable'], '0', 2);

                if (in_array($key, $exhausted, true) || bccomp($taxable, $leftTaxable, 2) > 0) {
                    $lines   = $this->moveOntoTheLargest($lines, $key, bcsub(self::signed($sign, $leftTaxable), $category->taxable, 2), $category->percent);
                    $taxable = $leftTaxable;
                }
            }

            $signed           = self::signed($sign, $taxable);
            $categories[$key] = $category->withAmounts($signed, self::round(bcmul($signed, bcdiv($category->percent, '100', self::WORK_SCALE), self::WORK_SCALE), 2));
        }

        return new VatResult($lines, $categories);
    }

    /**
     * Moves what the taxable amount of a category was held by onto its largest line, so that its lines still add
     * up to it (BR-CO-10 and the taxable amount of the category).
     *
     * @param array<string, VatLineResult> $lines
     *
     * @return array<string, VatLineResult>
     */
    private function moveOntoTheLargest(array $lines, string $key, string $difference, string $percent): array
    {
        if (bccomp($difference, '0', 2) === 0) {
            return $lines;
        }

        $largest = null;

        foreach ($lines as $lineKey => $line) {
            if ($line->category === $key && ($largest === null || bccomp(self::absolute($line->net), self::absolute($lines[$largest]->net), 2) > 0)) {
                $largest = $lineKey;
            }
        }

        if ($largest !== null) {
            $lines[$largest] = $this->ofNet(bcadd($lines[$largest]->net, $difference, 2), bcdiv($percent, '100', self::WORK_SCALE), $key);
        }

        return $lines;
    }

    /**
     * Adds a charge of the document, its shipping, to the category of its rate: the taxable amount of the category
     * takes the net of the charge and its VAT is rounded once on the whole (R-04 §9.2, BR-CO-17). A charge whose rate
     * no line carries opens a category of its own. The net has the sign of the document: below nothing on a return.
     *
     * @param array<string, VatCategoryTotal> $categories
     *
     * @return array<string, VatCategoryTotal>
     */
    public function withCharge(array $categories, string $net, string $percent, bool $included): array
    {
        $net = self::round($net, 2);

        if (bccomp($net, '0', 2) === 0) {
            return $categories;
        }

        $percent = self::percent($percent);
        $key     = self::categoryKey('S', $percent, null);
        $current = $categories[$key] ?? new VatCategoryTotal('S', $percent, null, null, '0.00', '0.00', $included);
        $taxable = bcadd($current->taxable, $net, 2);

        $categories[$key] = $current->withCharges($taxable, self::round(bcmul($taxable, bcdiv($percent, '100', self::WORK_SCALE), self::WORK_SCALE), 2), bcadd($current->charges, $net, 2));

        return $categories;
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

    private function line(string $base, string $percent, bool $included, string $key): VatLineResult
    {
        $rate = bcdiv($percent, '100', self::WORK_SCALE);

        if ($included) {
            $gross = self::round($base, 2);
            $net   = self::round(bcdiv(bcmul($gross, '100', self::WORK_SCALE), bcadd('100', $percent, self::WORK_SCALE), self::WORK_SCALE), 2);
        } else {
            $net = self::round($base, 2);
        }

        return $this->ofNet($net, $rate, $key);
    }

    private function ofNet(string $net, string $rate, string $key): VatLineResult
    {
        $exact = bcmul($net, $rate, self::WORK_SCALE);
        $vat   = self::round($exact, 2);

        return new VatLineResult($net, $vat, bcadd($net, $vat, 2), self::trim($exact), $key);
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
