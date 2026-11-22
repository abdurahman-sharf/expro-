<?php

namespace App\Libraries\EInvoicing;

/**
 * The kinds of document of the standard, by the invoice type code (BT-3) the UBL file carries: the invoice (388),
 * the credit note (381), the debit note (383) and the prepayment invoice an advance is issued on (386). All are an
 * Invoice element in the file.
 */
enum EInvoiceKind: string
{
    case Invoice    = 'invoice';
    case CreditNote = 'credit_note';
    case DebitNote  = 'debit_note';
    case Prepayment = 'prepayment';

    public function typeCode(): string
    {
        return match ($this) {
            self::Invoice    => '388',
            self::CreditNote => '381',
            self::DebitNote  => '383',
            self::Prepayment => '386',
        };
    }

    public function isNote(): bool
    {
        return $this === self::CreditNote || $this === self::DebitNote;
    }

    public function languageKey(): string
    {
        return 'Accounting_einvoicing.kind_' . $this->value;
    }
}
