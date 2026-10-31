<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\RawSql;
use Config\Database;
use DateTimeImmutable;

/**
 * Closing of periods, for the whole company or for one branch. A closing says that nothing
 * can be posted on or before its date. Each scope only moves forward, and only the latest
 * closing of a scope can be reopened. A journal is held back by the later of the company's
 * closing and its own branch's, so the company can close a month the branches have not, and a
 * branch can close ahead of the company.
 *
 * A null branch id means the company.
 */
final class ClosedPeriodService
{
    use RunsInTransaction;

    private const NOTE_MAX        = 255;
    private const DRAFT_SAMPLE    = 10;
    private const SEARCH_MAX_ROWS = 500;

    private BaseConnection $db;
    private AuditLog $audit;
    private AccountingGuard $guard;
    private BranchGuard $branches;
    private PeriodLocks $locks;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->audit    = new AuditLog($this->db);
        $this->guard    = $guard ?? new AccountingGuard();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->locks    = new PeriodLocks($this->db);
    }

    /**
     * The latest date the scope has closed, null when it has closed nothing.
     */
    public function cutoff(?int $branchId): ?string
    {
        $builder = $this->db->table('accounting_closed_periods')
            ->selectMax('date_to', 'cutoff')
            ->where('company_id', CompanyContext::id())
            ->where('active', 1);

        $branchId === null ? $builder->where('branch_id IS NULL', null, false) : $builder->where('branch_id', $branchId);

        $row = $builder->get()->getRow();

        return $row === null || $row->cutoff === null ? null : (string) $row->cutoff;
    }

    /**
     * The company's closing and that of every branch the employee may see.
     *
     * @return array{company: ?string, branches: list<object>}
     */
    public function summary(?int $employeeId): array
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);
        $company = $this->cutoff(null);

        if ($allowed === []) {
            return ['company' => $company, 'branches' => []];
        }

        $table  = $this->db->prefixTable('accounting_closed_periods');
        $params = [CompanyContext::id()];
        $where  = 'b.company_id = ?';

        if ($allowed !== null) {
            $where .= ' AND b.branch_id IN (' . implode(',', array_map('intval', $allowed)) . ')';
        }

        $rows = $this->db->query(
            "SELECT b.branch_id, b.code, b.name, b.active,
                (SELECT MAX(p.date_to) FROM {$table} p WHERE p.company_id = b.company_id AND p.branch_id = b.branch_id AND p.active = 1) AS cutoff
             FROM " . $this->db->prefixTable('branches') . " b
             WHERE {$where}
             ORDER BY b.is_default DESC, b.code ASC",
            $params,
        )->getResult();

        return ['company' => $company, 'branches' => $rows];
    }

    /**
     * What closing the scope on a date would close, and the drafts that would stop it.
     *
     * @return array{previous: ?string, journals: int, total_debit: string, draft_count: int, drafts: list<object>}
     *
     * @throws AccountingException|BranchException
     */
    public function preview(?int $branchId, string $date, ?int $employeeId): array
    {
        $this->guard->require(AccountingGuard::PERIODS_CLOSE, $employeeId);
        $this->requireScope($branchId, $employeeId);
        $this->assertDate($date);

        $previous = $this->cutoff($branchId);
        $journals = $this->db->table('accounting_journals')
            ->select('COUNT(*) AS journals, COALESCE(SUM(total_debit), 0) AS total_debit', false)
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->where('journal_date <=', $date);

        if ($previous !== null) {
            $journals->where('journal_date >', $previous);
        }

        if ($branchId !== null) {
            $journals->where('branch_id', $branchId);
        }

        $totals = $journals->get()->getRow();
        $drafts = $this->drafts($branchId, $date);

        return [
            'previous'    => $previous,
            'journals'    => (int) $totals->journals,
            'total_debit' => (string) $totals->total_debit,
            'draft_count' => $drafts['count'],
            'drafts'      => $drafts['rows'],
        ];
    }

    /**
     * Closes the scope up to and including the date. The scope's lock is taken before anything
     * is read, so the transaction this method starts sees every closing committed before it
     * got the lock. Two closings of the same scope therefore run one after the other, and the
     * second finds the first.
     *
     * @throws AccountingException|BranchException
     */
    public function close(?int $branchId, string $date, string $note, ?int $employeeId): int
    {
        $this->guard->require(AccountingGuard::PERIODS_CLOSE, $employeeId);
        $this->requireScope($branchId, $employeeId);
        $this->assertDate($date);

        $note = trim($note);

        if (mb_strlen($note) > self::NOTE_MAX) {
            $note = mb_substr($note, 0, self::NOTE_MAX);
        }

        if ($date > date('Y-m-d')) {
            throw new AccountingException(AccountingError::ClosingInFuture, 'A period cannot be closed through a future date.', ['date' => $date]);
        }

        $startDate = (new AccountingSettings($this->db))->startDate();

        if ($startDate !== null && $date < $startDate) {
            throw new AccountingException(AccountingError::ClosingBeforeStart, 'A period cannot be closed before accounting starts.', ['date' => $startDate]);
        }

        if ((new FiscalYearService($this->db, $this->guard, $this->branches))->covering($date) === null) {
            throw new AccountingException(AccountingError::ClosingOutsideFiscalYear, 'The closing date is outside every fiscal year.', ['date' => $date]);
        }

        return $this->transactional(function () use ($branchId, $date, $note, $employeeId, $startDate): int {
            $this->hold($branchId);

            $previous = $this->cutoff($branchId);

            if ($previous !== null && $date <= $previous) {
                throw new AccountingException(AccountingError::ClosingNotAfterPrevious, 'The date must be after the last closing.', ['date' => $previous]);
            }

            $drafts = $this->drafts($branchId, $date);

            if ($drafts['count'] > 0) {
                throw new AccountingException(AccountingError::ClosingBlockedByDrafts, 'Draft journals lie in the period.', [
                    'count' => $drafts['count'],
                    'ids'   => array_map(static fn (object $draft): int => (int) $draft->journal_id, $drafts['rows']),
                ]);
            }

            $this->db->table('accounting_closed_periods')->insert([
                'company_id' => CompanyContext::id(),
                'branch_id'  => $branchId,
                'date_from'  => $previous === null ? min($startDate ?? $date, $date) : $this->nextDay($previous),
                'date_to'    => $date,
                'active'     => 1,
                'note'       => $note === '' ? null : $note,
                'created_by' => $employeeId,
            ]);

            $closedPeriodId = (int) $this->db->insertID();

            $this->audit->write('closed_period', $closedPeriodId, 'period_closed', $employeeId, JournalOrigin::User, [
                'until'    => $date,
                'previous' => $previous,
                'note'     => $note === '' ? null : $note,
            ], $branchId);

            return $closedPeriodId;
        });
    }

    /**
     * Reopens the latest closing of the scope. Reopening the company does not reopen a branch
     * that closed on its own. A screen passes the date it showed, so a second request that
     * arrives after another one already reopened does not reopen the closing before it.
     *
     * @throws AccountingException|BranchException
     */
    public function reopen(?int $branchId, string $reason, ?int $employeeId, ?string $expectedCutoff = null): int
    {
        $this->guard->require(AccountingGuard::PERIODS_REOPEN, $employeeId);
        $this->requireScope($branchId, $employeeId);

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::NOTE_MAX) {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason of 1 to 255 characters is required.');
        }

        return $this->transactional(function () use ($branchId, $reason, $employeeId, $expectedCutoff): int {
            $this->hold($branchId);

            $table  = $this->db->prefixTable('accounting_closed_periods');
            $scope  = $branchId === null ? 'branch_id IS NULL' : 'branch_id = ?';
            $params = $branchId === null ? [CompanyContext::id()] : [CompanyContext::id(), $branchId];
            $latest = LockedRead::row(
                $this->db,
                "SELECT closed_period_id, date_to FROM {$table} WHERE company_id = ? AND {$scope} AND active = 1 ORDER BY date_to DESC LIMIT 1 FOR UPDATE",
                $params,
            );

            if ($latest === null) {
                throw new AccountingException(AccountingError::NothingToReopen, 'There is no closing to reopen.');
            }

            if ($expectedCutoff !== null && $expectedCutoff !== $latest->date_to) {
                throw new AccountingException(AccountingError::ClosingChanged, 'The latest closing changed since it was shown.', ['date' => $latest->date_to]);
            }

            $this->assertNoYearStaysClosedOut($branchId, (int) $latest->closed_period_id, $scope, $params);

            $this->db->table('accounting_closed_periods')->where('closed_period_id', $latest->closed_period_id)->update([
                'active'        => 0,
                'reopened_by'   => $employeeId,
                'reopened_at'   => new RawSql('CURRENT_TIMESTAMP'),
                'reopen_reason' => $reason,
            ]);

            $this->audit->write('closed_period', (int) $latest->closed_period_id, 'period_reopened', $employeeId, JournalOrigin::User, [
                'until'  => $latest->date_to,
                'reason' => $reason,
            ], $branchId);

            return (int) $latest->closed_period_id;
        });
    }

    /**
     * The closings the employee may see: the company's, and the branches in their scope.
     *
     * @param array{scope?: string, status?: string, date_from?: string, date_to?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, ?int $employeeId, int $limit = 25, int $offset = 0): array
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);
        $limit   = max(1, min($limit, self::SEARCH_MAX_ROWS));

        $builder = $this->db->table('accounting_closed_periods p')
            ->select("p.closed_period_id, p.branch_id, b.code AS branch_code, b.name AS branch_name, p.date_from, p.date_to, p.active, p.note, p.created_at, p.reopened_at, p.reopen_reason, TRIM(CONCAT(cp.first_name, ' ', cp.last_name)) AS created_by_name, TRIM(CONCAT(rp.first_name, ' ', rp.last_name)) AS reopened_by_name", false)
            ->join('branches b', 'b.branch_id = p.branch_id', 'left')
            ->join('people cp', 'cp.person_id = p.created_by', 'left')
            ->join('people rp', 'rp.person_id = p.reopened_by', 'left')
            ->where('p.company_id', CompanyContext::id());

        if ($allowed !== null) {
            $allowed === []
                ? $builder->where('p.branch_id IS NULL', null, false)
                : $builder->groupStart()->where('p.branch_id IS NULL', null, false)->orWhereIn('p.branch_id', $allowed)->groupEnd();
        }

        $scope = (string) ($filters['scope'] ?? '');

        if ($scope === 'company') {
            $builder->where('p.branch_id IS NULL', null, false);
        } elseif ((int) $scope > 0) {
            $builder->where('p.branch_id', (int) $scope);
        }

        $status = (string) ($filters['status'] ?? '');

        if ($status === 'active') {
            $builder->where('p.active', 1);
        } elseif ($status === 'reopened') {
            $builder->where('p.active', 0);
        }

        $from = (string) ($filters['date_from'] ?? '');
        $to   = (string) ($filters['date_to'] ?? '');

        if ($this->isDate($from)) {
            $builder->where('p.date_to >=', $from);
        }

        if ($this->isDate($to)) {
            $builder->where('p.date_from <=', $to);
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('p.date_to', 'DESC')->orderBy('p.closed_period_id', 'DESC')->limit($limit, $offset)->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * A year a branch has closed out holds still, so a closing of periods that would stop covering it
     * stays until the year-end closing is taken back.
     *
     * @param list<int> $params
     *
     * @throws AccountingException
     */
    private function assertNoYearStaysClosedOut(?int $branchId, int $reopenedId, string $scope, array $params): void
    {
        $table     = $this->db->prefixTable('accounting_closed_periods');
        $remaining = LockedRead::row($this->db, "SELECT MAX(date_to) AS cutoff FROM {$table} WHERE company_id = ? AND {$scope} AND active = 1 AND closed_period_id <> ?", [...$params, $reopenedId]);
        $cutoff    = $remaining === null || $remaining->cutoff === null ? null : (string) $remaining->cutoff;
        $blocking  = (new ClosedOutYears($this->db))->leftOpenBy($branchId, $branchId === null ? $cutoff : $this->cutoff(null), $branchId === null ? null : $cutoff);

        if ($blocking !== []) {
            throw new AccountingException(AccountingError::YearEndClosedOut, 'The year is closed out. Take its closing back before the periods are reopened.', ['name' => $blocking[0]->year_name, 'date' => $blocking[0]->period_to]);
        }
    }

    private function hold(?int $branchId): void
    {
        $branchId === null ? $this->locks->holdCompany() : $this->locks->holdBranch($branchId);
    }

    /**
     * Closing the company needs every branch. Closing a branch needs that branch.
     *
     * @throws BranchException
     */
    private function requireScope(?int $branchId, ?int $employeeId): void
    {
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
     * Drafts dated on or before the date in the scope. They cannot be posted once the period
     * is closed, so they must be posted or deleted first.
     *
     * @return array{count: int, rows: list<object>}
     */
    private function drafts(?int $branchId, string $date): array
    {
        $count = $this->db->table('accounting_journals')
            ->where('company_id', CompanyContext::id())
            ->where('status', JournalStatus::Draft->value)
            ->where('journal_date <=', $date);

        if ($branchId !== null) {
            $count->where('branch_id', $branchId);
        }

        $total = $count->countAllResults();

        $rows = $this->db->table('accounting_journals j')
            ->select('j.journal_id, j.journal_date, j.description, b.code AS branch_code')
            ->join('branches b', 'b.branch_id = j.branch_id')
            ->where('j.company_id', CompanyContext::id())
            ->where('j.status', JournalStatus::Draft->value)
            ->where('j.journal_date <=', $date);

        if ($branchId !== null) {
            $rows->where('j.branch_id', $branchId);
        }

        return ['count' => $total, 'rows' => $rows->orderBy('j.journal_date', 'ASC')->limit(self::DRAFT_SAMPLE)->get()->getResult()];
    }

    private function assertDate(string $date): void
    {
        if (! $this->isDate($date)) {
            throw new AccountingException(AccountingError::InvalidDate, 'The date must be a valid Y-m-d date.', ['date' => $date]);
        }
    }

    private function nextDay(string $date): string
    {
        return (new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    }

    private function isDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
    }
}
