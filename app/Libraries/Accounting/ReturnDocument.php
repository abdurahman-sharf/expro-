<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentType;

/**
 * The two documents a return of sale can be issued as. Both are a return: they put the same
 * journals in the books, take the same goods back at the same cost and settle the customer the
 * same way. They differ in the number they take from their own sequence and in the name they
 * go by in lists and reports.
 */
enum ReturnDocument: string
{
    case CreditNote    = 'credit_note';
    case RefundInvoice = 'refund_invoice';

    public static function fromStored(?string $stored): self
    {
        return self::tryFrom((string) $stored) ?? self::CreditNote;
    }

    public function documentType(): DocumentType
    {
        return match ($this) {
            self::CreditNote    => DocumentType::CreditNote,
            self::RefundInvoice => DocumentType::RefundInvoice,
        };
    }

    public function storedValue(): ?string
    {
        return $this === self::CreditNote ? null : $this->value;
    }

    public function languageKey(): string
    {
        return $this->documentType()->labelKey();
    }
}
