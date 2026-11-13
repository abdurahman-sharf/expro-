<?php

namespace App\Libraries\EInvoicing;

final readonly class EInvoiceTaxSubtotal
{
    public function __construct(
        public string $category,
        public string $rate,
        public string $taxable,
        public string $taxAmount,
        public string $exemptionCode = '',
        public string $exemptionText = '',
    ) {
    }
}
