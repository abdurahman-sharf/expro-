<?php

namespace App\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Organization\CompanyContext;

/**
 * The books themselves: journals that do not balance or hold no lines, posted journals that sit
 * inside a period that was closed before they were posted or outside every fiscal year, a document
 * posted twice under one source key, and the counters of numbers against the numbers already given.
 * A list stops at a thousand rows: the count says so, and the check fails either way.
 */
final class LedgerChecks extends CheckGroup
{
    private const LIMIT     = 1000;
    private const TOLERANCE = '0.00005';

    public function run(): array
    {
        $journals = ['accounting_journals.company_id', 'accounting_journals.total_debit', 'accounting_journal_lines'];

        return [
            $this->check('journals_unbalanced', 'LEDGER-BALANCE', 'Journals that do not balance', $journals, fn (): CheckResult => $this->unbalanced()),
            $this->check('journals_without_lines', 'LEDGER-LINES', 'Posted journals without lines', $journals, fn (): CheckResult => $this->withoutLines()),
            $this->check('period_locks', 'LEDGER-PERIODS', 'Posted journals against the period locks and the fiscal years', [...$journals, 'accounting_journals.branch_id', 'accounting_journals.posted_at', 'accounting_closed_periods.branch_id', 'accounting_closed_periods.created_at', 'accounting_fiscal_years.company_id'], fn (): CheckResult => $this->periodLocks()),
            $this->check('source_keys', 'LEDGER-SOURCE-KEYS', 'Duplicate source keys', ['accounting_journals.company_id', 'accounting_journals.reverses_journal_id'], fn (): CheckResult => $this->sourceKeys()),
            $this->check('numbering', 'WP8-NUMBERING', 'Numbering sequences against the highest numbers', ['document_sequences', 'document_numbers', 'accounting_sequences.company_id', 'accounting_journals.series'], fn (): CheckResult => $this->numbering()),
        ];
    }

    private function unbalanced(): CheckResult
    {
        $rows = $this->journalTotals('line_count > 0 AND (ABS(line_debit - line_credit) > ' . self::TOLERANCE . ' OR ABS(line_debit - total_debit) > ' . self::TOLERANCE . ' OR ABS(line_credit - total_credit) > ' . self::TOLERANCE . ')');

        if ($rows === []) {
            return CheckResult::pass('Every posted journal balances and its header totals equal its lines.');
        }

        $findings = array_map(
            static function (object $row): string {
                $balanced = bccomp((string) $row->line_debit, (string) $row->line_credit, 4) === 0;

                return sprintf(
                    'journal #%d (%s, %s) dated %s: lines debit %s and credit %s%s',
                    $row->journal_id,
                    $row->number ?? 'no number',
                    self::source($row),
                    $row->journal_date,
                    bcadd((string) $row->line_debit, '0', 4),
                    bcadd((string) $row->line_credit, '0', 4),
                    $balanced ? sprintf(', header says debit %s and credit %s', bcadd((string) $row->total_debit, '0', 4), bcadd((string) $row->total_credit, '0', 4)) : '',
                );
            },
            array_slice($rows, 0, self::LIMIT),
        );

        return CheckResult::fail($this->counted(count($rows)) . ' posted journal(s) do not balance or carry header totals that differ from their lines. A posted journal is never edited: it is reversed and posted again.', $findings, ['count' => min(count($rows), self::LIMIT), 'truncated' => count($rows) > self::LIMIT]);
    }

    private function withoutLines(): CheckResult
    {
        $rows = $this->journalTotals('line_count = 0');

        if ($rows === []) {
            return CheckResult::pass('Every posted journal has lines.');
        }

        $findings = array_map(
            static fn (object $row): string => sprintf('journal #%d (%s, %s) dated %s has no lines', $row->journal_id, $row->number ?? 'no number', self::source($row), $row->journal_date),
            array_slice($rows, 0, self::LIMIT),
        );

        return CheckResult::fail($this->counted(count($rows)) . ' posted journal(s) have no lines.', $findings, ['count' => min(count($rows), self::LIMIT), 'truncated' => count($rows) > self::LIMIT]);
    }

