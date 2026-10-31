<?php

namespace App\Libraries\Accounting;

/**
 * What the lines of a sale or a purchase say about its tax: the part of the document that carries
 * tax, the tax on it, and both of them by rate. The amounts are not signed, because whether the
 * document adds to the tax owed or takes from it is what its journal says.
 */
final readonly class TaxDocument
{
    /**
     * @param numeric-string                                  $base       What the lines that carry tax come to after their discounts, before the tax.
     * @param numeric-string                                  $tax
     * @param array<string, array{base: string, tax: string}> $rates      By the rate of the tax, as a number without trailing zeros.
     * @param bool                                            $discounted Whether the document took a discount on the whole of it.
     */
    public function __construct(
        public TaxSection $section,
        public string $base,
        public string $tax,
        public array $rates,
        public bool $discounted,
    ) {
    }
}
