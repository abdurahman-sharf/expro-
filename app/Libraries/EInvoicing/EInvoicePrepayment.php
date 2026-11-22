<?php

namespace App\Libraries\EInvoicing;

/**
 * A prepayment an invoice takes off what is due (R-04 §9.5): the prepayment invoice it was issued on (KSA-26, KSA-28,
 * KSA-29, type 386 in KSA-30), its VAT category and rate (KSA-33, KSA-34), and the taxable amount and VAT the invoice
 * takes of it (KSA-31, KSA-32).
 */
final readonly class EInvoicePrepayment
{
    public function __construct(
        public string $number,
        public string $issueDate,
        public string $issueTime,
        public string $category,
        public string $rate,
        public string $taxable,
        public string $vat,
    ) {
    }

    public function amount(): string
    {
        return bcadd($this->taxable, $this->vat, 2);
    }
}
