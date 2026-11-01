<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * The documents the sales reports tell apart. A return of a sale is a credit note or a refund invoice
 * by the document it was issued as, and both count against the sales; a debit note adds to what a
 * customer owes and is listed only when it is asked for.
 */
enum DocumentKind: string
{
    case Invoice       = 'invoice';
    case CreditNote    = 'credit_note';
    case RefundInvoice = 'refund_invoice';
    case DebitNote     = 'debit_note';

    public function isReturn(): bool
    {
        return $this === self::CreditNote || $this === self::RefundInvoice;
    }

    public function sign(): int
    {
        return $this->isReturn() ? -1 : 1;
    }

    public function languageKey(): string
    {
        return 'Accounting_operational_reports.kind_' . $this->value;
    }

    /**
     * The kinds that make up the sales of a report that is not told which to read.
     *
     * @return list<self>
     */
    public static function sales(): array
    {
        return [self::Invoice, self::CreditNote, self::RefundInvoice];
    }
}
