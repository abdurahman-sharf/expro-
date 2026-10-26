<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Keeps the one journal of a document in step with the document: a journal that is not there is
 * posted, one that no longer matches the document is reversed and posted again as the next
 * revision, one that still matches is left alone, and one the document no longer calls for is
 * reversed. A posted journal is never changed. What the treasuries allow the employee is asked
 * from the lines that move, so a change that moves no money asks nothing.
 */
final class DocumentJournals
{
    private BaseConnection $db;
    private PostingService $posting;
    private JournalService $journals;
    private PeriodGuard $periods;
    private TreasuryScopes $scopes;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->posting  = new PostingService($this->db);
        $this->journals = new JournalService($this->db);
        $this->periods  = new PeriodGuard($this->db);
        $this->scopes   = new TreasuryScopes($this->db, $branches);
    }

    /**
     * The journal of the document that stands, if there is one.
     */
    public function active(string $sourceType, int $sourceId): ?object
    {
        return $this->db->table('accounting_journals')
            ->select('journal_id, journal_date, branch_id, revision')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', JournalEntryType::General->value)
            ->where('status', JournalStatus::Posted->value)
            ->orderBy('revision', 'DESC')
            ->limit(1)
            ->get()
            ->getRow();
    }

    public function nextRevision(string $sourceType, int $sourceId): int
    {
        $row = $this->db->table('accounting_journals')
            ->selectMax('revision', 'last')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('entry_type', JournalEntryType::General->value)
            ->get()
            ->getRow();

        return ((int) ($row->last ?? 0)) + 1;
    }

    /**
     * @param string $reason Why the journal that stands is taken back, as the reversal records it.
     *
     * @throws AccountingException|PostingException
     */
    public function sync(string $sourceType, int $sourceId, ?JournalDraft $draft, ?int $employeeId, string $reason): JournalChange
    {
        $active = $this->active($sourceType, $sourceId);

        if ($active === null) {
            if ($draft === null) {
                return JournalChange::None;
            }

            $this->scopes->assertMayPost($draft->lines, $employeeId);
            $this->posting->post($draft);

            return JournalChange::Posted;
        }

        if ($draft !== null && $this->holds($active, $draft)) {
            return JournalChange::Unchanged;
        }

        $this->scopes->assertMayReplace((int) $active->journal_id, $draft, $employeeId);
        $this->reverse($active, $employeeId, $reason);

        if ($draft === null) {
            return JournalChange::Reversed;
        }

        $this->posting->post($draft);

        return JournalChange::Replaced;
    }

    /**
     * Whether the journal that stands is on the date, in the branch and with the lines of a draft.
     */
    private function holds(object $journal, JournalDraft $draft): bool
    {
        if ($journal->journal_date !== $draft->date || ($draft->branchId !== null && (int) $journal->branch_id !== $draft->branchId)) {
            return false;
        }

        $stored = array_map(
            static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', SaleAmounts::SCALE) . ':' . bcadd($line->credit, '0', SaleAmounts::SCALE),
            $this->db->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->get()->getResult(),
        );

        $wanted = array_map(
            static fn (JournalLineDraft $line): string => $line->accountId . ':' . bcadd($line->debit, '0', SaleAmounts::SCALE) . ':' . bcadd($line->credit, '0', SaleAmounts::SCALE),
            $draft->lines,
        );

        sort($stored);
        sort($wanted);

        return $stored === $wanted;
    }

    /**
     * A journal in a period that has been closed is taken back on the day it is taken back, which
     * is open, and a journal in an open period on its own day.
     *
     * @throws AccountingException|PostingException
     */
    private function reverse(object $journal, ?int $employeeId, string $reason): void
    {
        $cutoff = $this->periods->effectiveCutoff((int) $journal->branch_id, true);
        $date   = $cutoff !== null && $journal->journal_date <= $cutoff['date'] ? date('Y-m-d') : $journal->journal_date;

        $this->journals->reverseAutomatic((int) $journal->journal_id, $date, $reason, $employeeId);
    }
}
