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

/**
 * Matches a line of a bank statement with the lines of the books it is the same money as, and takes
 * the match back. A match is a row of its own that points at journal lines, which are flagged by it
 * and are not changed: no journal is edited, created or deleted here, and the books do not know the
 * match is there. A statement line is matched once, and so is a journal line. The journal lines are
 * lines of the account of this bank account in journals that are posted or reversed, all of the
 * direction of the statement line (what the bank paid in is what the books debited), and they add up
 * to the amount of the statement line exactly. The date is not held to a window here as the
 * suggestions are. A match that falls inside a closed reconciliation is not made, and one that a
 * closed reconciliation holds is not taken back, until the reconciliation is reopened.
 */
final class BankMatches
{
    use RunsInTransaction;

    public const ENTITY    = 'bank_match';
    public const MANUAL    = 'manual';
    public const SUGGESTED = 'suggested';
    public const MAX_LINES = 50;
    private const SCALE    = SaleAmounts::SCALE;

    private BaseConnection $db;
    private BankAccess $access;
    private BankBook $book;
    private ReconciliationLock $lock;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->access = new BankAccess($this->db, $guard, $branches);
        $this->book   = new BankBook($this->db);
        $this->lock   = new ReconciliationLock($this->db);
        $this->audit  = new AuditLog($this->db);
    }

    /**
     * @param list<int> $journalLineIds
     *
     * @return int The id of the match.
     *
     * @throws AccountingException
     */
    public function match(int $treasuryId, int $statementLineId, array $journalLineIds, ?int $employeeId = null, string $method = self::MANUAL): int
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $journalLineIds = array_values(array_unique(array_filter(array_map('intval', $journalLineIds), static fn (int $id): bool => $id > 0)));

        if ($journalLineIds === []) {
            throw new AccountingException(AccountingError::BankJournalLinesRequired, 'A match needs at least one line of the books.');
        }

        if (count($journalLineIds) > self::MAX_LINES) {
            throw new AccountingException(AccountingError::BankJournalLineInvalid, 'A match takes at most ' . self::MAX_LINES . ' lines of the books.', ['max' => self::MAX_LINES]);
        }

        return $this->transactional(function () use ($treasuryId, $statementLineId, $journalLineIds, $employeeId, $method): int {
            $treasury  = $this->access->bankAccount($treasuryId, $employeeId, true);
            $accountId = $this->access->treasuries()->accountOf($treasuryId);

            $line = $this->db->table('bank_statement_lines')->where(['company_id' => CompanyContext::id(), 'treasury_id' => $treasuryId, 'line_id' => $statementLineId])->get()->getRow();

            if ($line === null) {
                throw new AccountingException(AccountingError::BankLineNotFound, "Statement line {$statementLineId} does not exist.", ['line' => $statementLineId]);
            }

            if ($this->db->table('bank_matches')->where('statement_line_id', $statementLineId)->countAllResults() > 0) {
                throw new AccountingException(AccountingError::BankLineMatched, 'The statement line is matched already.', ['line' => $statementLineId]);
            }

            $rows = $accountId === null ? [] : $this->book->lines($accountId, $journalLineIds);

            if (count($rows) !== count($journalLineIds)) {
                throw new AccountingException(AccountingError::BankJournalLineInvalid, 'A line is not a line of the books of this bank account.', ['line' => implode(', ', $journalLineIds)]);
            }

            $total = '0';
            $last  = '0000-00-00';

            foreach ($rows as $row) {
                if ($row->match_id !== null) {
                    throw new AccountingException(AccountingError::BankJournalLineMatched, 'A line of the books is matched already.', ['line' => (int) $row->line_id]);
                }

                if (bccomp((string) $row->amount, '0', self::SCALE) !== bccomp((string) $line->amount, '0', self::SCALE)) {
                    throw new AccountingException(AccountingError::BankMatchDirectionMismatch, 'A deposit is matched with debits of the account and a withdrawal with credits.', ['line' => (int) $row->line_id]);
                }

                $total = bcadd($total, (string) $row->amount, self::SCALE);
                $last  = max($last, (string) $row->journal_date);
            }

            if (bccomp($total, (string) $line->amount, self::SCALE) !== 0) {
                throw new AccountingException(AccountingError::BankMatchAmountMismatch, 'The lines of the books must add up to the statement line.', ['difference' => bcsub((string) $line->amount, $total, self::SCALE)]);
            }

            $closedThrough = $this->lock->through($treasuryId);

            if ($closedThrough !== null && (string) $line->line_date <= $closedThrough && $last <= $closedThrough) {
                throw new AccountingException(AccountingError::BankMatchClosedRange, "The reconciliation is closed up to {$closedThrough}.", ['date' => $closedThrough]);
            }

            $this->db->table('bank_matches')->insert([
                'company_id'        => CompanyContext::id(),
                'branch_id'         => (int) $treasury->branch_id,
                'treasury_id'       => $treasuryId,
                'statement_line_id' => $statementLineId,
                'amount'            => $line->amount,
                'method'            => $method === self::SUGGESTED ? self::SUGGESTED : self::MANUAL,
                'matched_by'        => $employeeId,
            ]);

            $matchId = (int) $this->db->insertID();

            $this->db->table('bank_match_lines')->insertBatch(array_map(static fn (object $row): array => ['match_id' => $matchId, 'journal_line_id' => (int) $row->line_id, 'amount' => $row->amount], $rows));

            $this->audit->write(self::ENTITY, $matchId, 'bank_matched', $employeeId, JournalOrigin::User, [
                'treasury_id'       => $treasuryId,
                'statement_line_id' => $statementLineId,
                'journal_line_ids'  => array_map(static fn (object $row): int => (int) $row->line_id, $rows),
                'amount'            => $line->amount,
                'method'            => $method === self::SUGGESTED ? self::SUGGESTED : self::MANUAL,
            ], (int) $treasury->branch_id);

            return $matchId;
        });
    }

    /**
     * @throws AccountingException
     */
    public function unmatch(int $treasuryId, int $matchId, ?int $employeeId = null): void
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $this->transactional(function () use ($treasuryId, $matchId, $employeeId): void {
            $treasury = $this->access->bankAccount($treasuryId, $employeeId, true);
            $match    = $this->db->table('bank_matches')->where(['company_id' => CompanyContext::id(), 'treasury_id' => $treasuryId, 'match_id' => $matchId])->get()->getRow();

            if ($match === null) {
                throw new AccountingException(AccountingError::BankMatchNotFound, "Match {$matchId} does not exist.", ['match_id' => $matchId]);
            }

            if ($match->reconciliation_id !== null) {
                throw new AccountingException(AccountingError::BankMatchLocked, 'A match held by a closed reconciliation is taken back by reopening it.', ['match_id' => $matchId]);
            }

            $journalLineIds = array_map(static fn (object $row): int => (int) $row->journal_line_id, $this->db->table('bank_match_lines')->select('journal_line_id')->where('match_id', $matchId)->orderBy('journal_line_id', 'ASC')->get()->getResult());

            $this->db->table('bank_match_lines')->where('match_id', $matchId)->delete();
            $this->db->table('bank_matches')->where('match_id', $matchId)->delete();

            $this->audit->write(self::ENTITY, $matchId, 'bank_unmatched', $employeeId, JournalOrigin::User, [
                'treasury_id'       => $treasuryId,
                'statement_line_id' => (int) $match->statement_line_id,
                'journal_line_ids'  => $journalLineIds,
                'amount'            => $match->amount,
            ], (int) $treasury->branch_id);
        });
    }

    /**
     * The matches of an account with their lines, for the screen.
     *
     * @return list<object>
     *
     * @throws AccountingException
     */
    public function matches(int $treasuryId, ?int $employeeId, int $limit = 200): array
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $matches = $this->db->table('bank_matches m')
            ->select('m.match_id, m.amount, m.method, m.reconciliation_id, m.matched_at, sl.line_id AS statement_line_id, sl.line_date, sl.description, sl.reference')
            ->join('bank_statement_lines sl', 'sl.line_id = m.statement_line_id')
            ->where(['m.company_id' => CompanyContext::id(), 'm.treasury_id' => $treasuryId])
            ->orderBy('m.match_id', 'DESC')
            ->limit(max(1, $limit))
            ->get()
            ->getResult();

        foreach ($matches as $match) {
            $match->journal_lines = $this->db->table('bank_match_lines ml')
                ->select("ml.journal_line_id, ml.amount, j.journal_id, j.series, j.number, j.journal_date, j.status, COALESCE(NULLIF(l.description, ''), j.description) AS description", false)
                ->join('accounting_journal_lines l', 'l.line_id = ml.journal_line_id')
                ->join('accounting_journals j', 'j.journal_id = l.journal_id')
                ->where('ml.match_id', $match->match_id)
                ->orderBy('ml.journal_line_id', 'ASC')
                ->get()
                ->getResult();
        }

        return $matches;
    }

    /**
     * The matches the journal lines are flagged by, by line.
     *
     * @param list<int> $journalLineIds
     *
     * @return array<int, int> The match of each flagged line.
     */
    public function flagged(array $journalLineIds): array
    {
        $flagged = [];

        foreach (array_chunk(array_values(array_filter($journalLineIds, static fn (int $id): bool => $id > 0)), 500) as $chunk) {
            foreach ($this->db->table('bank_match_lines')->select('journal_line_id, match_id')->whereIn('journal_line_id', $chunk)->get()->getResult() as $row) {
                $flagged[(int) $row->journal_line_id] = (int) $row->match_id;
            }
        }

        return $flagged;
    }
}
