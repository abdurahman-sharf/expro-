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
    case SalesDebitNote  = 'sales_debit_note';

    case StockPermitAdd      = 'permit_in';
    case StockPermitIssue    = 'permit_out';
    case StockPermitTransfer = 'permit_transfer';
    case Stocktake           = 'stocktake';
    case StockRequest        = 'stock_request';

    /**
     * The tables a document of this kind may be stored in, with the column of its id.
     *
     * @return array<string, string>
     */
    public function sources(): array
    {
        return match ($this) {
            self::SalesInvoice, self::SalesQuote, self::CreditNote, self::RefundInvoice => ['sales' => 'sale_id'],
            self::CustomerPayment => ['sales_payments' => 'payment_id', 'customer_advances' => 'advance_id'],
            self::Customer        => ['customers' => 'person_id'],
            self::Purchase, self::DebitNote => ['receivings' => 'receiving_id'],
            self::SupplierPayment => ['receivings_payments' => 'payment_id', 'supplier_advances' => 'advance_id'],
            self::Expense         => ['expenses' => 'expense_id'],
            self::ReceivedCheque, self::PaidCheque => ['cheques' => 'cheque_id'],
            self::Revenue        => ['revenues' => 'revenue_id'],
            self::SalesDebitNote => ['sales_debit_notes' => 'debit_note_id'],
            self::StockPermitAdd, self::StockPermitIssue, self::StockPermitTransfer => ['stock_permits' => 'permit_id'],
            self::Stocktake    => ['stocktakes' => 'stocktake_id'],
            self::StockRequest => ['stock_requests' => 'request_id'],
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
            self::CustomerPayment, self::SupplierPayment, self::Expense, self::Revenue, self::SalesDebitNote => $this->sources(),
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
            self::SalesDebitNote      => str_pad((string) $id, $this->defaultPadding(), '0', STR_PAD_LEFT),
            self::ReceivedCheque      => 'CHQ-IN ' . $id,
            self::PaidCheque          => 'CHQ-OUT ' . $id,
            self::StockPermitAdd      => 'STK-IN ' . $id,
            self::StockPermitIssue    => 'STK-OUT ' . $id,
            self::StockPermitTransfer => 'STK-TRF ' . $id,
            self::Stocktake           => 'STKT ' . $id,
            self::StockRequest        => 'STK-REQ ' . $id,
            default                   => '',
        };
    }

    /**
     * The digits a number of the kind has until the company chooses otherwise: the sales debit note
     * is numbered with five and the stock documents with six, as the reference system numbers them.
     */
    public function defaultPadding(): int
    {
        return match ($this) {
            self::SalesDebitNote => 5,
            self::StockPermitAdd, self::StockPermitIssue, self::StockPermitTransfer, self::Stocktake, self::StockRequest => 6,
            default => 0,
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
