<?php

namespace App\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\ExpensePosting;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;

/**
 * The expenses that were saved and have no journal: what each would have posted when it was saved. An
 * expense that was never stamped with a branch belongs to the default branch of the company, as the
 * list of what is not in the books already counts it, and is stamped with it when the batch is approved.
 */
final class ExpenseBackfill extends BackfillAdapter
{
    private ExpensePosting $documents;
    private ExpensePostingBuilder $builder;
    private CrossBranchPayments $crossBranch;
    private int $defaultBranchId;

    public function __construct(BaseConnection $db, ?BackfillGate $gate = null)
    {
        parent::__construct($db, $gate);

        $this->documents       = new ExpensePosting($this->db);
        $this->builder         = new ExpensePostingBuilder(new RoutingResolver($this->db));
        $this->crossBranch     = new CrossBranchPayments($this->db);
        $this->defaultBranchId = (new BranchGuard($this->db))->companyDefaultBranchId();
    }

    public function family(): BackfillFamily
    {
        return BackfillFamily::Expenses;
    }

    public function candidates(BackfillScope $scope, int $afterId, int $limit, int $batchId): array
    {
        $branch = 'COALESCE(e.branch_id, ' . $this->defaultBranchId . ')';
        $params = [$scope->from, $scope->to];

        if ($scope->branchId !== null) {
            $params[] = $scope->branchId;
        }

        array_push($params, $afterId, $batchId);

        $rows = $this->db->query(
            "SELECT e.expense_id AS id, DATE(e.date) AS document_date, {$branch} AS branch_id FROM " . $this->db->prefixTable('expenses') . ' e'
            . ' WHERE e.deleted = 0 AND e.amount <> 0 AND DATE(e.date) BETWEEN ? AND ?'
            . ($scope->branchId === null ? '' : " AND {$branch} = ?")
            . ' AND e.expense_id > ? AND ' . $this->noJournal('e.expense_id', [ExpensePostingBuilder::SOURCE, ...CrossBranchPayments::companionsOf(ExpensePostingBuilder::SOURCE)])
            . ' ORDER BY e.expense_id ASC LIMIT ' . max(1, $limit),
            $params,
        )->getResult();

        return array_map(static fn (object $row): BackfillDocument => new BackfillDocument(BackfillFamily::Expenses, (int) $row->id, (string) $row->document_date, (int) $row->branch_id), $rows);
    }

    public function plan(BackfillDocument $document, ?int $employeeId, int $batchId): BackfillPlan
    {
        $stored  = $this->db->table('expenses')->select('branch_id, deleted')->where('expense_id', $document->id)->get()->getRow();
        $expense = $stored === null || (int) $stored->deleted === 1 ? null : $this->documents->document($document->id, $this->defaultBranchId);

        if ($expense === null) {
            return BackfillPlan::refused(BackfillRefusal::DocumentChanged, '', 'expense');
        }

        $source = (string) json_encode([$expense, $stored->branch_id === null], JSON_UNESCAPED_UNICODE);
        $early  = $this->gate->refusalOf($expense->date, $expense->branchId);

        if ($early !== null) {
            return BackfillPlan::refused($early, $source);
        }

        if ($this->treasuryGone($expense->treasuryId)) {
            return BackfillPlan::refused(BackfillRefusal::TreasuryMissing, $source, (string) $expense->treasuryId);
        }

        if ($this->hasOtherJournals([ExpensePostingBuilder::SOURCE, ...CrossBranchPayments::companionsOf(ExpensePostingBuilder::SOURCE)], [$expense->expenseId], $batchId)) {
            return BackfillPlan::refused(BackfillRefusal::PartlyPosted, $source, 'expense');
        }

        try {
            $draft = $this->builder->expense($expense, 1, $employeeId);

            if ($draft !== null && count($this->crossBranch->drafts($draft, $expense->treasuryId, $expense->treasuryBranchId)) > 1) {
                return BackfillPlan::refused(BackfillRefusal::CrossBranchPayment, $source, (string) $expense->treasuryId);
            }

            $stamp = $stored->branch_id === null ? ['table' => 'expenses', 'key' => 'expense_id', 'id' => $expense->expenseId, 'null' => 'branch_id', 'columns' => ['branch_id' => $expense->branchId]] : [];

            return $this->checked([new BackfillStep($draft)], $source, stamp: $stamp);
        } catch (PostingException $failure) {
            return $this->refusedByPosting($failure, $source);
        }
    }

    protected function documentKey(): array
    {
        return ['expenses', 'expense_id'];
    }
}