    /**
     * @return list<object>
     */
    private function journalTotals(string $having): array
    {
        return $this->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.source_type, j.source_id, j.journal_date, j.total_debit, j.total_credit, COUNT(l.line_id) AS line_count, COALESCE(SUM(l.debit), 0) AS line_debit, COALESCE(SUM(l.credit), 0) AS line_credit', false)
            ->join('accounting_journal_lines l', 'l.journal_id = j.journal_id', 'left')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', $this->standing())
            ->groupBy('j.journal_id')
            ->having($having, null, false)
            ->orderBy('j.journal_id', 'ASC')
            ->limit(self::LIMIT + 1)
            ->get()
            ->getResult();
    }

    private function periodLocks(): CheckResult
    {
        $closed = $this->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.branch_id, j.journal_date, j.posted_at, MAX(p.date_to) AS closed_through', false)
            ->join($this->db->prefixTable('accounting_closed_periods') . ' p', 'p.company_id = j.company_id AND p.active = 1 AND (p.branch_id IS NULL OR p.branch_id = j.branch_id) AND j.journal_date <= p.date_to AND j.posted_at > p.created_at', '', false)
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', $this->standing())
            ->groupBy('j.journal_id')
            ->orderBy('j.journal_id', 'ASC')
            ->limit(self::LIMIT + 1)
            ->get()
            ->getResult();

        $fiscalYears = $this->db->prefixTable('accounting_fiscal_years');
        $outside     = $this->db->table('accounting_journals j')
            ->select('j.journal_id, j.number, j.branch_id, j.journal_date')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', $this->standing())
            ->where("NOT EXISTS (SELECT 1 FROM {$fiscalYears} f WHERE f.company_id = j.company_id AND f.start_date <= j.journal_date AND f.end_date >= j.journal_date)", null, false)
            ->orderBy('j.journal_id', 'ASC')
            ->limit(self::LIMIT + 1)
            ->get()
            ->getResult();

        if ($closed === [] && $outside === []) {
            return CheckResult::pass('No posted journal was posted into a period closed at the time, and every one falls in a fiscal year.');
        }

        $findings = [
            ...array_map(
                static fn (object $row): string => sprintf('journal #%d (%s) dated %s in branch %s was posted at %s into a period closed through %s', $row->journal_id, $row->number ?? 'no number', $row->journal_date, $row->branch_id, $row->posted_at, $row->closed_through),
                array_slice($closed, 0, self::LIMIT),
            ),
            ...array_map(
                static fn (object $row): string => sprintf('journal #%d (%s) dated %s in branch %s is outside every fiscal year', $row->journal_id, $row->number ?? 'no number', $row->journal_date, $row->branch_id),
                array_slice($outside, 0, self::LIMIT),
            ),
        ];

        return CheckResult::fail(
            sprintf('%s posted journal(s) were posted into a closed period and %s lie outside every fiscal year: the period locks and the fiscal years do not agree with the books.', $this->counted(count($closed)), $this->counted(count($outside))),
            $findings,
            ['closed' => min(count($closed), self::LIMIT), 'outside_fiscal_years' => min(count($outside), self::LIMIT), 'truncated' => count($closed) > self::LIMIT || count($outside) > self::LIMIT],
        );
    }

