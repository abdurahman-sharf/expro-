<?php

namespace App\Libraries\EInvoicing;

/**
 * The invoice subtype (the first two characters of KSA-2): 01 for the tax invoice between businesses, which
 * the standard clears, and 02 for the simplified tax invoice to a consumer, which it reports.
 */
enum EInvoiceSubtype: string
{
    case Standard   = 'standard';
    case Simplified = 'simplified';

    public function code(): string
    {
        return $this === self::Standard ? '01' : '02';
    }

    public function transactionCode(): string
    {
        return $this->code() . '00000';
    }

    public function languageKey(): string
    {
        return 'Accounting_einvoicing.subtype_' . $this->value;
    }
}
