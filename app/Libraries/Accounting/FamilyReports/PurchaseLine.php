<?php

namespace App\Libraries\Accounting\FamilyReports;

final readonly class PurchaseLine
{
    /**
     * @param numeric-string $quantity The units, negative on a return.
     * @param numeric-string $total    What the line is worth with its tax, negative on a return; the rounding of the document is on its last line.
     */
    public function __construct(
        public int $itemId,
        public string $itemName,
        public ?string $itemNumber,
        public string $category,
        public string $quantity,
        public string $unitPrice,
        public string $tax,
        public string $total,
    ) {
    }
}
