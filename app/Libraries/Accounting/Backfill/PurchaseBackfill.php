<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingLoader;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReceivingType;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\StockLedger;
use CodeIgniter\Database\BaseConnection;

/**
 * The purchases that were saved and have no journal: what each would have posted when it was saved,
 * as ReceivingPosting::postCompleted posts it: the purchase with its stock movements, the payment made
 * at once, and a journal for each payment made against it since. The goods a return gives back and the
 * stock a transfer moves are worth what the ledger of stock says on the day they are posted, which a
 * preview cannot fix, so they are left to the live posting.
 */
final class PurchaseBackfill extends BackfillAdapter
{
    private const SOURCES = [
        ReceivingPostingBuilder::SOURCE_PURCHASE,
        ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER,
        ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT,
        ReceivingPostingBuilder::SOURCE_PAYMENT,
    ];

    private ReceivingLoader $loader;
    private ReceivingPostingBuilder $builder;
    private CrossBranchPayments $crossBranch;

    public function __construct(BaseConnection $db, ?BackfillGate $gate = null)
    {
        parent::__construct($db, $gate);

        $this->loader      = new ReceivingLoader($this->db);
        $this->builder     = new ReceivingPostingBuilder(new RoutingResolver($this->db), new StockLedger($this->db));
        $this->crossBranch = new CrossBranchPayments($this->db);
    }

    public function family(): BackfillFamily
    {
        return BackfillFamily::Purchases;
    }

    public function candidates(BackfillScope $scope, int $afterId, int $limit, int $batchId): array
    {
        $date   = 'COALESCE(r.invoice_date, DATE(r.receiving_time))';
        $params = [$scope->from, $scope->to];

        if ($scope->branchId !== null) {
            $params[] = $scope->branchId;
        }

        array_push($params, $afterId, $batchId);

        $rows = $this->db->query(
            "SELECT r.receiving_id AS id, {$date} AS document_date, r.branch_id FROM " . $this->db->prefixTable('receivings') . ' r'
            . ' WHERE ' . $date . ' BETWEEN ? AND ?'
            . ($scope->branchId === null ? '' : ' AND r.branch_id = ?')
            . ' AND r.receiving_id > ? AND ' . $this->noJournal('r.receiving_id', self::SOURCES)
            . ' AND ' . $this->noStock('r.receiving_id', [ReceivingPostingBuilder::SOURCE_PURCHASE, ReceivingPostingBuilder::SOURCE_STOCK_TRANSFER, ReceivingPostingBuilder::SOURCE_TRANSFER_RECEIPT])
            . ' ORDER BY r.receiving_id ASC LIMIT ' . max(1, $limit),
            $params,
        )->getResult();

        return array_map(static fn (object $row): BackfillDocument => new BackfillDocument(BackfillFamily::Purchases, (int) $row->id, (string) $row->document_date, (int) $row->branch_id), $rows);
    }

    public function plan(BackfillDocument $document, ?int $employeeId, int $batchId): BackfillPlan
    {
        $purchase = $this->loader->load($document->id);

        if ($purchase === null) {
            return BackfillPlan::refused(BackfillRefusal::DocumentChanged, '', 'purchase');
        }

        $source = (string) json_encode($purchase, JSON_UNESCAPED_UNICODE);
        $early  = $this->gate->refusalOf($purchase->date, $purchase->branchId);

        if ($early !== null) {
            return BackfillPlan::refused($early, $source);
        }

        if ($purchase->type !== ReceivingType::Receive || $purchase->toBranchId !== null) {
            return BackfillPlan::refused(BackfillRefusal::ValuedByLedger, $source, $purchase->type->value);
        }

        if ($this->partyGone('suppliers', $purchase->supplierId)) {
            return BackfillPlan::refused(BackfillRefusal::PartyMissing, $source, 'supplier');
        }

        $treasuries = [$purchase->treasuryId, ...array_map(static fn ($payment): ?int => $payment->treasuryId, $purchase->payments)];

        foreach ($treasuries as $treasuryId) {
            if ($this->treasuryGone($treasuryId)) {
                return BackfillPlan::refused(BackfillRefusal::TreasuryMissing, $source, (string) $treasuryId);
            }
        }

        $paymentIds = array_map(static fn ($payment): int => $payment->paymentId, $purchase->payments);

        if ($this->hasOtherJournals([ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, ...CrossBranchPayments::companionsOf(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT)], $paymentIds, $batchId)) {
            return BackfillPlan::refused(BackfillRefusal::PartlyPosted, $source, 'payment');
        }

        try {
            $plan  = $this->builder->purchase($purchase, 1, $employeeId);
            $steps = [new BackfillStep($plan->journal, $plan->movements === [] ? null : new BackfillStock(ReceivingPostingBuilder::issueSourceOf($purchase), $purchase->receivingId, JournalEntryType::General, 1, $purchase->date, $purchase->branchId, $plan->movements))];
            $paid  = $this->builder->payment($purchase, 1, $employeeId);

            if ($paid !== null) {
                $steps[] = new BackfillStep($paid);
            }

            foreach ($purchase->payments as $payment) {
                $draft = $this->builder->supplierPayment($purchase, $payment, 1, $employeeId);

                if ($draft === null) {
                    continue;
                }

                if (count($this->crossBranch->drafts($draft, $payment->treasuryId, $payment->treasuryBranchId)) > 1) {
                    return BackfillPlan::refused(BackfillRefusal::CrossBranchPayment, $source, (string) $payment->paymentId);
                }

                $steps[] = new BackfillStep($draft);
            }

            return $this->checked($steps, $source, $purchase->supplierId === null ? null : 'supplier', $purchase->supplierId);
        } catch (PostingException $failure) {
            return $this->refusedByPosting($failure, $source);
        }
    }

    protected function documentKey(): array
    {
        return ['receivings', 'receiving_id'];
    }
}
