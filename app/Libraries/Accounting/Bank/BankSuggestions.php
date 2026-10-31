<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * Which lines of the books a statement line may be the same money as. It reads and never writes, and
 * what it finds is a suggestion that somebody accepts, never a match made. A line of the books is a
 * candidate for a statement line when it is open, on the account of the bank account, of exactly the
 * same amount and direction, and dated within the window of days of the statement line. The
 * candidates are put in order of the hint they carry (the reference of the statement line found in the
 * journal number, the document or the text of the line, then words of the description), then of how
 * near the dates are. A statement line with one candidate, or one candidate that carries the
 * reference, is a firm suggestion; one with several is ambiguous and is left to be chosen. Only
 * single lines are suggested: a statement line that is the sum of several lines of the books is
 * matched by hand.
 */
final class BankSuggestions
{
    public const REFERENCE = 'reference';
    public const UNIQUE    = 'unique';
    public const DESCRIPTION = 'description';
    public const AMBIGUOUS = 'ambiguous';
    private const MIN_HINT = 3;

    private BaseConnection $db;
    private BankAccess $access;
    private BankBook $book;
    private BankMatchSettings $settings;

    public function __construct(?BaseConnection $db = null, ?AccountingGuard $guard = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->access   = new BankAccess($this->db, $guard, $branches);
        $this->book     = new BankBook($this->db);
        $this->settings = new BankMatchSettings($this->db, $guard);
    }

    /**
     * @return list<array{line: object, candidates: list<object>, confidence: string}>
     *
     * @throws AccountingException
     */
    public function forAccount(int $treasuryId, ?int $employeeId, ?int $windowDays = null, int $limit = 200): array
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $accountId = $this->access->treasuries()->accountOf($treasuryId);

        if ($accountId === null) {
            return [];
        }

        $window = $windowDays ?? $this->settings->windowDays();
        $lines  = $this->db->table('bank_statement_lines l')
            ->select('l.line_id, l.line_date, l.description, l.reference, l.amount')
            ->join('bank_matches m', 'm.statement_line_id = l.line_id', 'left')
            ->where(['l.company_id' => CompanyContext::id(), 'l.treasury_id' => $treasuryId])
            ->where('m.match_id IS NULL', null, false)
            ->orderBy('l.line_date', 'ASC')
            ->orderBy('l.line_id', 'ASC')
            ->limit(max(1, $limit))
            ->get()
            ->getResult();

        $suggestions = [];

        foreach ($lines as $line) {
            $date       = new DateTimeImmutable((string) $line->line_date);
            $candidates = $this->book->candidates($treasuryId, $accountId, (string) $line->amount, $date->modify("-{$window} days")->format('Y-m-d'), $date->modify("+{$window} days")->format('Y-m-d'));

            if ($candidates === []) {
                continue;
            }

            foreach ($candidates as $candidate) {
                $candidate->hint = $this->hint($line, $candidate);
                $candidate->rank = [self::REFERENCE => 2, self::DESCRIPTION => 1][$candidate->hint] ?? 0;
                $candidate->days = abs((int) $date->diff(new DateTimeImmutable((string) $candidate->journal_date))->format('%a'));
            }

            usort($candidates, static fn (object $first, object $second): int => [$second->rank, $first->days, $first->line_id] <=> [$first->rank, $second->days, $second->line_id]);

            $referenced = count(array_filter($candidates, static fn (object $candidate): bool => $candidate->hint === self::REFERENCE));

            $suggestions[] = [
                'line'       => $line,
                'candidates' => $candidates,
                'confidence' => $referenced === 1 ? self::REFERENCE : (count($candidates) === 1 ? self::UNIQUE : self::AMBIGUOUS),
            ];
        }

        return $suggestions;
    }

    /**
     * What a person chooses from to match one statement line: the lines of the books that no match
     * holds, of the direction of the line, those of the amount of the line and within the window first,
     * then by how near their date is.
     *
     * @return array{line: object, lines: list<object>}
     *
     * @throws AccountingException
     */
    public function forLine(int $treasuryId, int $statementLineId, ?int $employeeId, ?int $windowDays = null, int $limit = 300): array
    {
        $this->access->bankAccount($treasuryId, $employeeId);

        $line = $this->db->table('bank_statement_lines')->where(['company_id' => CompanyContext::id(), 'treasury_id' => $treasuryId, 'line_id' => $statementLineId])->get()->getRow();

        if ($line === null) {
            throw new AccountingException(AccountingError::BankLineNotFound, "Statement line {$statementLineId} does not exist.", ['line' => $statementLineId]);
        }

        $accountId = $this->access->treasuries()->accountOf($treasuryId);
        $window    = $windowDays ?? $this->settings->windowDays();
        $date      = new DateTimeImmutable((string) $line->line_date);
        $lines     = $accountId === null ? [] : $this->book->unmatchedOfDirection($accountId, bccomp((string) $line->amount, '0', 4) > 0, $limit);

        foreach ($lines as $candidate) {
            $candidate->days  = abs((int) $date->diff(new DateTimeImmutable((string) $candidate->journal_date))->format('%a'));
            $candidate->exact = bccomp((string) $candidate->amount, (string) $line->amount, 4) === 0;
            $candidate->near  = $candidate->exact && $candidate->days <= $window;
            $candidate->hint  = $this->hint($line, $candidate);
        }

        usort($lines, static fn (object $first, object $second): int => [(int) $second->near, $first->days, $first->line_id] <=> [(int) $first->near, $second->days, $second->line_id]);

        return ['line' => $line, 'lines' => $lines];
    }

    private function hint(object $line, object $candidate): string
    {
        $reference = BankText::normalised((string) $line->reference);
        $text      = BankText::normalised($candidate->number . ' ' . $candidate->source_type . ' ' . $candidate->source_id . ' ' . $candidate->source_type . ':' . $candidate->source_id . ' ' . $candidate->description);

        if (mb_strlen($reference) >= self::MIN_HINT && str_contains($text, $reference)) {
            return self::REFERENCE;
        }

        foreach (explode(' ', BankText::normalised((string) $line->description)) as $word) {
            if (mb_strlen($word) >= self::MIN_HINT + 1 && str_contains($text, $word)) {
                return self::DESCRIPTION;
            }
        }

        return 'none';
    }
}