    private function sourceKeys(): CheckResult
    {
        $documents = $this->db->table('accounting_journals')
            ->select('source_type, source_id, entry_type, COUNT(*) AS journals, GROUP_CONCAT(journal_id ORDER BY journal_id) AS journal_ids', false)
            ->where('company_id', CompanyContext::id())
            ->where('status', JournalStatus::Posted->value)
            ->where('source_id IS NOT NULL', null, false)
            ->whereNotIn('source_type', [JournalDraft::SOURCE_MANUAL, JournalDraft::SOURCE_REVERSAL])
            ->groupBy('source_type, source_id, entry_type')
            ->having('COUNT(*) > 1', null, false)
            ->orderBy('source_type', 'ASC')
            ->orderBy('source_id', 'ASC')
            ->limit(self::LIMIT + 1)
            ->get()
            ->getResult();

        $reversals = $this->db->table('accounting_journals')
            ->select('reverses_journal_id, COUNT(*) AS reversals, GROUP_CONCAT(journal_id ORDER BY journal_id) AS journal_ids', false)
            ->where('company_id', CompanyContext::id())
            ->whereIn('status', $this->standing())
            ->where('reverses_journal_id IS NOT NULL', null, false)
            ->groupBy('reverses_journal_id')
            ->having('COUNT(*) > 1', null, false)
            ->orderBy('reverses_journal_id', 'ASC')
            ->limit(self::LIMIT + 1)
            ->get()
            ->getResult();

        if ($documents === [] && $reversals === []) {
            return CheckResult::pass('No document stands in the books twice and no journal was reversed twice.');
        }

        $findings = [
            ...array_map(
                static fn (object $row): string => sprintf('%s %d (%s) stands as %d posted journals: %s', $row->source_type, $row->source_id, $row->entry_type, $row->journals, $row->journal_ids),
                array_slice($documents, 0, self::LIMIT),
            ),
            ...array_map(
                static fn (object $row): string => sprintf('journal #%d was reversed %d times by journals %s', $row->reverses_journal_id, $row->reversals, $row->journal_ids),
                array_slice($reversals, 0, self::LIMIT),
            ),
        ];

        return CheckResult::fail(
            sprintf('%s document(s) stand in the books under one source key more than once and %s journal(s) were reversed more than once.', $this->counted(count($documents)), $this->counted(count($reversals))),
            $findings,
            ['documents' => min(count($documents), self::LIMIT), 'reversed_twice' => min(count($reversals), self::LIMIT), 'truncated' => count($documents) > self::LIMIT || count($reversals) > self::LIMIT],
        );
    }

    private function numbering(): CheckResult
    {
        $numbering = new DocumentNumbering($this->db);
        $behind    = [];
        $notes     = [];
        $rows      = [];

        foreach ($numbering->overview() as $lowest) {
            $stored   = $numbering->sequence($lowest->type);
            $isBehind = $lowest->nextNumber > $stored->nextNumber;

            $rows[] = ['type' => $lowest->type->value, 'enabled' => $stored->enabled, 'prefix' => $stored->prefix, 'next_number' => $stored->nextNumber, 'lowest_next' => $lowest->nextNumber, 'behind' => $isBehind];

            if ($isBehind && $stored->enabled) {
                $behind[] = "{$lowest->type->value} is switched on and would give {$stored->preview()} next, but numbers up to " . ($lowest->nextNumber - 1) . ' are already given';
            } elseif ($isBehind) {
                $notes[] = "{$lowest->type->value} is switched off: it rises to {$lowest->nextNumber} on its own when switched on";
            }
        }

        $manual = $this->manualJournals();

        if ($manual !== null) {
            $behind[] = $manual;
        }

        $data = ['sequences' => $rows, 'manual_journals_behind' => $manual !== null];

        if ($behind === []) {
            return CheckResult::pass('Every switched-on sequence gives a number above every number already given, and the manual journal series is ahead of its journals.', $notes, $data);
        }

        return CheckResult::fail(count($behind) . ' counter(s) would give a number that is already taken.', [...$behind, ...$notes], $data);
    }

    private function manualJournals(): ?string
    {
        $last = (new AccountingSettings($this->db))->lastManualNumber();
        $top  = (int) $this->db->query(
            'SELECT COALESCE(MAX(CAST(number AS UNSIGNED)), 0) AS top FROM ' . $this->db->prefixTable('accounting_journals') . " WHERE company_id = ? AND series = 'manual' AND number REGEXP '^[0-9]+\$'",
            [CompanyContext::id()],
        )->getRow()->top;

        return $top > $last ? "the manual journal series stands at {$last} but a manual journal already carries number {$top}" : null;
    }

    /**
     * @return list<string>
     */
    private function standing(): array
    {
        return [JournalStatus::Posted->value, JournalStatus::Reversed->value];
    }

    private static function source(object $row): string
    {
        return trim($row->source_type . ' ' . ($row->source_id ?? ''));
    }

    private function counted(int $found): string
    {
        return $found > self::LIMIT ? self::LIMIT . '+' : (string) $found;
    }
}
