<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReturnState;

/**
 * The states a sales report can be limited to, any number of them at once. An invoice is paid, partly
 * paid or unpaid by what has been settled on it, whatever was taken back from it, which is why a paid
 * invoice that was partly returned is both paid and paid with a return. A return is told by the document
 * it was issued as.
 */
enum StatusFilter: string
{
    case Paid         = 'paid';
    case Partial      = 'partial';
    case Unpaid       = 'unpaid';
    case Overdue      = 'overdue';
    case Refund       = 'refund';
    case PaidRefund   = 'paid_refund';
    case UnpaidRefund = 'unpaid_refund';
    case CreditNote   = 'credit_note';

    public function matches(ReportDocument $document, string $today): bool
    {
        $state    = $document->paymentState();
        $isIssued = $document->kind === DocumentKind::Invoice;
        $returned = $document->returnState() !== ReturnState::None;
        $settled  = $state === PaymentState::Paid || $state === PaymentState::Excess;

        return match ($this) {
            self::Paid         => $isIssued && $settled,
            self::Partial      => $isIssued && $state === PaymentState::Partial,
            self::Unpaid       => $isIssued && $state === PaymentState::Unpaid,
            self::Overdue      => $document->isOverdue($today),
            self::Refund       => $document->kind === DocumentKind::RefundInvoice,
            self::PaidRefund   => $isIssued && $settled && $returned,
            self::UnpaidRefund => $isIssued && $state === PaymentState::Unpaid && $returned,
            self::CreditNote   => $document->kind === DocumentKind::CreditNote,
        };
    }

    /**
     * @param list<self> $filters Empty for every document.
     */
    public static function admits(array $filters, ReportDocument $document, string $today): bool
    {
        if ($filters === []) {
            return true;
        }

        foreach ($filters as $filter) {
            if ($filter->matches($document, $today)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<self>
     */
    public static function fromRequest(mixed $input): array
    {
        $filters = [];

        foreach (is_array($input) ? $input : [] as $value) {
            $filter = is_string($value) ? self::tryFrom($value) : null;

            if ($filter !== null) {
                $filters[$filter->value] = $filter;
            }
        }

        return array_values($filters);
    }

    public function languageKey(): string
    {
        return 'Accounting_operational_reports.status_' . $this->value;
    }
}
