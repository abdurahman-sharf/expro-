<?php

namespace App\Libraries\EInvoicing;

/**
 * A line of the document: every amount is positive and has the two decimals of the standard, the price the
 * four it allows, and the figures are those of the books for the line, rounded once at the end.
 */
final readonly class EInvoiceLine
{
    public function __construct(
        public int $id,
        public string $name,
        public string $quantity,
        public string $unitCode,
        public string $priceNet,
        public string $allowance,
        public string $netAmount,
        public string $vatCategory,
        public string $vatRate,
        public string $vatAmount,
        public string $amountWithVat,
    ) {
    }
}
