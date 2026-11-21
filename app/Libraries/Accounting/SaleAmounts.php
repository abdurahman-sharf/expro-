<?php

namespace App\Libraries\Accounting;

/**
 * The amounts of a sale from its saved lines, by the same rules the sales report applies:
 * a percentage discount is rounded to the decimals the shop works in and a fixed discount is
 * taken from every unit. All amounts are positive: whether a sale is a return says which side
 * of the journal they go on. Intermediate values keep more decimals than they end with.
 */
final class SaleAmounts
{
    private const WORK_SCALE = 8;
    public const SCALE       = 4;

    /**
     * Gross is what the lines come to before any discount, discount what their own discounts take
     * off, invoiceDiscount what the discount on the whole sale takes off after them, net what is
     * left without the tax that was inside the prices, and tax all the tax of the sale.
     *
     * @return array{gross: numeric-string, discount: numeric-string, invoiceDiscount: numeric-string, net: numeric-string, tax: numeric-string}
     */
    public static function totals(SaleDocument $sale, int $decimals): array
    {
        $gross           = '0';
        $discount        = '0';
        $invoiceDiscount = '0';
        $included        = '0';
        $added           = '0';

        foreach ($sale->lines as $line) {
            $gross           = bcadd($gross, self::lineGross($line), self::WORK_SCALE);
            $discount        = bcadd($discount, self::lineDiscount($line, $decimals), self::WORK_SCALE);
            $invoiceDiscount = bcadd($invoiceDiscount, self::absolute($line->invoiceDiscountShare), self::WORK_SCALE);
            $included        = bcadd($included, self::absolute($line->includedTax), self::WORK_SCALE);
            $added           = bcadd($added, self::absolute($line->addedTax), self::WORK_SCALE);
        }

        $net = bcsub(bcsub(bcsub($gross, $discount, self::WORK_SCALE), $invoiceDiscount, self::WORK_SCALE), $included, self::WORK_SCALE);

        return [
            'gross'           => bcadd($gross, '0', self::SCALE),
            'discount'        => bcadd($discount, '0', self::SCALE),
            'invoiceDiscount' => bcadd($invoiceDiscount, '0', self::SCALE),
            'net'             => bcadd($net, '0', self::SCALE),
            'tax'             => bcadd(bcadd($included, $added, self::WORK_SCALE), '0', self::SCALE),
        ];
    }

    /**
     * The tax of the sale that went to liability accounts of its own, by account and in account order,
     * which is the part of its tax the routed account of tax collected does not take.
     *
     * @return array<int, numeric-string>
     */
    public static function accountTaxes(SaleDocument $sale): array
    {
        $accounts = [];

        foreach ($sale->lines as $line) {
            foreach ($line->accountTaxes as $accountId => $amount) {
                $accounts[$accountId] = bcadd($accounts[$accountId] ?? '0', $amount, self::SCALE);
            }
        }

        ksort($accounts);

        return array_filter($accounts, static fn (string $amount): bool => bccomp($amount, '0', self::SCALE) !== 0);
    }

    /**
     * What an invoice journal holds, from the lines and the charges of the header: the total the party
     * owes rounded to the cent, the tax as calculated (the shipping tax with it), the revenue of the goods
     * that balances the journal, the shipping revenue without its tax, the adjustment in the direction of
     * the document, and what the lines took off. The rounding of the cent falls on the goods, so the
     * shipping and the adjustment are the amounts that were typed; when the lines come to nothing there
     * are no goods to carry it, and it falls on the shipping, which is then the whole of the revenue.
     *
     * A sale saved under the Saudi rules keeps the total the register charged, takes its tax from the VAT of its
     * categories and its revenue from their taxable amounts, and leaves what the two part by as its rounding
     * (BT-114), which is never folded into the revenue.
     *
     * @return array{total: numeric-string, tax: numeric-string, revenue: numeric-string, shipping: numeric-string, adjustment: numeric-string, discount: numeric-string, rounding: numeric-string}
     */
    public static function figures(SaleDocument $sale, int $decimals): array
    {
        $decimals = $sale->currency?->decimals ?? $decimals;
        $amounts  = self::totals($sale, $decimals);
        $shipping = $sale->charges?->shippingNet() ?? '0.0000';
        $tax      = bcadd($amounts['tax'], $sale->charges?->shippingTax ?? '0', self::SCALE);
        $adjusted = $sale->charges?->adjustment ?? '0.0000';
        $split    = InvoiceRounding::split(bcadd(bcadd($amounts['net'], $shipping, self::SCALE), $adjusted, self::SCALE), $tax, $sale->currency?->decimals ?? 2);
        $revenue  = bcsub(bcsub($split['revenue'], $shipping, self::SCALE), $adjusted, self::SCALE);

        if ($sale->vat !== []) {
            return self::workedOnce($sale, $amounts, $shipping, $adjusted);
        }

        if (bccomp($amounts['net'], '0', self::SCALE) === 0 && bccomp($shipping, '0', self::SCALE) > 0) {
            $shipping = bcadd($shipping, $revenue, self::SCALE);
            $revenue  = '0.0000';
        }

        return [
            'total'      => $split['total'],
            'tax'        => $split['tax'],
            'revenue'    => $revenue,
            'shipping'   => $shipping,
            'adjustment' => $adjusted,
            'discount'   => $amounts['discount'],
            'rounding'   => '0.0000',
        ];
    }

