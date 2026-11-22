<?php

namespace App\Libraries\EInvoicing;

/**
 * A charge on the whole document (BG-21), its shipping, with the VAT category it is taxed in (BT-102 and BT-103).
 */
final readonly class EInvoiceCharge
{
    public function __construct(
        public string $amount,
        public string $category,
        public string $rate,
        public string $reasonCode = 'FC',
        public string $reason = 'Shipping',
    ) {
    }
}
