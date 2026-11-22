<?php

namespace App\Libraries\Tax;

/**
 * The taxable amount and the VAT of one category of a document (BT-116 and BT-117), the VAT rounded on the taxable
 * amount and not summed from the lines. The taxable amount holds the charges of the document in the category, its
 * shipping, which are kept apart too so that what the lines come to can be told.
 */
final readonly class VatCategoryTotal
{
    public function __construct(
        public string $category,
        public string $percent,
        public ?string $exemptionCode,
        public ?string $exemptionText,
        public string $taxable,
        public string $vat,
        public bool $included,
        public string $charges = '0.00',
    ) {
    }

    public function key(): string
    {
        return VatCalculator::categoryKey($this->category, $this->percent, $this->exemptionCode);
    }

    public function withAmounts(string $taxable, string $vat): self
    {
        return new self($this->category, $this->percent, $this->exemptionCode, $this->exemptionText, $taxable, $vat, $this->included, $this->charges);
    }

    public function withCharges(string $taxable, string $vat, string $charges): self
    {
        return new self($this->category, $this->percent, $this->exemptionCode, $this->exemptionText, $taxable, $vat, $this->included, $charges);
    }

    /**
     * What the lines of the category come to, without its charges.
     */
    public function lines(): string
    {
        return bcsub($this->taxable, $this->charges, 2);
    }
}
