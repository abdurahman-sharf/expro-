<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The reconciliation of the bank accounts as one piece of work for a screen or a command: the
 * statements of an account and the lines in them, the suggestions, the matches, the reconciliation
 * statement and the closings. Every method takes the employee and every one of them asks the
 * permission to reconcile and the scope of the bank account of the services it calls, so an employee
 * who may not see an account gets none of it and an id that is not theirs is an account not found. The
 * books are only read, and only the tables of the statements, the matches and the closings are written.
 */
final class BankReconciliation
{
    private BankAccess $access;
    private BankStatements $statements;
    private BankMatches $matches;
    private BankSuggestions $suggestions;
    private ReconciliationReport $report;
    private BankReconciliations $closings;
    private BankMatchSettings $settings;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $db ??= Database::connect();

        $this->access      = new BankAccess($db, $guard, $branches);
        $this->statements  = new BankStatements($db, $guard, $branches);
        $this->matches     = new BankMatches($db, $guard, $branches);
        $this->suggestions = new BankSuggestions($db, $guard, $branches);
        $this->report      = new ReconciliationReport($db, $guard, $branches);
        $this->closings    = new BankReconciliations($db, $guard, $branches);
        $this->settings    = new BankMatchSettings($db, $guard);
    }

    /**
     * @return list<object>
     */
    public function bankAccounts(?int $employeeId): array
    {
        return $this->access->bankAccounts($employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function bankAccount(int $treasuryId, ?int $employeeId): object
    {
        return $this->access->bankAccount($treasuryId, $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function import(int $treasuryId, string $fileName, string $content, StatementMapping $mapping, ?int $employeeId = null): ImportResult
    {
        return $this->statements->import($treasuryId, $fileName, $content, $mapping, $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function deleteStatement(int $statementId, ?int $employeeId = null): void
    {
        $this->statements->delete($statementId, $employeeId);
    }

    /**
     * @return list<object>
     *
     * @throws AccountingException
     */
    public function statements(int $treasuryId, ?int $employeeId): array
    {
        return $this->statements->statements($treasuryId, $employeeId);
    }

    /**
     * @param 'all'|'matched'|'unmatched' $state
     *
     * @return array{total: int, rows: list<object>}
     *
     * @throws AccountingException
     */
    public function lines(int $treasuryId, ?int $employeeId, string $state = 'all', ?string $from = null, ?string $to = null, int $limit = 100, int $offset = 0): array
    {
        return $this->statements->lines($treasuryId, $employeeId, $state, $from, $to, $limit, $offset);
    }

    /**
     * @return list<array{line: object, candidates: list<object>, confidence: string}>
     *
     * @throws AccountingException
     */
    public function suggestions(int $treasuryId, ?int $employeeId, ?int $windowDays = null): array
    {
        return $this->suggestions->forAccount($treasuryId, $employeeId, $windowDays);
    }

    /**
     * @return array{line: object, lines: list<object>}
     *
     * @throws AccountingException
     */
    public function linesToMatch(int $treasuryId, int $statementLineId, ?int $employeeId): array
    {
        return $this->suggestions->forLine($treasuryId, $statementLineId, $employeeId);
    }

    /**
     * @param list<int> $journalLineIds
     *
     * @throws AccountingException
     */
    public function match(int $treasuryId, int $statementLineId, array $journalLineIds, ?int $employeeId = null, string $method = BankMatches::MANUAL): int
    {
        return $this->matches->match($treasuryId, $statementLineId, $journalLineIds, $employeeId, $method);
    }

    /**
     * @throws AccountingException
     */
    public function unmatch(int $treasuryId, int $matchId, ?int $employeeId = null): void
    {
        $this->matches->unmatch($treasuryId, $matchId, $employeeId);
    }

    /**
     * @return list<object>
     *
     * @throws AccountingException
     */
    public function matches(int $treasuryId, ?int $employeeId): array
    {
        return $this->matches->matches($treasuryId, $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function report(int $treasuryId, string $asOf, ?string $statementBalance, ?int $employeeId = null): ReconciliationFigures
    {
        return $this->report->build($treasuryId, $asOf, $statementBalance, $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function close(int $treasuryId, string $through, string $statementBalance, ?int $employeeId = null): int
    {
        return $this->closings->close($treasuryId, $through, $statementBalance, $employeeId);
    }

    /**
     * @throws AccountingException
     */
    public function reopen(int $reconciliationId, string $reason, ?int $employeeId = null): void
    {
        $this->closings->reopen($reconciliationId, $reason, $employeeId);
    }

    /**
     * @return list<object>
     *
     * @throws AccountingException
     */
    public function closings(int $treasuryId, ?int $employeeId): array
    {
        return $this->closings->closings($treasuryId, $employeeId);
    }

    public function windowDays(): int
    {
        return $this->settings->windowDays();
    }

    /**
     * @throws AccountingException
     */
    public function setWindowDays(mixed $days, ?int $employeeId = null): void
    {
        $this->settings->setWindowDays($days, $employeeId);
    }
}
