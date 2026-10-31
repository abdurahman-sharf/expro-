<?php

namespace App\Libraries;

use App\Libraries\Accounting\SaleAmounts;

/**
 * The charges typed on the header of an invoice or a credit note, apart from its lines: an adjustment,
 * which has a label and a signed amount, and a shipping charge with the tax on it. The adjustment joins
 * the total only while the total before it is more than nothing, as the reference system adds it. The tax
 * on the shipping follows the tax mode: on top of the amount when the prices do not include tax, taken out
 * of the amount when they do. The tax is kept to four decimals and never rounded to the cent; the total of
 * the invoice is, in the journal.
 */
final readonly class HeaderCharges
{
    public const SCALE          = 4;
    public const LABEL_MAX      = 100;
    public const SHIPPING_VIEWS = [1, 2, 3];
    private const WORK          = 8;
    private const NUMBER        = '/^-?\d{1,11}(\.\d{1,4})?$/';

    public function __construct(
        public string $adjustmentLabel = '',
        public string $adjustment = '0',
        public string $shipping = '0',
        public ShippingTaxOption $shippingTaxOption = ShippingTaxOption::None,
        public string $shippingTaxPercent = '0',
        public ?int $shippingOptions = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return bccomp($this->safe($this->adjustment), '0', self::WORK) === 0
            && bccomp($this->safe($this->shipping), '0', self::WORK) === 0
            && $this->shippingOptions === null;
    }

    public function hasAdjustment(): bool
    {
        return bccomp($this->safe($this->adjustment), '0', self::WORK) !== 0;
    }

    public function hasShipping(): bool
    {
        return bccomp($this->safe($this->shipping), '0', self::WORK) !== 0 || $this->shippingOptions !== null;
    }

    /**
     * Why the charges cannot be accepted on a document whose lines, with their added tax, come to the
     * running total (negative for a return), or null.
     */
    public function problem(string $running): ?HeaderChargesProblem
    {
        if (preg_match(self::NUMBER, $this->adjustment) !== 1 || preg_match(self::NUMBER, $this->shipping) !== 1) {
            return HeaderChargesProblem::NotANumber;
        }

        if (bccomp($this->shipping, '0', self::WORK) < 0) {
            return HeaderChargesProblem::NegativeShipping;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,4})?$/', $this->shippingTaxPercent) !== 1 || bccomp($this->shippingTaxPercent, '100', self::WORK) > 0) {
            return HeaderChargesProblem::TaxPercentInvalid;
        }

        if ($this->shippingOptions !== null && ! in_array($this->shippingOptions, self::SHIPPING_VIEWS, true)) {
            return HeaderChargesProblem::UnknownOption;
        }

        if (mb_strlen($this->adjustmentLabel) > self::LABEL_MAX) {
            return HeaderChargesProblem::LabelTooLong;
        }

        $resolved = $this->resolve($running);
        $after    = bcadd(SaleAmounts::absolute($running), $resolved->addedToTotal(), self::WORK);

        return bccomp($after, '0', self::WORK) < 0 ? HeaderChargesProblem::ExceedsTotal : null;
    }

    /**
     * The charges as they count on a document whose lines, with their added tax, come to the running
     * total. The sign of the running total says whether the document is a return: the amounts stay in the
     * direction of the document either way.
     */
    public function resolve(string $running): ResolvedCharges
    {
        $adjustment = $this->safe($this->adjustment);
        $shipping   = bcadd($this->safe($this->shipping), '0', self::SCALE);
        $tax        = $this->shippingTax($shipping);
        $included   = $this->shippingTaxOption === ShippingTaxOption::Included;
        $before     = bcadd(SaleAmounts::absolute($running), bcadd($shipping, $included ? '0' : $tax, self::WORK), self::WORK);
        $effective  = bccomp($before, '0', self::WORK) > 0 ? bcadd($adjustment, '0', self::SCALE) : '0.0000';

        return new ResolvedCharges(trim($this->adjustmentLabel), $effective, $shipping, $tax, $included, $this->shippingOptions);
    }

    public function shippingTax(string $shipping): string
    {
        $percent = $this->safe($this->shippingTaxPercent);

        if ($this->shippingTaxOption === ShippingTaxOption::None || bccomp($percent, '0', self::WORK) === 0 || bccomp($shipping, '0', self::WORK) === 0) {
            return '0.0000';
        }

        $rate = bcdiv($percent, '100', self::WORK);

        $tax = $this->shippingTaxOption === ShippingTaxOption::Included
            ? bcsub($shipping, bcdiv($shipping, bcadd('1', $rate, self::WORK), self::WORK), self::WORK)
            : bcmul($shipping, $rate, self::WORK);

        return SaleAmounts::round($tax, self::SCALE);
    }

    private function safe(string $value): string
    {
        return preg_match(self::NUMBER, $value) === 1 ? $value : '0';
    }
}
