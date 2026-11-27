<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\OperationJournal;
use App\Libraries\Accounting\Engine\PostingEngine;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The journals of a payment of a sale, in the mode each is posted in: the payment itself and the exchange
 * difference it leaves on a foreign document. The live posting and the retroactive posting both ask here.
 */
final class SalePaymentJournal
{
    private RoutingResolver $resolver;
    private OperationJournal $payments;
    private OperationJournal $differences;

    /**
     * @param Closure(): int $baseDecimals
     */
    public function __construct(private readonly SalePostingBuilder $builder, private readonly Closure $baseDecimals, ?BaseConnection $db = null, ?PostingEngine $engine = null)
    {
        $db ??= Database::connect();

        $this->resolver    = new RoutingResolver($db);
        $this->payments    = new OperationJournal(SalePostingBuilder::SOURCE_PAYMENT, $db, $engine);
        $this->differences = new OperationJournal(SalePostingBuilder::SOURCE_PAYMENT_FX, $db, $engine);
    }

    public static function forDatabase(BaseConnection $db, SalePostingBuilder $builder): self
    {
        return new self($builder, static fn (): int => (new Currencies($db))->baseDecimals(), $db);
    }

    /**
     * @throws PostingException
     */
    public function payment(SaleDocument $sale, SalePayment $payment, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->payments->draft(
            fn (): ?JournalDraft => $this->builder->payment($sale, $payment, $revision, $employeeId),
            fn () => SalePaymentFacts::of($sale, $payment, $revision, $employeeId, $sale->currency === null ? 2 : ($this->baseDecimals)()),
            SalePostingBuilder::SOURCE_PAYMENT,
            $payment->paymentId,
            $revision,
        );
    }

    /**
     * @throws PostingException
     */
    public function paymentDifference(SaleDocument $sale, SalePayment $payment, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->differences->draft(
            fn (): ?JournalDraft => $this->builder->paymentDifference($sale, $payment, $revision, $employeeId),
            fn () => SalePaymentDifferenceFacts::of($sale, $payment, $revision, $employeeId, $sale->currency === null ? 2 : ($this->baseDecimals)(), $this->resolver),
            SalePostingBuilder::SOURCE_PAYMENT_FX,
            $payment->paymentId,
            $revision,
        );
    }
}
