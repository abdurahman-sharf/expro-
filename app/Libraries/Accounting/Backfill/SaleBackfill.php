<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\SaleJournal;
use App\Libraries\Accounting\SaleLoader;
use App\Libraries\Accounting\SalePaymentJournal;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\UndeliveredReturns;
use CodeIgniter\Database\BaseConnection;

/**
 * The sales, the sales returns and the refund invoices that were completed and have no journal: what
 * each would have posted when it was completed, as SalePosting::postCompleted posts it, in the same
 * order: the invoice, a journal for each payment and the cost of the goods with its stock movements.
 */
final class SaleBackfill extends BackfillAdapter
{
    private const TYPES = [SALE_TYPE_POS, SALE_TYPE_INVOICE, SALE_TYPE_RETURN];

    private SaleLoader $loader;
    private SalePostingBuilder $builder;
    private SalePaymentJournal $payments;
    private SaleJournal $sales;
    private CrossBranchPayments $crossBranch;

    public function __construct(BaseConnection $db, ?BackfillGate $gate = null)
    {
        parent::__construct($db, $gate);

        $this->loader      = new SaleLoader($this->db);
        $this->builder     = SalePostingBuilder::forDatabase($this->db);
        $this->payments    = SalePaymentJournal::forDatabase($this->db, $this->builder);
        $this->sales       = SaleJournal::forDatabase($this->db, $this->builder);
        $this->crossBranch = new CrossBranchPayments($this->db);
    }

    public function family(): BackfillFamily
    {
        return BackfillFamily::Sales;
    }

    protected function candidateQuery(BackfillScope $scope, int $afterId, int $batchId): array
    {
        $date   = 'COALESCE(s.invoice_date, DATE(s.sale_time))';
        $params = [COMPLETED, ...self::TYPES, $scope->from, $scope->to];

        if ($scope->branchId !== null) {
            $params[] = $scope->branchId;
        }

        array_push($params, $afterId, $batchId);

        return [
            "SELECT s.sale_id AS id, {$date} AS document_date, s.branch_id FROM " . $this->db->prefixTable('sales') . ' s'
            . ' WHERE s.sale_status = ? AND s.sale_type IN (?, ?, ?) AND ' . $date . ' BETWEEN ? AND ?'
            . ($scope->branchId === null ? '' : ' AND s.branch_id = ?')
            . ' AND s.sale_id > ? AND ' . $this->noJournal('s.sale_id', [SalePostingBuilder::SOURCE_SALE])
            . ' AND ' . $this->noStock('s.sale_id', [SalePostingBuilder::SOURCE_SALE]),
            $params,
        ];
    }

    public function plan(BackfillDocument $document, ?int $employeeId, int $batchId): BackfillPlan
    {
        $sale = $this->loader->load($document->id);

        if ($sale === null || $sale->status !== COMPLETED || ! in_array($sale->saleType, self::TYPES, true)) {
            return BackfillPlan::refused(BackfillRefusal::DocumentChanged, '', 'sale');
        }

        $sale   = $sale->withReturnedUnits((new UndeliveredReturns($this->db))->unitsOf($sale->saleId));
        $source = (string) json_encode($sale, JSON_UNESCAPED_UNICODE);
        $early  = $this->gate->refusalOf($sale->date, $sale->branchId);

        if ($early !== null) {
            return BackfillPlan::refused($early, $source);
        }

        if ($this->partyGone('customers', $sale->customerId)) {
            return BackfillPlan::refused(BackfillRefusal::PartyMissing, $source, 'customer');
        }

        foreach ($sale->payments as $payment) {
            if ($this->treasuryGone($payment->treasuryId)) {
                return BackfillPlan::refused(BackfillRefusal::TreasuryMissing, $source, (string) $payment->treasuryId);
            }
        }

        $paymentIds = array_map(static fn ($payment): int => $payment->paymentId, $sale->payments);

        if ($this->hasOtherJournals([SalePostingBuilder::SOURCE_PAYMENT, ...CrossBranchPayments::companionsOf(SalePostingBuilder::SOURCE_PAYMENT)], $paymentIds, $batchId)) {
            return BackfillPlan::refused(BackfillRefusal::PartlyPosted, $source, 'payment');
        }

        try {
            $this->builder->assertSettled($sale);

            $steps   = [];
            $invoice = $this->sales->invoice($sale, 1, $employeeId);

            if ($invoice !== null) {
                $steps[] = new BackfillStep($invoice);
            }

            foreach ($sale->payments as $payment) {
                $draft = $this->payments->payment($sale, $payment, 1, $employeeId);

                if ($draft === null) {
                    continue;
                }

                if (count($this->crossBranch->drafts($draft, $payment->treasuryId, $payment->treasuryBranchId)) > 1) {
                    return BackfillPlan::refused(BackfillRefusal::CrossBranchPayment, $source, (string) $payment->paymentId);
                }

                $steps[] = new BackfillStep($draft);
            }

            $cost = $this->sales->costPlan($sale, 1, $employeeId);

            if ($this->stockOpenedAfter('sales', 'sale_id', 'sale_time', $sale->saleId, $cost->movements)) {
                return BackfillPlan::refused(BackfillRefusal::StockOpenedAfter, $source);
            }

            if ($cost->journal !== null || $cost->movements !== []) {
                $steps[] = new BackfillStep($cost->journal, $cost->movements === [] ? null : new BackfillStock(SalePostingBuilder::SOURCE_SALE, $sale->saleId, JournalEntryType::Cogs, 1, $sale->date, $sale->branchId, $cost->movements));
            }

            return $this->checked($steps, $source, $sale->customerId === null ? null : 'customer', $sale->customerId);
        } catch (PostingException $failure) {
            return $this->refusedByPosting($failure, $source);
        }
    }

    public function documentAmount(BackfillDocument $document): string
    {
        $sale = $this->loader->load($document->id);

        if ($sale === null) {
            return '0.0000';
        }

        $total = SaleAmounts::figures($sale, totals_decimals())['total'];

        return $sale->isReturn() ? bcsub('0', $total, SaleAmounts::SCALE) : $total;
    }

    protected function documentKey(): array
    {
        return ['sales', 'sale_id'];
    }
}
