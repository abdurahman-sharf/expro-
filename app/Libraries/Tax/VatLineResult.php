<?php

namespace App\Libraries\Tax;

/**
 * What one line comes to: its net (BT-131) and VAT (KSA-11) to two decimals, its amount with VAT (KSA-12), and the
 * VAT of its net before any rounding, which is what the tax rows of the sale keep, and the key of its category.
 */
final readonly class VatLineResult
{
    public function __construct(
        public string $net,
        public string $vat,
        public string $gross,
        public string $exact,
        public string $category = '',
    ) {
    }
}
