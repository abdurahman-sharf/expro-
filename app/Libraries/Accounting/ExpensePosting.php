<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Posts what happens to an expense: it is saved, changed, deleted or brought back. It runs inside
 * the transaction of the change that caused it, so the expense and its journal stand or fall
 * together. An expense has one journal, written once for each revision, and one that must change
 * is reversed and written again as the next revision, never edited.
 *
 * An expense that was saved before the books followed it has no journal and is not given one by
 * changing it: only the retroactive posting does that. It stays among the expenses that are not
 * in the books, which unposted() lists.
 */
final class ExpensePosting
{
    private BaseConnection $db;
    private AccountingSettings $settings;
    private DocumentJournals $journals;
    private ExpensePostingBuilder $builder;
    private TreasuryService $treasuries;
    private BranchGuard $branches;
    private AuditLog $audit;
    private CrossBranchPayments $crossBranch;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->branches   = $branches ?? new BranchGuard($this->db);
        $this->settings   = new AccountingSettings($this->db);
        $this->journals   = new DocumentJournals($this->db, $this->branches);
        $this->builder    = new ExpensePostingBuilder(new RoutingResolver($this->db));
        $this->treasuries = new TreasuryService($this->db, null, $this->branches);
        $this->audit      = new AuditLog($this->db);

