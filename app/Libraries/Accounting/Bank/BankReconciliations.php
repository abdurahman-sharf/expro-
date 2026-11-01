<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Closes the reconciliation of a bank account up to a date, and reopens it. Closing takes the balance
 * on the statement on that date and is refused unless the reconciliation statement then has no
 * difference: lines that are still open stay open and are carried on, but every line of the bank is
 * accounted for. It holds the matches that are made by the date, so that they are not taken back, and
 * it keeps the balances it closed with. The date is after the date of the closing before it, not in the
 * future, and not before the first statement line. Reopening takes the latest closing back, never an
 * earlier one, with a reason, and releases its matches. No journal is touched by either, and both are
 * written to the audit log.
 */
final class BankReconciliations
{
    use RunsInTransaction;

    public const ENTITY      = 'bank_reconciliation';
    private const SCALE      = SaleAmounts::SCALE;
    private const REASON_MAX = 255;

    private BaseConnection $db;
    private BankAccess $access;
    private BankBook $book;
    private ReconciliationReport $report;
    private ReconciliationLock $lock;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->access = new BankAccess($this->db, $guard, $branches);
        $this->book   = new BankBook($this->db);
        $this->report = new ReconciliationReport($this->db, $guard, $branches);
        $this->lock   = new ReconciliationLock($this->db);
        $this->audit  = new AuditLog($this->db);
    }

    /**
     * @return int The id of the closing.
     *
     * @throws AccountingException
     */
    public function close(int $treasuryId, string $through, string $statementBalance, ?int $employeeId = null): int
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $balance = ReconciliationReport::amount($statementBalance);

        return $this->transactional(function () use ($treasuryId, $through, $balance, $employeeId): int {
            $treasury = $this->access->bankAccount($treasuryId, $employeeId, true);

            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $through);

            if ($date === false || $date->format('Y-m-d') !== $through) {
                throw new AccountingException(AccountingError::InvalidDate, "{$through} is not a date.", ['date' => $through]);
            }

            $previous = $this->lock->through($treasuryId);
            $start    = $this->book->coverageStart($treasuryId);

            if ($through > date('Y-m-d') || ($previous !== null && $through <= $previous) || $start === null || $through < $start) {
                throw $previous === null
                    ? new AccountingException(AccountingError::BankReconciliationDateOutside, "{$through} cannot be closed.", ['date' => $through])
                    : new AccountingException(AccountingError::BankReconciliationDateInvalid, "{$through} cannot be closed.", ['date' => $through, 'until' => $previous]);
            }

            $figures = $this->report->build($treasuryId, $through, $balance, $employeeId);

            if (! $figures->reconciled()) {
                throw new AccountingException(AccountingError::BankReconciliationDifference, 'The reconciliation statement has a difference.', ['difference' => $figures->difference]);
            }

            $matches = $this->book->matchesMadeBy($treasuryId, $through);

            $this->db->table('bank_reconciliations')->insert([
                'company_id'        => CompanyContext::id(),
                'branch_id'         => (int) $treasury->branch_id,
                'treasury_id'       => $treasuryId,
                'closed_through'    => $through,
                'statement_balance' => $balance,
                'book_balance'      => $figures->bookBalance,
                'difference'        => '0.0000',
                'match_count'       => count($matches),
                'status'            => 'closed',
                'closed_by'         => $employeeId,
            ]);

            $reconciliationId = (int) $this->db->insertID();

            foreach (array_chunk($matches, 500) as $chunk) {
                $this->db->table('bank_matches')->where('treasury_id', $treasuryId)->where('reconciliation_id IS NULL', null, false)->whereIn('match_id', $chunk)->update(['reconciliation_id' => $reconciliationId]);
            }

            $this->audit->write(self::ENTITY, $reconciliationId, 'bank_reconciliation_closed', $employeeId, JournalOrigin::User, [
                'treasury_id'       => $treasuryId,
                'closed_through'    => $through,
                'statement_balance' => $balance,
                'book_balance'      => $figures->bookBalance,
                'matches'           => count($matches),
            ], (int) $treasury->branch_id);

            return $reconciliationId;
        });
    }

    /**
     * @throws AccountingException
     */
    public function reopen(int $reconciliationId, string $reason, ?int $employeeId = null): void
    {
        $this->access->requirePermission($employeeId);

        $row = $this->find($reconciliationId, $employeeId);

        if ($row === null) {
            throw new AccountingException(AccountingError::BankReconciliationNotFound, "Reconciliation {$reconciliationId} does not exist.", ['reconciliation_id' => $reconciliationId]);
        }

        $reason = mb_substr(trim($reason), 0, self::REASON_MAX);

        if ($reason === '') {
            throw new AccountingException(AccountingError::ReasonRequired, 'A reason is required to reopen a reconciliation.');
        }

        $this->transactional(function () use ($reconciliationId, $reason, $employeeId, $row): void {
            $treasuryId = (int) $row->treasury_id;
            $treasury   = $this->access->bankAccount($treasuryId, $employeeId, true);
            $current    = $this->db->table('bank_reconciliations')->where(['company_id' => CompanyContext::id(), 'reconciliation_id' => $reconciliationId])->get()->getRow();

            if ($current === null || $current->status !== 'closed') {
                throw new AccountingException(AccountingError::BankReconciliationReopened, 'The reconciliation is reopened already.', ['reconciliation_id' => $reconciliationId]);
            }

            if ($this->lock->through($treasuryId) !== (string) $current->closed_through) {
                throw new AccountingException(AccountingError::BankReconciliationNotLatest, 'Only the latest closing of an account is reopened.', ['date' => $this->lock->through($treasuryId) ?? '']);
            }

            $released = $this->db->table('bank_matches')->where('reconciliation_id', $reconciliationId)->countAllResults();

            $this->db->table('bank_matches')->where('reconciliation_id', $reconciliationId)->update(['reconciliation_id' => null]);
            $this->db->table('bank_reconciliations')->where('reconciliation_id', $reconciliationId)->update([
                'status'        => 'reopened',
                'reopened_by'   => $employeeId,
                'reopened_at'   => date('Y-m-d H:i:s'),
                'reopen_reason' => $reason,
            ]);

            $this->audit->write(self::ENTITY, $reconciliationId, 'bank_reconciliation_reopened', $employeeId, JournalOrigin::User, [
                'treasury_id'    => $treasuryId,
                'closed_through' => (string) $current->closed_through,
                'reason'         => $reason,
                'matches'        => $released,
            ], (int) $treasury->branch_id);
        });
    }

    /**
     * A closing of a bank account the employee may see, or none.
     */
    public function find(int $reconciliationId, ?int $employeeId): ?object
    {
        $row = $this->db->table('bank_reconciliations')->where(['company_id' => CompanyContext::id(), 'reconciliation_id' => $reconciliationId])->get()->getRow();

        if ($row === null) {
            return null;
        }

        $treasury = $this->access->treasuries()->find((int) $row->treasury_id);

        return $treasury !== null && $this->access->canSee($treasury, $employeeId) ? $row : null;
    }

    /**
     * The closings of an account, the latest first, each with whether the books still show what it
     * closed with: a journal dated inside a closed period after the closing changes the balance.
     *
     * @return list<object>
     *
     * @throws AccountingException
     */
    public function closings(int $treasuryId, ?int $employeeId): array
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $accountId = $this->access->treasuries()->accountOf($treasuryId) ?? 0;
        $rows      = $this->db->table('bank_reconciliations r')
            ->select("r.*, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS closed_by_name", false)
            ->join('people p', 'p.person_id = r.closed_by', 'left')
            ->where(['r.company_id' => CompanyContext::id(), 'r.treasury_id' => $treasuryId])
            ->orderBy('r.closed_through', 'DESC')
            ->orderBy('r.reconciliation_id', 'DESC')
            ->get()
            ->getResult();

        foreach ($rows as $row) {
            $row->drifted = $row->status === 'closed' && bccomp($this->book->balance($accountId, (string) $row->closed_through), (string) $row->book_balance, self::SCALE) !== 0;
        }

        return $rows;
    }
}
