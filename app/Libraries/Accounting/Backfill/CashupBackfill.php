<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\CashupDocument;
use App\Libraries\Accounting\CashupPosting;
use App\Libraries\Accounting\CashupPostingBuilder;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;

/**
 * The cash-ups that were counted and have no journal: the difference of each, as the live posting
 * posts it. A cash-up that was never stamped with a branch belongs to the default branch of the
 * company, and its expected cash and difference are counted from the books of that branch, as they
 * are when a cash-up is saved, and stamped on it when the batch is approved.
 */
final class CashupBackfill extends BackfillAdapter
{
    private CashupPosting $documents;
    private CashupPostingBuilder $builder;
    private int $defaultBranchId;

    public function __construct(BaseConnection $db, ?BackfillGate $gate = null)
    {
        parent::__construct($db, $gate);

        $this->documents       = new CashupPosting($this->db);
        $this->builder         = new CashupPostingBuilder(new RoutingResolver($this->db));
        $this->defaultBranchId = (new BranchGuard($this->db))->companyDefaultBranchId();
    }

    public function family(): BackfillFamily
    {
        return BackfillFamily::Cashups;
    }

    protected function candidateQuery(BackfillScope $scope, int $afterId, int $batchId): array
    {
        $branch = 'COALESCE(c.branch_id, ' . $this->defaultBranchId . ')';
        $params = [$scope->from, $scope->to];

        if ($scope->branchId !== null) {
            $params[] = $scope->branchId;
        }

        array_push($params, $afterId, $batchId);

        return [
            "SELECT c.cashup_id AS id, DATE(c.close_date) AS document_date, {$branch} AS branch_id FROM " . $this->db->prefixTable('cash_up') . ' c'
            . ' WHERE c.deleted = 0 AND (c.closed_amount_cash <> 0 OR c.closed_amount_due <> 0 OR c.closed_amount_card <> 0 OR c.closed_amount_check <> 0)'
            . ' AND DATE(c.close_date) BETWEEN ? AND ?'
            . ($scope->branchId === null ? '' : " AND {$branch} = ?")
            . ' AND c.cashup_id > ? AND ' . $this->noJournal('c.cashup_id', [CashupPostingBuilder::SOURCE]),
            $params,
        ];
    }

    public function plan(BackfillDocument $document, ?int $employeeId, int $batchId): BackfillPlan
    {
        $row = $this->db->table('cash_up')->where('cashup_id', $document->id)->get()->getRow();

        if ($row === null || (int) $row->deleted === 1 || ! $this->documents->isCounted($row)) {
            return BackfillPlan::refused(BackfillRefusal::DocumentChanged, '', 'cashup');
        }

        $branchId = (int) ($row->branch_id ?? $this->defaultBranchId);
        $date     = substr((string) $row->close_date, 0, 10);
        $source   = (string) json_encode($row, JSON_UNESCAPED_UNICODE);
        $early    = $this->gate->refusalOf($date, $branchId);

        if ($early !== null) {
            return BackfillPlan::refused($early, $source);
        }

        if ($this->treasuryGone($row->treasury_id === null ? null : (int) $row->treasury_id)) {
            return BackfillPlan::refused(BackfillRefusal::TreasuryMissing, $source, (string) $row->treasury_id);
        }

        if ($this->hasOtherJournals([CashupPostingBuilder::SOURCE], [$document->id], $batchId)) {
            return BackfillPlan::refused(BackfillRefusal::PartlyPosted, $source, 'cashup');
        }

        $counts = $row->branch_id === null || $row->cash_difference === null;

        try {
            [$expected, $difference] = $counts ? $this->documents->counted([], $row, $branchId) : [(string) $row->expected_amount_cash, (string) $row->cash_difference];

            $cashup = new CashupDocument(
                cashupId: (int) $row->cashup_id,
                date: $date,
                difference: bcadd($difference, '0', SaleAmounts::SCALE),
                treasuryId: $row->treasury_id === null ? null : (int) $row->treasury_id,
                branchId: $branchId,
                deleted: false,
            );
            $draft = $this->builder->difference($cashup, 1, $employeeId);
            $stamp = $counts ? ['table' => 'cash_up', 'key' => 'cashup_id', 'id' => $cashup->cashupId, 'null' => $row->branch_id === null ? 'branch_id' : 'cash_difference', 'columns' => ['branch_id' => $branchId, 'expected_amount_cash' => $expected, 'cash_difference' => $difference]] : [];

            return $this->checked([new BackfillStep($draft)], $source, stamp: $stamp);
        } catch (PostingException $failure) {
            return $this->refusedByPosting($failure, $source);
        }
    }

    protected function documentKey(): array
    {
        return ['cash_up', 'cashup_id'];
    }
}