        $this->crossBranch = new CrossBranchPayments($this->db, $this->branches);
    }

    /**
     * Whether the books follow an expense dated on that day: accounting is on and the day is on or
     * after the day accounting starts.
     */
    public function follows(string $date): bool
    {
        if (! $this->settings->isEnabled()) {
            return false;
        }

        $start = $this->settings->startDate();

        return $start === null || substr($date, 0, 10) >= $start;
    }

    /**
     * What an expense that is about to be saved needs before it is written: the branch and the
     * treasury it is stamped with, and whether the books will follow it. A new expense the books
     * follow, or one that has been stamped with a branch by the books before, is checked and
     * stamped; any other is saved as it always was.
     *
     * @param array<string, mixed> $data   The columns of the expense; treasury_id, when present, is the treasury asked for, and 0 or empty asks for the default. expense_branch_id, when present, is the branch the expense belongs to when that is not the branch of the treasury.
     * @param object|null          $stored The row as it is now, locked, or null for a new expense.
     *
     * @return array{data: array<string, mixed>, post: bool, branch_id: ?int}
     *
     * @throws AccountingException|BranchException
     */
    public function plan(array $data, ?object $stored, ?int $actorId): array
    {
        $asked       = array_key_exists('treasury_id', $data) ? (int) $data['treasury_id'] : null;
        $askedBranch = isset($data['expense_branch_id']) ? (int) $data['expense_branch_id'] : null;

        unset($data['treasury_id'], $data['branch_id'], $data['expense_branch_id'], $data['treasury_branch_id']);

        $isNew = $stored === null;

        if (! $isNew && $stored->branch_id === null) {
            return ['data' => $data, 'post' => false, 'branch_id' => null];
        }

        $date    = substr((string) ($data['date'] ?? $stored->date ?? date('Y-m-d')), 0, 10);
        $deleted = (bool) ($data['deleted'] ?? ($stored->deleted ?? 0));

        if ($isNew && ($deleted || ! $this->follows($date))) {
            return ['data' => $data, 'post' => false, 'branch_id' => null];
        }

        if (! $isNew && ($deleted || ! $this->follows($date))) {
            $this->branches->requireBranch((int) $stored->branch_id, $actorId);

            return ['data' => $data, 'post' => true, 'branch_id' => (int) $stored->branch_id];
        }

        $this->assertCategoryAccount($data, $stored);

        [$branchId, $treasuryId, $treasuryBranchId] = $this->resolve($data, $stored, $asked, $askedBranch, $actorId);

        $this->branches->requireBranch($branchId, $actorId);

        return ['data' => array_merge($data, ['branch_id' => $branchId, 'treasury_id' => $treasuryId, 'treasury_branch_id' => $treasuryBranchId]), 'post' => true, 'branch_id' => $branchId];
    }

    /**
     * Brings the journal of a saved expense in line with the expense. An expense the books follow
     * is posted, or has its journal reversed and posted again if it changed; one that was deleted or
     * moved out of the period the books follow has its journal only reversed. An expense the books
     * never followed has no branch, and stays out.
     *
     * @throws AccountingException|PostingException
     */
    public function sync(int $expenseId, ?int $actorId): JournalChange
    {
        $expense = $this->document($expenseId);

        if ($expense === null) {
            return JournalChange::None;
        }

        $wanted   = ! $expense->deleted && $this->follows($expense->date);
        $revision = $this->journals->nextRevision(ExpensePostingBuilder::SOURCE, $expenseId);
        $draft    = $wanted ? $this->builder->expense($expense, $revision, $actorId) : null;
        $drafts   = $draft === null ? [] : $this->crossBranch->drafts($draft, $expense->treasuryId, $expense->treasuryBranchId);
        $change   = $this->journals->syncAll(ExpensePostingBuilder::SOURCE, $expenseId, $drafts, $actorId, 'Expense #' . $expenseId . ($expense->deleted ? ' deleted' : ' changed'));

        $this->record($expense, $change, $draft?->revision, $actorId);

        return $change;
    }

    /**
     * Takes back the journal of an expense that is being deleted. An expense that is not in the
     * books has none, and nothing happens.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function cancel(int $expenseId, ?int $actorId): JournalChange
    {
        $active = $this->journals->active(ExpensePostingBuilder::SOURCE, $expenseId);

        if ($active === null) {
            return JournalChange::None;
        }

        $this->branches->requireBranch((int) $active->branch_id, $actorId);

        $change = $this->journals->syncAll(ExpensePostingBuilder::SOURCE, $expenseId, [], $actorId, 'Expense #' . $expenseId . ' deleted');

        $this->audit->write('expense', $expenseId, 'cancelled', $actorId, JournalOrigin::User, ['revision' => (int) $active->revision], (int) $active->branch_id);

        return $change;
    }

    public function document(int $expenseId): ?ExpenseDocument
    {
        $row = $this->db->table('expenses e')
            ->select('e.expense_id, e.date, e.amount, e.tax_amount, e.payment_type, e.treasury_id, e.treasury_branch_id, e.branch_id, e.deleted, c.account_id')
            ->join('expense_categories c', 'c.expense_category_id = e.expense_category_id', 'left')
            ->where('e.expense_id', $expenseId)
            ->get()
            ->getRow();

        if ($row === null || $row->branch_id === null) {
            return null;
        }

        return new ExpenseDocument(
            expenseId: (int) $row->expense_id,
            date: substr((string) $row->date, 0, 10),
            amount: bcadd((string) $row->amount, '0', SaleAmounts::SCALE),
            taxAmount: bcadd((string) ($row->tax_amount ?? '0'), '0', SaleAmounts::SCALE),
            paymentType: (string) $row->payment_type,
            categoryAccountId: $row->account_id === null ? null : (int) $row->account_id,
            treasuryId: $row->treasury_id === null ? null : (int) $row->treasury_id,
            branchId: (int) $row->branch_id,
            deleted: (int) $row->deleted === 1,
            treasuryBranchId: $row->treasury_branch_id === null ? null : (int) $row->treasury_branch_id,
        );
    }

    /**
     * The expenses that are not in the books, newest first: the ones saved before the books
     * followed expenses, the ones dated before accounting starts, and the ones saved while
     * accounting was off. An expense without a branch counts as one of the default branch.
     *
     * @param list<int>|null $branchIds The branches the reader may see, null for every branch.
     *
     * @return list<object>
     */
    public function unposted(?array $branchIds = null): array
    {
        return $this->unpostedBuilder($branchIds)
            ->select('e.expense_id, e.date, e.amount, e.tax_amount, e.payment_type, e.branch_id, c.category_name')
            ->join('expense_categories c', 'c.expense_category_id = e.expense_category_id', 'left')
            ->orderBy('e.date', 'DESC')
            ->orderBy('e.expense_id', 'DESC')
            ->get()
            ->getResult();
    }

    /**
     * @param list<int>|null $branchIds
     */
    public function unpostedCount(?array $branchIds = null): int
    {
        return $this->unpostedBuilder($branchIds)->countAllResults();
    }

    /**
     * What the books said about the expense that was just refused. It must run after the
     * transaction of the expense has rolled back, or the row would go with it.
     */
    public function recordFailure(?int $expenseId, PostingException $failure, ?int $actorId = null, ?int $branchId = null): void
    {
        (new PostingFailureLog($this->db))->recordUnbuilt(ExpensePostingBuilder::SOURCE, $expenseId, 1, JournalEntryType::General, $failure, $actorId, $branchId);
    }

    /**
     * The journals of an expense, oldest first.
     *
     * @return list<object>
     */
    public function journalsOf(int $expenseId): array
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, number, journal_date, status, revision, total_debit, reverses_journal_id')
            ->where('source_type', ExpensePostingBuilder::SOURCE)
            ->where('source_id', $expenseId)
            ->orderBy('journal_id', 'ASC')
            ->get()
            ->getResult();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function unpostedBuilder(?array $branchIds): BaseBuilder
    {
        $default = $this->branches->companyDefaultBranchId();
        $builder = $this->db->table('expenses e')
            ->where('e.deleted', 0)
            ->where('e.amount !=', 0)
            ->where(
                'NOT EXISTS (SELECT 1 FROM ' . $this->db->prefixTable('accounting_journals') . ' j WHERE j.source_type = ' . $this->db->escape(ExpensePostingBuilder::SOURCE) . ' AND j.source_id = e.expense_id AND j.status = ' . $this->db->escape(JournalStatus::Posted->value) . ')',
                null,
                false,
            );

        if ($branchIds === null) {
            return $builder;
        }

        if ($branchIds === []) {
            return $builder->where('1 = 0', null, false);
        }

        $builder->groupStart()->whereIn('e.branch_id', $branchIds);

        if (in_array($default, $branchIds, true)) {
            $builder->orWhere('e.branch_id IS NULL', null, false);
        }

        return $builder->groupEnd();
    }

    /**
     * A category without an account is refused for a new expense, or when the expense moves to such
     * a category, if the settings ask every expense to be charged to an account of its own.
     *
     * @param array<string, mixed> $data
     *
     * @throws AccountingException
     */
    private function assertCategoryAccount(array $data, ?object $stored): void
    {
        if (! $this->settings->expenseAccountRequired()) {
            return;
        }

        $categoryId = (int) ($data['expense_category_id'] ?? $stored->expense_category_id ?? 0);

        if ($stored !== null && $categoryId === (int) $stored->expense_category_id) {
            return;
        }

        $category = $this->db->table('expense_categories')->select('account_id')->where('expense_category_id', $categoryId)->get()->getRow();

        if ($category === null || $category->account_id === null) {
            throw new AccountingException(AccountingError::ExpenseCategoryAccountRequired, 'The category of the expense has no account.', ['category_id' => $categoryId]);
        }
    }

    /**
     * The branch an expense belongs to and the treasury it is paid from. A treasury that is named
     * decides the branch, unless the expense is asked to belong to another, which an employee who
     * works in both branches may do and which is paid across the branches. It must be active unless
     * the expense already used it. Without one, an expense that has been posted keeps its treasury, and a
     * new one is paid from the treasury its employee works from, when that is open to them, or else
     * from the account of its way of payment. An expense that is owed, or has no way of payment the
     * books know, uses no treasury.
     *
     * @param array<string, mixed> $data
     *
     * @return array{int, ?int, ?int} The branch, the treasury and the branch of the treasury when it is not the branch of the expense.
     *
     * @throws AccountingException|BranchException
     */
    private function resolve(array $data, ?object $stored, ?int $asked, ?int $askedBranch, ?int $actorId): array
    {
        $type           = PaymentType::fromLabel((string) ($data['payment_type'] ?? $stored->payment_type ?? ''));
        $usesTreasury   = $type !== null && $type->usesTreasury();
        $storedType     = $stored === null ? null : PaymentType::fromLabel((string) $stored->payment_type);
        $storedTreasury = $stored === null || $stored->treasury_id === null ? null : (int) $stored->treasury_id;
        $amount         = (string) ($data['amount'] ?? $stored->amount ?? '0');
        $direction      = bccomp($amount === '' ? '0' : $amount, '0', SaleAmounts::SCALE) < 0 ? TreasuryScopes::DEPOSIT : TreasuryScopes::WITHDRAW;

        if (! $usesTreasury) {
            return [$stored === null ? $this->branches->defaultBranchId($actorId) : (int) $stored->branch_id, null, null];
        }

        if ($asked !== null && $asked > 0) {
            $treasury = $this->treasuries->find($asked);

            if ($treasury === null) {
                throw new AccountingException(AccountingError::TreasuryNotFound, "Treasury {$asked} does not exist.", ['treasury_id' => $asked]);
            }

            if ((int) $treasury->active === 0 && $asked !== $storedTreasury) {
                throw new AccountingException(AccountingError::TreasuryInactive, 'An expense cannot be paid from a deactivated treasury.', ['treasury_id' => $asked]);
            }

            $keepsOtherBranch = $stored !== null && $asked === $storedTreasury && $stored->treasury_branch_id !== null;
            $branchId         = $askedBranch !== null && $askedBranch > 0 ? $askedBranch : ($keepsOtherBranch ? (int) $stored->branch_id : (int) $treasury->branch_id);

            return [$branchId, $asked, $this->treasuries->otherBranchOf($asked, $branchId, $actorId, $asked === $storedTreasury)];
        }

        if ($asked === null && $stored !== null && $storedType !== null && $storedType->usesTreasury()) {
            return [(int) $stored->branch_id, $storedTreasury, $stored->treasury_branch_id === null ? null : (int) $stored->treasury_branch_id];
        }

        $branchId = $stored === null ? $this->branches->defaultBranchId($actorId) : (int) $stored->branch_id;

        return [$branchId, $this->treasuries->stampFor($actorId, $branchId, $direction), null];
    }

    private function record(ExpenseDocument $expense, JournalChange $change, ?int $revision, ?int $actorId): void
    {
        $action = match ($change) {
            JournalChange::Posted   => $revision !== null && $revision > 1 ? 'reposted' : 'posted',
            JournalChange::Replaced => 'reposted',
            JournalChange::Reversed => 'cancelled',
            default                 => null,
        };

        if ($action === null) {
            return;
        }

        $this->audit->write('expense', $expense->expenseId, $action, $actorId, JournalOrigin::User, [
            'amount'      => $expense->amount,
            'tax_amount'  => $expense->taxAmount,
            'treasury_id' => $expense->treasuryId,
            'revision'    => $revision,
        ], $expense->branchId);
    }
}
