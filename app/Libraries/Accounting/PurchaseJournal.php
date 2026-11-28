<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\OperationJournal;
use App\Libraries\Accounting\Engine\PostingEngine;
use Closure;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The plan of a purchase or a purchase return with its journal in the mode the operation is posted in. The
 * stock movements, and every check of the document, still come from the code: the engine writes journals,
 * not movements. A transfer of stock between branches is left as the code makes it.
 */
final class PurchaseJournal
{
    public const PURCHASE = 'purchase';
    public const RETURN   = 'purchase_return';

    private OperationJournal $purchases;
    private OperationJournal $returns;

    /**
     * @param (Closure(): int)|null $baseDecimals As the builder takes them: two when none are given.
     */
    public function __construct(private readonly ReceivingPostingBuilder $builder, private readonly ?Closure $baseDecimals = null, ?BaseConnection $db = null, ?PostingEngine $engine = null)
    {
        $db ??= Database::connect();

        $this->purchases = new OperationJournal(self::PURCHASE, $db, $engine);
        $this->returns   = new OperationJournal(self::RETURN, $db, $engine);
    }

    /**
     * @throws PostingException
     */
    public function plan(ReceivingDocument $purchase, int $revision, ?int $employeeId = null): StockPostingPlan
    {
        $plan = $this->builder->purchase($purchase, $revision, $employeeId);

        if ($purchase->type === ReceivingType::Requisition || $purchase->toBranchId !== null) {
            return $plan;
        }

        $decimals = $purchase->currency === null || $this->baseDecimals === null ? 2 : ($this->baseDecimals)();
        $receive  = $purchase->type === ReceivingType::Receive;

        $journal = ($receive ? $this->purchases : $this->returns)->draft(
            static fn (): ?JournalDraft => $plan->journal,
            static fn () => $receive
                ? PurchaseFacts::receive($purchase, $revision, $employeeId, $decimals)
                : PurchaseFacts::giveBack($purchase, $plan->movements, $revision, $employeeId, $decimals),
            ReceivingPostingBuilder::SOURCE_PURCHASE,
            $purchase->receivingId,
            $revision,
        );

        return new StockPostingPlan($journal, $plan->movements, $plan->receipt, $plan->receiptMovements);
    }
}
