<?php

namespace App\Libraries\EInvoicing;

/**
 * A line of the document with the tax of the books before it was rounded, which the tax of a category is
 * rounded from once for all its lines, and, for a line sold under a VAT class without tax, the reason of its own.
 */
final readonly class BuiltLine
{
    public function __construct(
        public EInvoiceLine $line,
        public string $exactTax,
        public ?string $exemptionCode = null,
        public ?string $exemptionText = null,
    ) {
    }
}
