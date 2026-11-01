<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\InvoiceFigures;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\ReturnState;

/**
 * A sales document as a sales report counts it: the amounts of its header in the direction of the
 * document, so that a credit note or a refund invoice carries them as negatives and a column adds up to
 * the net sales, and, for an invoice or a debit note, what has been settled on it and what was taken back
 * by refund invoices. The ledger amounts are what its journal holds, read apart from the amounts, so that
 * a report can say whether they agree.
 */
final readonly class ReportDocument
{
    public function __construct(
        public int $id,
        public DocumentKind $kind,
        public string $date,
        public int $branchId,
        public string $branchCode,
        public ?int $customerId,
        public string $customerName,
        public ?int $employeeId,
        public string $employeeName,
        public string $number,
        public string $value,
        public string $discount,
        public string $shipping,
        public string $adjustment,
        public string $tax,
        public string $total,
        public string $ledgerTotal,
        public string $ledgerTax,
        public string $ledgerRevenue,
        public string $returnedByRefund = '0.0000',
        public ?InvoiceFigures $figures = null,
        public ?int $originId = null,
    ) {
    }

    /**
     * What it comes to before tax: the total less the tax, which is what its journal puts on the
     * accounts of revenue less what it takes off them.
     *
     * @return numeric-string
     */
    public function revenue(): string
    {
        return Amounts::sub($this->total, $this->tax);
    }

    /**
     * What was settled on it: the payments and the credit applied to it, nothing for a return.
     *
     * @return numeric-string
     */
    public function paid(): string
    {
        return $this->figures?->settled() ?? Amounts::ZERO;
    }

    /**
     * What is left of the total once the payments and the refund invoices are taken off, which may be
     * below nothing when both exceed it. A return is not paid and not returned: it is its own amount,
     * which is below nothing.
     *
     * @return numeric-string
     */
    public function unpaid(): string
    {
        if ($this->figures === null) {
            return $this->total;
        }

        return Amounts::sub(Amounts::sub($this->total, $this->paid()), $this->returnedByRefund);
    }

    /**
     * What every return of the invoice took back, the credit notes with the refund invoices, as a
     * positive amount.
     *
     * @return numeric-string
     */
    public function returnedAll(): string
    {
        return $this->figures?->returned ?? Amounts::ZERO;
    }

    public function paymentState(): ?PaymentState
    {
        return $this->figures?->paymentState();
    }

    public function returnState(): ReturnState
    {
        return $this->figures?->returnState() ?? ReturnState::None;
    }

    public function isOverdue(string $today): bool
    {
        return $this->kind === DocumentKind::Invoice && $this->figures !== null && $this->figures->isOverdue($today);
    }
}
