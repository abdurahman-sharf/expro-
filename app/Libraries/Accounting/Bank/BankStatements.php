<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Accounting\LockedRead;
use App\Libraries\Accounting\RunsInTransaction;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The statements of bank accounts imported from files, and their lines. An import is atomic and
 * idempotent: the file is read and checked as a whole, a file that was imported before (by its
 * checksum) creates nothing, and so does a file whose lines are all known already; a line is known
 * when the account holds a line of the same date, amount, reference and description, as many times as
 * the new file has it, so statements that overlap put in each line once. The lines are rows of their
 * own: no journal is read for writing, created or changed here. Every change of one account is made
 * with its treasury row locked, and a line dated on or before the date its reconciliation is closed up
 * to is not put in, nor taken out, until the reconciliation is reopened.
 */
final class BankStatements
{
    use RunsInTransaction;

    public const ENTITY   = 'bank_statement';
    private const BATCH   = 200;
    private const NAME_MAX = 255;

    private BaseConnection $db;
    private BankAccess $access;
    private StatementParser $parser;
    private ReconciliationLock $lock;
    private AuditLog $audit;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null, ?StatementParser $parser = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->access = new BankAccess($this->db, $guard, $branches);
        $this->parser = $parser ?? new StatementParser();
        $this->lock   = new ReconciliationLock($this->db);
        $this->audit  = new AuditLog($this->db);
    }

    /**
     * @throws AccountingException
     */
    public function import(int $treasuryId, string $fileName, string $content, StatementMapping $mapping, ?int $employeeId = null): ImportResult
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $parsed = $this->parser->parse($content, $mapping);

        if (! $parsed->isValid()) {
            throw new StatementRejected($parsed->errors, $parsed->errorCount);
        }

        if ($parsed->rows === []) {
            throw new AccountingException(AccountingError::BankStatementEmpty, 'The file has no rows.');
        }

        return $this->transactional(function () use ($treasuryId, $fileName, $mapping, $employeeId, $parsed): ImportResult {
            $treasury = $this->access->bankAccount($treasuryId, $employeeId, true);

            $sameFile = $this->db->table('bank_statements')->where(['treasury_id' => $treasuryId, 'checksum' => $parsed->checksum])->countAllResults();

            if ($sameFile > 0) {
                return new ImportResult(ImportResult::DUPLICATE_FILE, null, $parsed->rowCount, 0, $parsed->rowCount);
            }

            $known = $this->knownKeys($treasuryId, array_map(static fn (StatementRow $row): string => $row->dedupeKey, $parsed->rows));
            $fresh = array_values(array_filter($parsed->rows, static fn (StatementRow $row): bool => ! isset($known[$row->dedupeKey])));

            if ($fresh === []) {
                return new ImportResult(ImportResult::DUPLICATE_LINES, null, $parsed->rowCount, 0, $parsed->rowCount);
            }

            $closedThrough = $this->lock->through($treasuryId);
            $dates         = array_map(static fn (StatementRow $row): string => $row->date, $fresh);
            $from          = min($dates);
            $to            = max($dates);

            if ($closedThrough !== null && $from <= $closedThrough) {
                throw new AccountingException(AccountingError::BankStatementClosedRange, "The reconciliation is closed up to {$closedThrough}.", ['date' => $closedThrough]);
            }

            $branchId = (int) $treasury->branch_id;

            $this->db->table('bank_statements')->insert([
                'company_id'        => CompanyContext::id(),
                'branch_id'         => $branchId,
                'treasury_id'       => $treasuryId,
                'file_name'         => $this->fileName($fileName),
                'checksum'          => $parsed->checksum,
                'delimiter_name'    => $mapping->delimiter,
                'skipped_first_row' => $mapping->skipFirstRow ? 1 : 0,
                'row_count'         => $parsed->rowCount,
                'line_count'        => count($fresh),
                'duplicate_count'   => $parsed->rowCount - count($fresh),
                'date_from'         => $from,
                'date_to'           => $to,
                'imported_by'       => $employeeId,
            ]);

            $statementId = (int) $this->db->insertID();

            foreach (array_chunk($fresh, self::BATCH) as $chunk) {
                $this->db->table('bank_statement_lines')->insertBatch(array_map(static fn (StatementRow $row): array => [
                    'statement_id' => $statementId,
                    'company_id'   => CompanyContext::id(),
                    'branch_id'    => $branchId,
                    'treasury_id'  => $treasuryId,
                    'row_no'       => $row->rowNumber,
                    'line_date'    => $row->date,
                    'description'  => $row->description,
                    'reference'    => $row->reference,
                    'amount'       => $row->amount,
                    'dedupe_key'   => $row->dedupeKey,
                ], $chunk));
            }

            $this->audit->write(self::ENTITY, $statementId, 'bank_statement_imported', $employeeId, JournalOrigin::User, [
                'treasury_id' => $treasuryId,
                'file'        => $this->fileName($fileName),
                'checksum'    => $parsed->checksum,
                'rows'        => $parsed->rowCount,
                'imported'    => count($fresh),
                'duplicates'  => $parsed->rowCount - count($fresh),
                'from'        => $from,
                'to'          => $to,
            ], $branchId);

            return new ImportResult(ImportResult::IMPORTED, $statementId, $parsed->rowCount, count($fresh), $parsed->rowCount - count($fresh));
        });
    }

    /**
     * Takes an imported statement out, with its lines, when none of its lines is matched and none is
     * dated inside a closed reconciliation. What was matched is unmatched first.
     *
     * @throws AccountingException
     */
    public function delete(int $statementId, ?int $employeeId = null): void
    {
        $this->access->requirePermission($employeeId);

        $statement = $this->find($statementId, $employeeId);

        if ($statement === null) {
            throw new AccountingException(AccountingError::BankStatementNotFound, "Statement {$statementId} does not exist.", ['statement_id' => $statementId]);
        }

        $this->transactional(function () use ($statementId, $statement, $employeeId): void {
            $treasuryId = (int) $statement->treasury_id;

            $this->access->bankAccount($treasuryId, $employeeId, true);

            $current = $this->row($statementId);

            if ($current === null) {
                throw new AccountingException(AccountingError::BankStatementNotFound, "Statement {$statementId} does not exist.", ['statement_id' => $statementId]);
            }

            $matched = $this->db->table('bank_matches m')
                ->join('bank_statement_lines l', 'l.line_id = m.statement_line_id')
                ->where('l.statement_id', $statementId)
                ->countAllResults();

            if ($matched > 0) {
                throw new AccountingException(AccountingError::BankStatementHasMatches, 'A statement with matched lines cannot be deleted.', ['count' => $matched]);
            }

            $closedThrough = $this->lock->through($treasuryId);

            if ($closedThrough !== null && (string) $current->date_from <= $closedThrough) {
                throw new AccountingException(AccountingError::BankStatementClosedRange, "The reconciliation is closed up to {$closedThrough}.", ['date' => $closedThrough]);
            }

            $this->db->table('bank_statement_lines')->where('statement_id', $statementId)->delete();
            $this->db->table('bank_statements')->where('statement_id', $statementId)->delete();

            $this->audit->write(self::ENTITY, $statementId, 'bank_statement_deleted', $employeeId, JournalOrigin::User, [
                'treasury_id' => $treasuryId,
                'file'        => $current->file_name,
                'checksum'    => $current->checksum,
                'lines'       => (int) $current->line_count,
            ], (int) $current->branch_id);
        });
    }

    /**
     * A statement of a bank account the employee may see, or none.
     */
    public function find(int $statementId, ?int $employeeId): ?object
    {
        $statement = $this->row($statementId);

        if ($statement === null) {
            return null;
        }

        $treasury = $this->access->treasuries()->find((int) $statement->treasury_id);

        return $treasury !== null && $this->access->canSee($treasury, $employeeId) ? $statement : null;
    }

    /**
     * @return list<object>
     *
     * @throws AccountingException
     */
    public function statements(int $treasuryId, ?int $employeeId): array
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        return $this->db->table('bank_statements s')
            ->select("s.*, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS imported_by_name", false)
            ->join('people p', 'p.person_id = s.imported_by', 'left')
            ->where(['s.company_id' => CompanyContext::id(), 's.treasury_id' => $treasuryId])
            ->orderBy('s.imported_at', 'DESC')
            ->orderBy('s.statement_id', 'DESC')
            ->get()
            ->getResult();
    }

    /**
     * The lines of the statements of an account, oldest first, with the match each has.
     *
     * @param 'all'|'matched'|'unmatched' $state
     *
     * @return array{total: int, rows: list<object>}
     *
     * @throws AccountingException
     */
    public function lines(int $treasuryId, ?int $employeeId, string $state = 'all', ?string $from = null, ?string $to = null, int $limit = 100, int $offset = 0): array
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $builder = $this->db->table('bank_statement_lines l')
            ->select('l.line_id, l.statement_id, l.row_no, l.line_date, l.description, l.reference, l.amount, m.match_id, m.reconciliation_id, m.method, s.file_name')
            ->join('bank_statements s', 's.statement_id = l.statement_id')
            ->join('bank_matches m', 'm.statement_line_id = l.line_id', 'left')
            ->where(['l.company_id' => CompanyContext::id(), 'l.treasury_id' => $treasuryId]);

        if ($state === 'matched') {
            $builder->where('m.match_id IS NOT NULL', null, false);
        } elseif ($state === 'unmatched') {
            $builder->where('m.match_id IS NULL', null, false);
        }

        foreach (['>=' => $from, '<=' => $to] as $operator => $date) {
            if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $builder->where("l.line_date {$operator}", $date);
            }
        }

        $total = $builder->countAllResults(false);
        $rows  = $builder->orderBy('l.line_date', 'ASC')->orderBy('l.line_id', 'ASC')->limit(max(1, min($limit, 500)), max(0, $offset))->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, true>
     */
    private function knownKeys(int $treasuryId, array $keys): array
    {
        $known = [];

        foreach (array_chunk($keys, 500) as $chunk) {
            foreach ($this->db->table('bank_statement_lines')->select('dedupe_key')->where('treasury_id', $treasuryId)->whereIn('dedupe_key', $chunk)->get()->getResult() as $row) {
                $known[(string) $row->dedupe_key] = true;
            }
        }

        return $known;
    }

    private function row(int $statementId): ?object
    {
        return $this->db->table('bank_statements')->where(['company_id' => CompanyContext::id(), 'statement_id' => $statementId])->get()->getRow();
    }

    private function fileName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))));

        return mb_substr($name, 0, self::NAME_MAX);
    }
}
