<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleLine;

/**
 * A line of a sales document as the item reports count it, in the direction of the document: the units,
 * the discount of the line with its share of the discount on the whole invoice, the tax, what the line
 * comes to without the tax and what it comes to with it. A return carries all of them as negatives.
 */
final readonly class ReportLine
{
    public function __construct(
        public int $itemId,
        public string $unitPrice,
        public string $quantity,
        public string $discount,
        public string $tax,
        public string $value,
        public string $total,
    ) {
    }

    public static function of(SaleLine $line, int $sign, int $decimals): self
    {
        $gross    = SaleAmounts::lineGross($line);
        $discount = bcadd(SaleAmounts::lineDiscount($line, $decimals), SaleAmounts::absolute($line->invoiceDiscountShare), SaleAmounts::SCALE);
        $included = SaleAmounts::absolute($line->includedTax);
        $added    = SaleAmounts::absolute($line->addedTax);
        $value    = bcsub(bcsub($gross, $discount, SaleAmounts::SCALE + 4), $included, SaleAmounts::SCALE);
        $tax      = bcadd($included, $added, SaleAmounts::SCALE);

        return new self(
            $line->itemId,
            bcadd($line->unitPrice, '0', SaleAmounts::SCALE),
            Amounts::signed(SaleAmounts::absolute($line->quantity), $sign),
            Amounts::signed($discount, $sign),
            Amounts::signed($tax, $sign),
            Amounts::signed($value, $sign),
            Amounts::signed(bcadd($value, $tax, SaleAmounts::SCALE), $sign),
        );
    }
}
