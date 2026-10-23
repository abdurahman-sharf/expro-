<?php

namespace App\Libraries\Accounting;

/**
 * How much of an invoice has been taken back by credit notes.
 */
enum ReturnState: string
{
    case None    = 'none';
    case Partial = 'partial';
    case Full    = 'full';

    public function languageKey(): ?string
    {
        return $this === self::None ? null : 'Sales.invoice_returned_' . $this->value;
    }
}
