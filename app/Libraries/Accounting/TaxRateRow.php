<?php

namespace App\Libraries\Accounting;

/**
 * What one rate of tax holds in a part of the tax report.
 */
final readonly class TaxRateRow
{
    /**
     * @param string         $rate The rate as a number, without trailing zeros.
     * @param numeric-string $base
     * @param numeric-string $tax
     */
    public function __construct(
        public string $rate,
        public string $base,
        public string $tax,
        public int $documents,
    ) {
    }
}
