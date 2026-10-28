<?php

namespace App\Libraries\Accounting;

/**
 * One part of the tax report: the part of its documents that carries tax and the tax on it, by
 * rate, and what the documents that took a discount on the whole of them hold of both. The
 * amounts are signed by what they do to the tax owed.
 */
final readonly class TaxSectionFigures
{
    /**
     * @param numeric-string   $base
     * @param numeric-string   $tax
     * @param list<TaxRateRow> $rates
     * @param numeric-string   $discountedBase
     * @param numeric-string   $discountedTax
     */
    public function __construct(
        public TaxSection $section,
        public string $base,
        public string $tax,
        public int $documents,
        public array $rates,
        public int $discountedDocuments,
        public string $discountedBase,
        public string $discountedTax,
    ) {
    }
}
