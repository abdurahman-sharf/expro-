<?php

namespace App\Libraries\Accounting;

/**
 * How much of an invoice has been paid, counting the credit applied to it.
 */
enum PaymentState: string
{
    case Unpaid  = 'unpaid';
    case Partial = 'partial';
    case Paid    = 'paid';
    case Excess  = 'excess';

    public function languageKey(): string
    {
        return 'Sales.invoice_' . $this->value;
    }
}
