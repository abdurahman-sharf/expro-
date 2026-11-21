<?php

namespace App\Libraries\Tax;

/**
 * One line as the calculation takes it: what it comes to after its own discount and its share of the discount on
 * the whole invoice (negative on a return), the VAT it is sold under, and whether the price holds the tax.
 */
final readonly class VatLine
{
    public function __construct(
        public string $key,
        public string $base,
        public LineVat $vat,
        public bool $included,
    ) {
    }
}
