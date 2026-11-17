<?php

namespace App\Libraries\Purchases;

final readonly class PurchaseInvoicePage
{
    /**
     * @param list<PurchaseInvoiceRow> $rows
     * @param string|null              $refusal The language key of why the page was not read, when it was not.
     */
    public function __construct(
        public int $total,
        public array $rows,
        public ?string $refusal = null,
    ) {
    }
}
