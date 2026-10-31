<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;

/**
 * The closing of a fiscal year for the branches of a run: one journal for each branch that moves
 * what the year's revenue and expense accounts hold to the retained earnings account of the
 * routing, so a profit is a credit there and a loss a debit, and nothing moves between branches.
 * It is made in two steps. The preview reads the books and writes nothing, and the approval writes
 * the journals only for the figures the preview showed. A closing is never edited or deleted: it is
 * reversed, on the same day, and the books are as they were.
 *
 * The year has to be closed for the whole company already and hold no draft. The closing of a
 * year is written inside that closed period, which only this class may do (PostingService::postYearEnd).
 * A branch has at most one active closing for a year, held by a key of the database and by the
 * lock of the branch, which a run takes before it reads anything, the branches of a company run in
 * the order of their numbers.
 *
 * A closing takes the movement of the year, not the balance the accounts have carried since the
 * books began, so the years of books that were never closed are closed one by one in any order.
 */
final class YearEndClosing
{
    use RunsInTransaction;

    private const SCALE      = 4;
    private const REASON_MAX = 255;

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PeriodLocks $locks;
    private PostingService $posting;
    private ClosedOutYears $closedOut;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db        = $db ?? Database::connect();
        $this->audit     = new AuditLog($this->db);
        $this->guard     = $guard ?? new AccountingGuard();
        $this->branches  = $branches ?? new BranchGuard($this->db);
        $this->locks     = new PeriodLocks($this->db);
        $this->posting   = new PostingService($this->db);
        $this->closedOut = new ClosedOutYears($this->db);
    }

    /**
     * The years that have ended, the latest first, with whether the company has closed its periods
     * through the last day and how many closings of the year are active.
     *
     * @return list<object>
     */
    public function years(): array
    {
        $cutoff   = $this->companyCutoff(false);
        $years    = $this->db->prefixTable('accounting_fiscal_years');
        $closings = $this->db->prefixTable('accounting_year_end_closings');

        $rows = $this->db->query(
            "SELECT y.fiscal_year_id, y.name, y.start_date, y.end_date,
                (SELECT COUNT(*) FROM {$closings} c WHERE c.fiscal_year_id = y.fiscal_year_id AND c.status = 'active') AS closing_count
             FROM {$years} y
             WHERE y.company_id = ? AND y.end_date < ?
             ORDER BY y.start_date DESC",
            [CompanyContext::id(), date('Y-m-d')],
        )->getResult();

        foreach ($rows as $row) {
            $row->period_closed = $cutoff !== null && $cutoff >= $row->end_date;
        }

        return $rows;
    }

    /**
     * What closing the year would write for the branches of the run, and what stops it. Nothing is
     * written. A null branch is the whole company.
     *
     * @throws AccountingException|BranchException
     */
    public function preview(?int $branchId, int $fiscalYearId, ?int $employeeId): YearEndPreview
    {
        $this->requireRun($branchId, $employeeId);

        return $this->plan($branchId, $fiscalYearId, false);
    }

    /**
     * Writes the closing journal of every branch of the run that has something to close, the
     * figures being those of the preview whose fingerprint is given. Either all of them are written
     * or none.
     *
     * @return list<int> The closings made, in the order of their branches.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function approve(?int $branchId, int $fiscalYearId, string $fingerprint, ?int $employeeId): array
    {
        $this->requireRun($branchId, $employeeId);

        return $this->transactional(function () use ($branchId, $fiscalYearId, $fingerprint, $employeeId): array {
            foreach ($this->scopeBranchIds($branchId) as $lockedBranchId) {
                $this->locks->holdBranch($lockedBranchId);
            }

            $preview = $this->plan($branchId, $fiscalYearId, true);

            if ($preview->blockers !== []) {
                throw $preview->blockers[0];
            }

            $ready = $preview->ready();

            if ($ready === []) {
                $closed = array_filter($preview->branches, static fn (YearEndBranchPlan $plan): bool => $plan->status === YearEndBranchStatus::AlreadyClosed);

                throw $closed === []
                    ? new AccountingException(AccountingError::YearEndNothingToClose, 'The year holds nothing to close in these branches.', ['name' => $preview->year->name])
                    : new AccountingException(AccountingError::YearEndAlreadyClosed, 'The year is already closed for these branches.', ['name' => $preview->year->name]);
            }

            if (! hash_equals($preview->fingerprint, $fingerprint)) {
                throw new AccountingException(AccountingError::YearEndPreviewChanged, 'The books changed since the preview was made.');
            }

            return array_map(fn (YearEndBranchPlan $plan): int => $this->close($plan, $preview, $employeeId), $ready);
        });
    }

    /**
     * Takes a closing back: its journal is mirrored on the day it was written, so every report of
     * every day reads as it did before the year was closed, and the branch may close the year again.
     *
     * @return int The journal of the reversal.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function reverse(int $closingId, string $reason, ?int $employeeId): int
    {
        $this->guard->require(AccountingGuard::YEAR_END_CLOSE, $employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::REASON_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        $known = $this->db->table('accounting_year_end_closings')->where('company_id', CompanyContext::id())->where('closing_id', $closingId)->get()->getRow();

        if ($known === null) {
            throw new AccountingException(AccountingError::YearEndNotFound, "Closing {$closingId} does not exist.");
        }

        $this->branches->requireBranch((int) $known->branch_id, $employeeId);

        return $this->transactional(function () use ($closingId, $reason, $employeeId, $known): int {
            $this->locks->holdBranch((int) $known->branch_id);

            $table   = $this->db->prefixTable('accounting_year_end_closings');
            $closing = LockedRead::row($this->db, "SELECT * FROM {$table} WHERE closing_id = ? FOR UPDATE", [$closingId]);

            if ($closing === null || $closing->status !== 'active') {
                throw new AccountingException(AccountingError::YearEndAlreadyReversed, 'The closing is already taken back.');
            }

            $journals = $this->db->prefixTable('accounting_journals');
            $original = LockedRead::row($this->db, "SELECT * FROM {$journals} WHERE journal_id = ? FOR UPDATE", [(int) $closing->journal_id]);

            if ($original === null || $original->status !== JournalStatus::Posted->value) {
                throw new AccountingException(AccountingError::NotPosted, 'The journal of the closing is not posted.');
            }

            $lines = array_map(static fn (object $line): JournalLineDraft => new JournalLineDraft(
                accountId: (int) $line->account_id,
                debit: $line->credit,
                credit: $line->debit,
                description: $line->description,
            ), $this->db->table('accounting_journal_lines')->where('journal_id', (int) $original->journal_id)->orderBy('line_no', 'ASC')->get()->getResult());

            $result = $this->posting->postYearEnd(new JournalDraft(
                date: $original->journal_date,
                lines: $lines,
                sourceType: JournalDraft::SOURCE_REVERSAL,
                sourceId: (int) $original->journal_id,
                revision: 1,
                origin: $this->originOf($employeeId),
                employeeId: $employeeId,
                description: $reason,
                currencyCode: $original->currency_code,
                exchangeRate: $original->exchange_rate,
                reversesJournalId: (int) $original->journal_id,
                branchId: (int) $closing->branch_id,
                entryType: JournalEntryType::YearEnd,
            ));

            $this->db->table('accounting_journals')->where('journal_id', (int) $original->journal_id)->update(['status' => JournalStatus::Reversed->value]);
            $this->db->table('accounting_year_end_closings')->where('closing_id', $closingId)->update([
                'status'              => 'reversed',
                'active_key'          => null,
                'reversal_journal_id' => $result->journalId,
                'reversed_by'         => $employeeId,
                'reversed_at'         => new RawSql('CURRENT_TIMESTAMP'),
                'reverse_reason'      => $reason,
            ]);

            $origin = $this->originOf($employeeId);
            $this->audit->write('journal', (int) $original->journal_id, 'reversed', $employeeId, $origin, ['reversal_id' => $result->journalId, 'date' => $original->journal_date, 'reason' => $reason], (int) $closing->branch_id);
            $this->audit->write('year_end_closing', $closingId, 'year_end_reversed', $employeeId, $origin, [
                'fiscal_year_id'      => (int) $closing->fiscal_year_id,
                'journal_id'          => (int) $original->journal_id,
                'reversal_id'         => $result->journalId,
                'date'                => $original->journal_date,
                'reason'              => $reason,
                'closed_period_until' => $this->companyCutoff(true),
            ], (int) $closing->branch_id);

            return $result->journalId;
        });
    }

    /**
     * The closings of the branches the employee may see, the latest year first, one year only when given.
     *
     * @return list<object>
     */
    public function closings(?int $employeeId, ?int $fiscalYearId = null): array
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);

        if ($allowed === []) {
            return [];
        }

        $builder = $this->db->table('accounting_year_end_closings c')
            ->select("c.closing_id, c.branch_id, b.code AS branch_code, b.name AS branch_name, c.fiscal_year_id, y.name AS year_name, c.period_from, c.period_to, c.result_amount, c.line_count, c.status, c.journal_id, c.reversal_journal_id, c.revision, c.created_at, c.reversed_at, c.reverse_reason, TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS created_by_name, TRIM(CONCAT(rp.first_name, ' ', rp.last_name)) AS reversed_by_name", false)
            ->join('branches b', 'b.branch_id = c.branch_id')
            ->join('accounting_fiscal_years y', 'y.fiscal_year_id = c.fiscal_year_id')
            ->join('people cp', 'cp.person_id = c.created_by', 'left')
            ->join('people rp', 'rp.person_id = c.reversed_by', 'left')
            ->where('c.company_id', CompanyContext::id());

        if ($allowed !== null) {
            $builder->whereIn('c.branch_id', $allowed);
        }

        if ($fiscalYearId !== null) {
            $builder->where('c.fiscal_year_id', $fiscalYearId);
        }

        return $builder->orderBy('c.period_to', 'DESC')->orderBy('b.code', 'ASC')->orderBy('c.closing_id', 'DESC')->get()->getResult();
    }

    private function close(YearEndBranchPlan $plan, YearEndPreview $preview, ?int $employeeId): int
    {
        $year        = $preview->year;
        $closingsTbl = $this->db->prefixTable('accounting_year_end_closings');
        $latest      = LockedRead::row($this->db, "SELECT COALESCE(MAX(revision), 0) AS latest FROM {$closingsTbl} WHERE company_id = ? AND branch_id = ? AND fiscal_year_id = ? FOR UPDATE", [CompanyContext::id(), $plan->branchId, (int) $year->fiscal_year_id]);

        $inserted = $this->db->table('accounting_year_end_closings')->insert([
            'company_id'          => CompanyContext::id(),
            'branch_id'           => $plan->branchId,
            'fiscal_year_id'      => (int) $year->fiscal_year_id,
            'revision'            => (int) $latest->latest + 1,
            'period_from'         => $year->start_date,
            'period_to'           => $year->end_date,
            'retained_account_id' => (int) $preview->retainedAccount->account_id,
            'result_amount'       => $plan->result,
            'line_count'          => count($plan->lines),
            'fingerprint'         => $this->branchFingerprint($plan),
            'status'              => 'active',
            'active_key'          => 1,
            'created_by'          => $employeeId,
        ]);

        if ($inserted === false) {
            throw new AccountingException(AccountingError::WriteFailed, 'The closing could not be saved.');
        }

        $closingId = (int) $this->db->insertID();
        $result    = $this->posting->postYearEnd(new JournalDraft(
            date: $year->end_date,
            lines: $this->journalLines($plan, (int) $preview->retainedAccount->account_id),
            sourceType: YearEndEntries::SOURCE_TYPE,
            sourceId: $closingId,
            revision: 1,
            origin: $this->originOf($employeeId),
            employeeId: $employeeId,
            description: 'Year-end closing ' . $year->name,
            branchId: $plan->branchId,
            entryType: JournalEntryType::YearEnd,
        ));

        $this->db->table('accounting_year_end_closings')->where('closing_id', $closingId)->update(['journal_id' => $result->journalId]);

        $this->audit->write('year_end_closing', $closingId, 'year_end_closed', $employeeId, $this->originOf($employeeId), [
            'fiscal_year_id'          => (int) $year->fiscal_year_id,
            'journal_id'              => $result->journalId,
            'date'                    => $year->end_date,
            'result'                  => $plan->result,
            'lines'                   => count($plan->lines),
            'closed_period_exception' => true,
            'closed_period_until'     => $preview->companyCutoff,
        ], $plan->branchId);

        return $closingId;
    }

    /**
     * @return list<JournalLineDraft>
     */
    private function journalLines(YearEndBranchPlan $plan, int $retainedAccountId): array
    {
        $lines = [];

        foreach ($plan->lines as $line) {
            $lines[] = new JournalLineDraft($line->accountId, debit: $line->closingDebit(), credit: $line->closingCredit());
        }

        if ($plan->isProfit()) {
            $lines[] = new JournalLineDraft($retainedAccountId, credit: $plan->result);
        } elseif ($plan->isLoss()) {
            $lines[] = new JournalLineDraft($retainedAccountId, debit: bcsub('0', $plan->result, self::SCALE));
        }

        return $lines;
    }

    /**
     * @throws AccountingException|BranchException
     */
    private function plan(?int $branchId, int $fiscalYearId, bool $locked): YearEndPreview
    {
        $year     = $this->requireYear($fiscalYearId);
        $scope    = $this->scopeBranches($branchId);
        $blockers = [];
        $cutoff   = $this->companyCutoff($locked);

        if ($cutoff === null || $cutoff < $year->end_date) {
            $blockers[] = new AccountingException(AccountingError::YearEndPeriodNotClosed, 'The periods of the company are not closed through the end of the year.', ['name' => $year->name, 'date' => $year->end_date]);
        }

        $drafts = $this->draftCount(array_map(static fn (object $branch): int => (int) $branch->branch_id, $scope), $year);

        if ($drafts > 0) {
            $blockers[] = new AccountingException(AccountingError::YearEndHasDrafts, 'Draft journals lie in the year.', ['name' => $year->name, 'count' => $drafts]);
        }

        [$account, $accountBlocker] = $this->retainedAccount();

        if ($accountBlocker !== null) {
            $blockers[] = $accountBlocker;
        }

        $plans = [];

        foreach ($scope as $branch) {
            $plans[] = $this->branchPlan($branch, $year, $locked);
        }

        return new YearEndPreview($year, $branchId === null, $cutoff, $drafts, $account, $plans, $blockers, $this->fingerprint($year, $account, $plans));
    }

    private function branchPlan(object $branch, object $year, bool $locked): YearEndBranchPlan
    {
        $closing = $locked ? $this->lockedClosing((int) $branch->branch_id, (int) $year->fiscal_year_id) : $this->closedOut->activeOf((int) $branch->branch_id, (int) $year->fiscal_year_id);

        if ($closing !== null) {
            return new YearEndBranchPlan((int) $branch->branch_id, (string) $branch->code, (string) $branch->name, (int) $branch->active === 1, YearEndBranchStatus::AlreadyClosed, [], '0.0000', '0.0000', bcadd((string) $closing->result_amount, '0', self::SCALE), $closing);
        }

        $lines   = $this->lines((int) $branch->branch_id, $year);
        $revenue = '0.0000';
        $expense = '0.0000';

        foreach ($lines as $line) {
            if ($line->type === AccountType::Revenue) {
                $revenue = bcsub($revenue, $line->net, self::SCALE);
            } else {
                $expense = bcadd($expense, $line->net, self::SCALE);
            }
        }

        return new YearEndBranchPlan(
            (int) $branch->branch_id,
            (string) $branch->code,
            (string) $branch->name,
            (int) $branch->active === 1,
            $lines === [] ? YearEndBranchStatus::NoActivity : YearEndBranchStatus::Ready,
            $lines,
            $revenue,
            $expense,
            bcsub($revenue, $expense, self::SCALE),
            null,
        );
    }

    /**
     * What each account of revenue and expense stands at in the branch for the days of the year, the
     * journals that close a year left out, an account whose debits and credits cancel left off.
     *
     * @return list<YearEndLine>
     */
    private function lines(int $branchId, object $year): array
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->select('l.account_id, a.code, a.name, a.account_type, SUM(l.debit) - SUM(l.credit) AS net', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('accounting_accounts a', 'a.account_id = l.account_id')
            ->where('j.company_id', CompanyContext::id())
            ->where('j.branch_id', $branchId)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->where('j.journal_date >=', $year->start_date)
            ->where('j.journal_date <=', $year->end_date)
            ->whereIn('a.account_type', [AccountType::Revenue->value, AccountType::Expense->value])
            ->groupBy('l.account_id, a.code, a.name, a.account_type')
            ->having('SUM(l.debit) <> SUM(l.credit)', null, false)
            ->orderBy('a.code', 'ASC');

        YearEndEntries::leaveOut($builder, $this->db);

        return array_map(static fn (object $row): YearEndLine => new YearEndLine(
            (int) $row->account_id,
            (string) $row->code,
            (string) $row->name,
            AccountType::from((string) $row->account_type),
            bcadd((string) $row->net, '0', self::SCALE),
        ), $builder->get()->getResult());
    }

    /**
     * @param list<int> $branchIds
     */
    private function draftCount(array $branchIds, object $year): int
    {
        if ($branchIds === []) {
            return 0;
        }

        return $this->db->table('accounting_journals')
            ->where('company_id', CompanyContext::id())
            ->where('status', JournalStatus::Draft->value)
            ->whereIn('branch_id', $branchIds)
            ->where('journal_date >=', $year->start_date)
            ->where('journal_date <=', $year->end_date)
            ->countAllResults();
    }

    /**
     * @return array{0: ?object, 1: ?AccountingException} The account of the routing, or why there is none.
     */
    private function retainedAccount(): array
    {
        try {
            $accountId = (new RoutingResolver($this->db))->accountFor(RoutingKey::RetainedEarnings);
        } catch (PostingException $failure) {
            return [null, new AccountingException(AccountingError::YearEndRetainedEarningsUnset, 'The retained earnings account of the routing is missing, a group or inactive.', [], $failure)];
        }

        $account = $accountId === null ? null : $this->db->table('accounting_accounts')->where('company_id', CompanyContext::id())->where('account_id', $accountId)->get()->getRow();

        if ($account === null || $account->account_type !== AccountType::Equity->value || (int) $account->is_group === 1 || (int) $account->active === 0) {
            return [null, new AccountingException(AccountingError::YearEndRetainedEarningsUnset, 'The retained earnings account of the routing is not an account of equity that takes entries.')];
        }

        return [$account, null];
    }

    /**
     * @param list<YearEndBranchPlan> $plans
     */
    private function fingerprint(object $year, ?object $account, array $plans): string
    {
        return hash('sha256', (string) json_encode([
            (int) $year->fiscal_year_id,
            $year->start_date,
            $year->end_date,
            $account === null ? null : (int) $account->account_id,
            array_map(fn (YearEndBranchPlan $plan): array => [$plan->branchId, $plan->status->value, $this->branchFingerprint($plan)], $plans),
        ]));
    }

    private function branchFingerprint(YearEndBranchPlan $plan): string
    {
        return hash('sha256', (string) json_encode(array_map(static fn (YearEndLine $line): array => [$line->accountId, $line->net], $plan->lines)));
    }

    private function lockedClosing(int $branchId, int $fiscalYearId): ?object
    {
        $table = $this->db->prefixTable('accounting_year_end_closings');

        return LockedRead::row($this->db, "SELECT * FROM {$table} WHERE company_id = ? AND branch_id = ? AND fiscal_year_id = ? AND status = 'active' FOR UPDATE", [CompanyContext::id(), $branchId, $fiscalYearId]);
    }

    /**
     * The date the periods of the company are closed through. With $locked the answer is what is
     * committed now, read under a shared lock.
     */
    private function companyCutoff(bool $locked): ?string
    {
        if (! $locked) {
            return (new ClosedPeriodService($this->db, $this->guard, $this->branches))->cutoff(null);
        }

        $table = $this->db->prefixTable('accounting_closed_periods');
        $row   = LockedRead::row($this->db, "SELECT MAX(date_to) AS cutoff FROM {$table} WHERE company_id = ? AND branch_id IS NULL AND active = 1 LOCK IN SHARE MODE", [CompanyContext::id()]);

        return $row === null || $row->cutoff === null ? null : (string) $row->cutoff;
    }

    /**
     * @throws AccountingException
     */
    private function requireYear(int $fiscalYearId): object
    {
        $year = $this->db->table('accounting_fiscal_years')->where('company_id', CompanyContext::id())->where('fiscal_year_id', $fiscalYearId)->get()->getRow();

        if ($year === null) {
            throw new AccountingException(AccountingError::YearEndYearNotFound, "Fiscal year {$fiscalYearId} does not exist.");
        }

        return $year;
    }

    /**
     * A run for the company needs every branch, a run for a branch needs that branch.
     *
     * @throws AccountingException|BranchException
     */
    private function requireRun(?int $branchId, ?int $employeeId): void
    {
        $this->guard->require(AccountingGuard::YEAR_END_CLOSE, $employeeId);

        if ($branchId === null) {
            $this->branches->requireCompanyScope($employeeId);

            return;
        }

        $exists = $this->db->table('branches')->where('branch_id', $branchId)->where('company_id', CompanyContext::id())->countAllResults() > 0;

        if (! $exists) {
            throw new BranchException(BranchError::NotFound, "Branch {$branchId} does not exist.", ['branch_id' => $branchId]);
        }

        $this->branches->requireBranch($branchId, $employeeId);
    }

    /**
     * @return list<object> The branches of the run in the order of their numbers, one that was deactivated included.
     */
    private function scopeBranches(?int $branchId): array
    {
        $builder = $this->db->table('branches')->select('branch_id, code, name, active')->where('company_id', CompanyContext::id());

        if ($branchId !== null) {
            $builder->where('branch_id', $branchId);
        }

        return $builder->orderBy('branch_id', 'ASC')->get()->getResult();
    }

    /**
     * @return list<int>
     */
    private function scopeBranchIds(?int $branchId): array
    {
        return array_map(static fn (object $branch): int => (int) $branch->branch_id, $this->scopeBranches($branchId));
    }

    private function originOf(?int $employeeId): JournalOrigin
    {
        return $employeeId === null ? JournalOrigin::System : JournalOrigin::User;
    }
}
