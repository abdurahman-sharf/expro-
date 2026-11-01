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
     * @return array{total: numeric-string, tax: numeric-string, revenue: numeric-string, shipping: numeric-string, adjustment: numeric-string, discount: numeric-string}
     */
    public static function figures(SaleDocument $sale, int $decimals): array
    {
        $amounts  = self::totals($sale, $decimals);
        $shipping = $sale->charges?->shippingNet() ?? '0.0000';
        $tax      = bcadd($amounts['tax'], $sale->charges?->shippingTax ?? '0', self::SCALE);
        $adjusted = $sale->charges?->adjustment ?? '0.0000';
        $split    = InvoiceRounding::split(bcadd(bcadd($amounts['net'], $shipping, self::SCALE), $adjusted, self::SCALE), $tax);
        $revenue  = bcsub(bcsub($split['revenue'], $shipping, self::SCALE), $adjusted, self::SCALE);

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
        ];
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
