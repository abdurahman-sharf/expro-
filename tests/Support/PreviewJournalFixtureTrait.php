<?php

namespace Tests\Support;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;

/**
 * Previews of a batch of the retroactive posting, for a test that a balance or a report does not
 * count them: every journal that stands is written again as a preview with the same lines, so that a
 * report that counted previews would show twice what it showed.
 */
trait PreviewJournalFixtureTrait
{
    /**
     * @return int How many previews were written.
     */
    protected function previewEverything(): int
    {
        $db      = $this->accountingDb();
        $batchId = $this->previewBatch();
        $posting = new PostingService($db);
        $count   = 0;

        foreach ($db->table('accounting_journals')->whereIn('status', ['posted', 'reversed'])->orderBy('journal_id', 'ASC')->get()->getResult() as $journal) {
            $lines = array_map(
                static fn (object $line): JournalLineDraft => new JournalLineDraft((int) $line->account_id, $line->debit, $line->credit, $line->description, null, $line->party_type, $line->party_id === null ? null : (int) $line->party_id),
                $db->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
            );

            $posting->postPreview(new JournalDraft(date: $journal->journal_date, lines: $lines, sourceType: 'sale', sourceId: 700000 + (int) $journal->journal_id, branchId: (int) $journal->branch_id), $batchId);
            $count++;
        }

        return $count;
    }

    protected function previewBatch(): int
    {
        $db  = $this->accountingDb();
        $row = $db->table('accounting_backfill_batches')->where('name', 'fixture previews')->get()->getRow();

        if ($row !== null) {
            return (int) $row->batch_id;
        }

        $db->table('accounting_backfill_batches')->insert(['name' => 'fixture previews', 'status' => 'preview', 'start_date' => '2020-01-01', 'end_date' => '2035-12-31', 'families' => 'sales']);

        return (int) $db->insertID();
    }

    protected function assertPreviewsStand(): void
    {
        $this->assertGreaterThan(0, $this->accountingDb()->table('accounting_journals')->where('status', 'preview')->countAllResults(), 'The test wrote no preview');
    }
}