    /**
     * The total is what the register charged: the lines as they sold, the VAT of the categories whose prices did
     * not hold it, and the charges, to the cent.
     *
     * @param array{gross: numeric-string, discount: numeric-string, invoiceDiscount: numeric-string, net: numeric-string, tax: numeric-string} $amounts
     *
     * @return array{total: numeric-string, tax: numeric-string, revenue: numeric-string, shipping: numeric-string, adjustment: numeric-string, discount: numeric-string, rounding: numeric-string}
     */
    private static function workedOnce(SaleDocument $sale, array $amounts, string $shipping, string $adjusted): array
    {
        $taxable = '0';
        $vat     = '0';
        $added   = '0';

        foreach ($sale->vat as $category) {
            $taxable = bcadd($taxable, self::absolute($category->taxable), self::SCALE);
            $vat     = bcadd($vat, self::absolute($category->vat), self::SCALE);
            $added   = $category->included ? $added : bcadd($added, self::absolute($category->vat), self::SCALE);
        }

        $shippingTax = $sale->charges?->shippingTax ?? '0';
        $lines       = bcsub(bcsub($amounts['gross'], $amounts['discount'], self::SCALE), $amounts['invoiceDiscount'], self::SCALE);
        $charged     = bcadd(bcadd(bcadd($lines, $added, self::SCALE), bcadd($shipping, $adjusted, self::SCALE), self::SCALE), $shippingTax, self::SCALE);
        $total       = InvoiceRounding::split($charged, '0', $sale->currency?->decimals ?? 2)['total'];
        $tax         = bcadd($vat, $shippingTax, self::SCALE);
        $rounding    = bcsub(bcsub(bcsub(bcsub($total, $tax, self::SCALE), $taxable, self::SCALE), $shipping, self::SCALE), $adjusted, self::SCALE);

        return [
            'total'      => $total,
            'tax'        => $tax,
            'revenue'    => $taxable,
            'shipping'   => $shipping,
            'adjustment' => $adjusted,
            'discount'   => $amounts['discount'],
            'rounding'   => $rounding,
        ];
    }

    /**
     * The part of the goods of an invoice that one line carries: what it sells for less the share it
     * took of the discount on the whole invoice and the tax that was inside its price, and less its own
     * discount unless that discount has an account of its own.
     *
     * @return numeric-string
     */
    public static function lineGoods(SaleLine $line, int $decimals, bool $discountHasAccount): string
    {
        $goods = bcsub(bcsub(self::lineGross($line), self::absolute($line->invoiceDiscountShare), self::WORK_SCALE), self::absolute($line->includedTax), self::WORK_SCALE);

        return $discountHasAccount ? $goods : bcsub($goods, self::lineDiscount($line, $decimals), self::WORK_SCALE);
    }

    /**
     * What the line sells for before its discount.
     *
     * @return numeric-string
     */
    public static function lineGross(SaleLine $line): string
    {
        return bcmul(self::absolute($line->quantity), $line->unitPrice, self::WORK_SCALE);
    }

    /**
     * @return numeric-string
     */
    public static function lineDiscount(SaleLine $line, int $decimals): string
    {
        $quantity = self::absolute($line->quantity);

        $amount = $line->discountType === PERCENT
            ? bcmul(self::lineGross($line), bcdiv($line->discount, '100', self::WORK_SCALE), self::WORK_SCALE)
            : bcmul($quantity, $line->discount, self::WORK_SCALE);

        return self::round($amount, $decimals);
    }

    /**
     * The cost of the units of a line at a unit cost, to the scale amounts are kept at.
     *
     * @return numeric-string
     */
    public static function cost(string $quantity, string $unitCost): string
    {
        return self::round(bcmul(self::absolute($quantity), $unitCost, self::WORK_SCALE), self::SCALE);
    }

    /**
     * @param numeric-string $amount Not negative.
     *
     * @return numeric-string
     */
    public static function round(string $amount, int $decimals): string
    {
        $half = $decimals === 0 ? '0.5' : '0.' . str_repeat('0', $decimals) . '5';

        return bcadd(bcadd($amount, $half, $decimals + 1), '0', $decimals);
    }

    /**
     * @return numeric-string
     */
    public static function absolute(string $value): string
    {
        return ltrim($value, '-+') === '' ? '0' : ltrim($value, '-+');
    }
}
