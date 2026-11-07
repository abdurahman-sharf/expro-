<?php

namespace App\Libraries;

use App\Libraries\Accounting\SaleAmounts;

/**
 * What the header of a sale adds to it once it is worked out against the lines: the label and the
 * amount of the adjustment as it counts (nothing when the lines came to nothing), the shipping, and the
 * tax on the shipping with whether that tax is inside the shipping amount. Every amount is in the
 * direction of the document: positive adds to what the invoice charges, or to what the credit note
 * gives back. These are the amounts that are stored and posted, so what is printed is what the books
 * hold.
 */
final readonly class ResolvedCharges
{
    public function __construct(
        public string $adjustmentLabel = '',
        public string $adjustment = '0.0000',
        public string $shipping = '0.0000',
        public string $shippingTax = '0.0000',
        public bool $shippingTaxIncluded = false,
        public ?int $shippingOptions = null,
        public ?int $shippingCompanyId = null,
        public ?int $shippingAccountId = null,
    ) {
    }

    public function isEmpty(): bool
    {
        return bccomp($this->adjustment, '0', SaleAmounts::SCALE) === 0
            && bccomp($this->shipping, '0', SaleAmounts::SCALE) === 0
            && $this->shippingOptions === null;
    }

    public function shippingNet(): string
    {
        return $this->shippingTaxIncluded ? bcsub($this->shipping, $this->shippingTax, SaleAmounts::SCALE) : bcadd($this->shipping, '0', SaleAmounts::SCALE);
    }

    public function shippingTaxAdded(): string
    {
        return $this->shippingTaxIncluded ? '0.0000' : bcadd($this->shippingTax, '0', SaleAmounts::SCALE);
    }

    public function addedToTotal(): string
    {
        return bcadd(bcadd($this->shipping, $this->shippingTaxAdded(), SaleAmounts::SCALE), $this->adjustment, SaleAmounts::SCALE);
    }
}
