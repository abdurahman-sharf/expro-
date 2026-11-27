<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\OperationJournal;
use App\Libraries\Accounting\Engine\PostingEngine;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The journals of the money paid to a supplier on a purchase, in the mode each is posted in: what the
 * purchase was paid when it was saved, each payment made against it later, and the exchange difference a
 * payment leaves on a foreign purchase. The live posting and the retroactive posting both ask here.
 */
final class ReceivingPaymentJournal
{
    private RoutingResolver $resolver;
    private OperationJournal $atOnce;
    private OperationJournal $payments;
    private OperationJournal $differences;

    /**
     * @param (Closure(): int)|null $baseDecimals As the builder takes them: two when none are given.
     */
    public function __construct(private readonly ReceivingPostingBuilder $builder, private readonly ?Closure $baseDecimals = null, ?BaseConnection $db = null, ?PostingEngine $engine = null)
    {
        $db ??= Database::connect();

        $this->resolver    = new RoutingResolver($db);
        $this->atOnce      = new OperationJournal(ReceivingPostingBuilder::SOURCE_PAYMENT, $db, $engine);
        $this->payments    = new OperationJournal(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, $db, $engine);
        $this->differences = new OperationJournal(ReceivingPostingBuilder::SOURCE_SUPPLIER_FX, $db, $engine);
    }

    /**
     * @throws PostingException
     */
    public function payment(ReceivingDocument $purchase, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->atOnce->draft(
            fn (): ?JournalDraft => $this->builder->payment($purchase, $revision, $employeeId),
            fn () => PurchasePaymentFacts::of($purchase, $revision, $employeeId, $this->decimalsFor($purchase)),
            ReceivingPostingBuilder::SOURCE_PAYMENT,
            $purchase->receivingId,
            $revision,
        );
    }

    /**
     * @throws PostingException
     */
    public function supplierPayment(ReceivingDocument $purchase, SupplierPayment $payment, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->payments->draft(
            fn (): ?JournalDraft => $this->builder->supplierPayment($purchase, $payment, $revision, $employeeId),
            fn () => SupplierPaymentFacts::of($purchase, $payment, $revision, $employeeId, $this->decimalsFor($purchase)),
            ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT,
            $payment->paymentId,
            $revision,
        );
    }

    /**
     * @throws PostingException
     */
    public function supplierPaymentDifference(ReceivingDocument $purchase, SupplierPayment $payment, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->differences->draft(
            fn (): ?JournalDraft => $this->builder->supplierPaymentDifference($purchase, $payment, $revision, $employeeId),
            fn () => SupplierPaymentDifferenceFacts::of($purchase, $payment, $revision, $employeeId, $this->decimalsFor($purchase), $this->resolver),
            ReceivingPostingBuilder::SOURCE_SUPPLIER_FX,
            $payment->paymentId,
            $revision,
        );
    }

    private function decimalsFor(ReceivingDocument $purchase): int
    {
        return $purchase->currency === null || $this->baseDecimals === null ? 2 : ($this->baseDecimals)();
    }
}
