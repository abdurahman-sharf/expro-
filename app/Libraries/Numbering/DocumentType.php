<?php

namespace App\Libraries\Numbering;

/**
 * The kinds of document that have a sequence of their own. A document is told apart by the table
 * it is stored in and its id there. A kind that already keeps its number in a column of that table
 * (an invoice, a quote, a customer) keeps writing it there, so every screen and search that reads
 * the column goes on working.
 */
enum DocumentType: string
{
    case SalesInvoice    = 'sales_invoice';
    case SalesQuote      = 'sales_quote';
    case CreditNote      = 'credit_note';
    case RefundInvoice   = 'refund_invoice';
    case CustomerPayment = 'customer_payment';
    case Customer        = 'customer';
    case Purchase        = 'purchase';
    case DebitNote       = 'debit_note';
    case SupplierPayment = 'supplier_payment';
    case Expense         = 'expense';
    case ReceivedCheque  = 'received_cheque';
    case PaidCheque      = 'paid_cheque';
    case Revenue         = 'revenue';

    /**
     * The tables a document of this kind may be stored in, with the column of its id.
     *
     * @return array<string, string>
     */
    public function sources(): array
    {
        return match ($this) {
            self::SalesInvoice, self::SalesQuote, self::CreditNote, self::RefundInvoice => ['sales' => 'sale_id'],
            self::CustomerPayment => ['sales_payments' => 'payment_id'],
            self::Customer        => ['customers' => 'person_id'],
            self::Purchase, self::DebitNote => ['receivings' => 'receiving_id'],
            self::SupplierPayment => ['receivings_payments' => 'payment_id', 'supplier_advances' => 'advance_id'],
            self::Expense         => ['expenses' => 'expense_id'],
            self::ReceivedCheque, self::PaidCheque => ['cheques' => 'cheque_id'],
            self::Revenue         => ['revenues' => 'revenue_id'],
        };
    }

    /**
     * The column that holds the number of the document in its own table, for the kinds that had
     * one before there was a numbering.
     */
    public function numberColumn(): ?string
    {
        return match ($this) {
            self::SalesInvoice => 'invoice_number',
            self::SalesQuote   => 'quote_number',
            self::Customer     => 'account_number',
            default            => null,
        };
    }

    /**
     * The tables whose documents show their id as their number until they get one, so a number
     * that is a bare digit string must stay above every id that is still shown that way.
     *
     * @return array<string, string>
     */
    public function bareIdSources(): array
    {
        return match ($this) {
            self::CustomerPayment, self::SupplierPayment, self::Expense, self::Revenue => $this->sources(),
            default => [],
        };
    }

    /**
     * What a document of this kind shows as its number while it has none.
     */
    public function fallback(int $id): string
    {
        return match ($this) {
            self::CreditNote, self::RefundInvoice => 'POS ' . $id,
            self::Purchase, self::DebitNote => 'RECV ' . $id,
            self::CustomerPayment, self::SupplierPayment, self::Expense, self::Revenue => (string) $id,
            self::ReceivedCheque => 'CHQ-IN ' . $id,
            self::PaidCheque     => 'CHQ-OUT ' . $id,
            default => '',
        };
    }

    public function labelKey(): string
    {
        return 'Numbering.type_' . $this->value;
    }

    public function hasDuplicateOption(): bool
    {
        return $this === self::Customer;
    }
}
