<?php

namespace App\Libraries\EInvoicing;

/**
 * A line of the document with the tax of the books before it was rounded, which the tax of a category is
 * rounded from once for all its lines.
 */
final readonly class BuiltLine
{
    public function __construct(
        public EInvoiceLine $line,
        public string $exactTax,
    ) {
    }
}
