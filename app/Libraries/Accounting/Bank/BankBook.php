<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\SaleAmounts;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The lines of the books on the account of a bank account, read and never written: the lines of the
 * journals that are posted or reversed, as the balance of the treasury counts them, with the match each
 * has. A match counts as made as of a date when its statement line and all of its journal lines are
 * dated on or before it; before that the bank and the books are not yet agreed on it, and its lines are
 * still open. A journal that was reversed and its reversal, when neither is matched and together they
 * make nothing on the account, are left out of the open lines: they are the same money taken back.
 */
final class BankBook
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function balance(int $accountId, ?string $asOf = null): string
    {
        $sql = "SELECT COALESCE(SUM(l.debit), 0) - COALESCE(SUM(l.credit), 0) AS balance
                   FROM {$this->table('accounting_journal_lines')} l
                   JOIN {$this->table('accounting_journals')} j ON j.journal_id = l.journal_id
                   WHERE j.status IN (:posted:, :reversed:) AND l.account_id = :account:" . ($asOf === null ? '' : ' AND j.journal_date <= :asof:');
        $binds  = $this->binds($accountId, $asOf);
        $result = $this->db->query($sql, $binds)->getRow();

        return bcadd((string) $result->balance, '0', SaleAmounts::SCALE);
    }

    /**
     * Lines of the account by id, with their journal and the match they are in, if any.
     *
     * @param list<int> $lineIds
     *
     * @return list<object>
     */
    public function lines(int $accountId, array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        return $this->db->table('accounting_journal_lines l')
            ->select('l.line_id, l.journal_id, j.journal_date, j.status, (l.debit - l.credit) AS amount, ml.match_id', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->join('bank_match_lines ml', 'ml.journal_line_id = l.line_id', 'left')
            ->where('l.account_id', $accountId)
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('l.line_id', $lineIds)
            ->get()
            ->getResult();
    }

    /**
     * The lines still open on a date, by date: not in a match that was made by then, and not one of a
     * journal and its reversal that cancel.
     *
     * @param 'all'|'carried'|'outstanding' $part Outstanding lines are dated on or after the first line of the statements, carried ones before it.
     *
     * @return list<object>
     */
    public function openLines(int $treasuryId, int $accountId, string $asOf, ?string $coverageStart, string $part = 'outstanding', int $limit = 500): array
    {
        [$sql, $binds] = $this->openQuery($treasuryId, $accountId, $asOf, $coverageStart, $part);

        return $this->db->query($sql . ' ORDER BY j.journal_date ASC, l.line_id ASC LIMIT ' . max(1, $limit), $binds)->getResult();
    }

    /**
     * @param 'all'|'carried'|'outstanding' $part
     *
     * @return array{count: int, total: string, in: string, out: string}
     */
    public function openTotals(int $treasuryId, int $accountId, string $asOf, ?string $coverageStart, string $part = 'outstanding'): array
    {
        [$sql, $binds] = $this->openQuery($treasuryId, $accountId, $asOf, $coverageStart, $part);

        $row = $this->db->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(t.amount), 0) AS total, COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS money_in, COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END), 0) AS money_out FROM (' . $sql . ') t',
            $binds,
        )->getRow();

        return [
            'count' => (int) $row->n,
            'total' => bcadd((string) $row->total, '0', SaleAmounts::SCALE),
            'in'    => bcadd((string) $row->money_in, '0', SaleAmounts::SCALE),
            'out'   => bcadd((string) $row->money_out, '0', SaleAmounts::SCALE),
        ];
    }

    /**
     * The lines of the books that no match holds yet, of the direction given, which a person can
     * choose from to match a statement line, by date: the debits for a deposit and the credits for a
     * withdrawal, with a journal and its reversal left out when they cancel.
     *
     * @return list<object>
     */
    public function unmatchedOfDirection(int $accountId, bool $deposit, int $limit = 300): array
    {
        $sql = "SELECT l.line_id, l.journal_id, j.series, j.number, j.journal_date, j.status, j.source_type, j.source_id, j.reverses_journal_id,
                       COALESCE(NULLIF(l.description, ''), j.description) AS description, (l.debit - l.credit) AS amount
                FROM {$this->table('accounting_journal_lines')} l
                JOIN {$this->table('accounting_journals')} j ON j.journal_id = l.journal_id
                LEFT JOIN {$this->table('bank_match_lines')} ml ON ml.journal_line_id = l.line_id
                WHERE ml.journal_line_id IS NULL AND l.account_id = :account: AND j.status IN (:posted:, :reversed:)
                  AND " . ($deposit ? 'l.debit > l.credit' : 'l.credit > l.debit') . '
                  AND NOT ' . $this->cancelledPair() . '
                ORDER BY j.journal_date DESC, l.line_id DESC LIMIT ' . max(1, $limit);

        return $this->db->query($sql, $this->binds($accountId, null))->getResult();
    }

    /**
     * The lines the screen offers to match with a statement line of an amount: open on the date given
     * by the books, of the same amount, in a posted or reversed journal.
     *
     * @return list<object>
     */
    public function candidates(int $treasuryId, int $accountId, string $amount, string $from, string $to, int $limit = 50): array
    {
        $sql = "SELECT l.line_id, l.journal_id, j.series, j.number, j.journal_date, j.status, j.source_type, j.source_id,
                       COALESCE(NULLIF(l.description, ''), j.description) AS description, (l.debit - l.credit) AS amount
                FROM {$this->table('accounting_journal_lines')} l
                JOIN {$this->table('accounting_journals')} j ON j.journal_id = l.journal_id
                LEFT JOIN {$this->table('bank_match_lines')} ml ON ml.journal_line_id = l.line_id
                WHERE ml.journal_line_id IS NULL AND l.account_id = :account: AND j.status IN (:posted:, :reversed:)
                  AND (l.debit - l.credit) = :amount: AND j.journal_date BETWEEN :from: AND :to:
                ORDER BY j.journal_date ASC, l.line_id ASC LIMIT " . max(1, $limit);

        return $this->db->query($sql, ['account' => $accountId, 'posted' => JournalStatus::Posted->value, 'reversed' => JournalStatus::Reversed->value, 'amount' => $amount, 'from' => $from, 'to' => $to])->getResult();
    }

    /**
     * The statement lines dated on or before a date that are not in a match made by then, by date.
     *
     * @return list<object>
     */
    public function openStatementLines(int $treasuryId, string $asOf, int $limit = 500): array
    {
        return $this->db->query($this->openStatementSql() . ' ORDER BY l.line_date ASC, l.line_id ASC LIMIT ' . max(1, $limit), ['treasury' => $treasuryId, 'asof' => $asOf])->getResult();
    }

    /**
     * @return array{count: int, total: string, in: string, out: string}
     */
    public function openStatementTotals(int $treasuryId, string $asOf): array
    {
        $row = $this->db->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(t.amount), 0) AS total, COALESCE(SUM(CASE WHEN t.amount > 0 THEN t.amount ELSE 0 END), 0) AS money_in, COALESCE(SUM(CASE WHEN t.amount < 0 THEN t.amount ELSE 0 END), 0) AS money_out FROM (' . $this->openStatementSql() . ') t',
            ['treasury' => $treasuryId, 'asof' => $asOf],
        )->getRow();

        return [
            'count' => (int) $row->n,
            'total' => bcadd((string) $row->total, '0', SaleAmounts::SCALE),
            'in'    => bcadd((string) $row->money_in, '0', SaleAmounts::SCALE),
            'out'   => bcadd((string) $row->money_out, '0', SaleAmounts::SCALE),
        ];
    }

    /**
     * What the statement lines of an account add up to on or before a date, matched or not.
     */
    public function statementTotal(int $treasuryId, string $asOf): string
    {
        $row = $this->db->table('bank_statement_lines')->selectSum('amount', 'total')->where('treasury_id', $treasuryId)->where('line_date <=', $asOf)->get()->getRow();

        return bcadd((string) ($row->total ?? '0'), '0', SaleAmounts::SCALE);
    }

    /**
     * The date of the first statement line of the account, from which the open lines of the books are
     * outstanding; the lines before it are carried, as the first statement cannot show them.
     */
    public function coverageStart(int $treasuryId): ?string
    {
        $row = $this->db->table('bank_statement_lines')->selectMin('line_date', 'first')->where('treasury_id', $treasuryId)->get()->getRow();

        return $row === null || $row->first === null ? null : (string) $row->first;
    }

    /**
     * The matches that are made as of a date, by id: those whose statement line and every journal line
     * are dated on or before it.
     *
     * @return list<int>
     */
    public function matchesMadeBy(int $treasuryId, string $asOf): array
    {
        $rows = $this->db->query('SELECT e.match_id FROM (' . $this->effectiveMatches() . ') e WHERE e.effective = 1 ORDER BY e.match_id ASC', ['treasury' => $treasuryId, 'asof' => $asOf])->getResult();

        return array_map(static fn (object $row): int => (int) $row->match_id, $rows);
    }

    private function openStatementSql(): string
    {
        return "SELECT l.line_id, l.line_date, l.description, l.reference, l.amount
                FROM {$this->table('bank_statement_lines')} l
                LEFT JOIN {$this->table('bank_matches')} m ON m.statement_line_id = l.line_id
                LEFT JOIN ({$this->effectiveMatches()}) eff ON eff.match_id = m.match_id
                WHERE l.treasury_id = :treasury: AND l.line_date <= :asof: AND (eff.match_id IS NULL OR eff.effective = 0)";
    }

    private function effectiveMatches(): string
    {
        return "SELECT m.match_id, (sl.line_date <= :asof: AND mx.last_date <= :asof:) AS effective
                FROM {$this->table('bank_matches')} m
                JOIN {$this->table('bank_statement_lines')} sl ON sl.line_id = m.statement_line_id
                JOIN (SELECT ml2.match_id, MAX(j2.journal_date) AS last_date
                      FROM {$this->table('bank_match_lines')} ml2
                      JOIN {$this->table('accounting_journal_lines')} l2 ON l2.line_id = ml2.journal_line_id
                      JOIN {$this->table('accounting_journals')} j2 ON j2.journal_id = l2.journal_id
                      GROUP BY ml2.match_id) mx ON mx.match_id = m.match_id
                WHERE m.treasury_id = :treasury:";
    }

    /**
     * @return array{string, array<string, int|string>}
     */
    private function openQuery(int $treasuryId, int $accountId, string $asOf, ?string $coverageStart, string $part): array
    {
        $sql = "SELECT l.line_id, l.journal_id, j.series, j.number, j.journal_date, j.status, j.source_type, j.source_id, j.reverses_journal_id,
                       COALESCE(NULLIF(l.description, ''), j.description) AS description, (l.debit - l.credit) AS amount
                FROM {$this->table('accounting_journal_lines')} l
                JOIN {$this->table('accounting_journals')} j ON j.journal_id = l.journal_id
                LEFT JOIN {$this->table('bank_match_lines')} ml ON ml.journal_line_id = l.line_id
                LEFT JOIN ({$this->effectiveMatches()}) eff ON eff.match_id = ml.match_id
                WHERE l.account_id = :account: AND j.status IN (:posted:, :reversed:) AND j.journal_date <= :asof:
                  AND (eff.match_id IS NULL OR eff.effective = 0)
                  AND NOT " . $this->cancelledPair();

        $binds = $this->binds($accountId, $asOf) + ['treasury' => $treasuryId];

        if ($part === 'outstanding') {
            $binds['start'] = $coverageStart ?? '9999-12-31';
            $sql .= ' AND j.journal_date >= :start:';
        } elseif ($part === 'carried' && $coverageStart !== null) {
            $binds['start'] = $coverageStart;
            $sql .= ' AND j.journal_date < :start:';
        }

        return [$sql, $binds];
    }

    /**
     * Whether the line (l) is of a journal that was reversed or of the reversal of one, both dated on
     * or before the date, with nothing of either matched and the two making nothing on the account.
     */
    private function cancelledPair(): string
    {
        $journals = $this->table('accounting_journals');
        $lines    = $this->table('accounting_journal_lines');
        $matches  = $this->table('bank_match_lines');

        $clear = static fn (string $original, string $reversal): string => "(SELECT COALESCE(SUM(x.debit - x.credit), 0) FROM {$lines} x WHERE x.account_id = :account: AND x.journal_id IN ({$original}, {$reversal})) = 0
              AND NOT EXISTS (SELECT 1 FROM {$matches} mx JOIN {$lines} y ON y.line_id = mx.journal_line_id WHERE y.account_id = :account: AND y.journal_id IN ({$original}, {$reversal}))";

        return "(EXISTS (SELECT 1 FROM {$journals} r WHERE r.reverses_journal_id = j.journal_id AND j.status = :reversed: AND r.status IN (:posted:, :reversed:) AND r.journal_date <= :asof: AND " . $clear('j.journal_id', 'r.journal_id') . ")
                 OR EXISTS (SELECT 1 FROM {$journals} o WHERE o.journal_id = j.reverses_journal_id AND o.status = :reversed: AND o.journal_date <= :asof: AND " . $clear('o.journal_id', 'j.journal_id') . '))';
    }

    /**
     * @return array<string, int|string>
     */
    private function binds(int $accountId, ?string $asOf): array
    {
        return ['account' => $accountId, 'posted' => JournalStatus::Posted->value, 'reversed' => JournalStatus::Reversed->value, 'asof' => $asOf ?? '9999-12-31'];
    }

    private function table(string $name): string
    {
        return $this->db->prefixTable($name);
    }
}
