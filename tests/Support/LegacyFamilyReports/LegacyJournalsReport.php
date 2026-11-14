<?php

namespace Tests\Support\LegacyFamilyReports;

use App\Libraries\Accounting\FamilyReports\BuildsReports;
use App\Libraries\Accounting\FamilyReports\ColumnKind;
use App\Libraries\Accounting\FamilyReports\FamilyReport;
use App\Libraries\Accounting\FamilyReports\JournalLines;
use App\Libraries\Accounting\FamilyReports\JournalSources;
use App\Libraries\Accounting\FamilyReports\ReportBlock;
use App\Libraries\Accounting\FamilyReports\ReportInput;
use App\Libraries\Accounting\FamilyReports\ReportRow;
use App\Libraries\Accounting\FamilyReports\ReportTable;
use App\Libraries\Accounting\FamilyReports\TieCheck;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\TrialBalance;

/**
 * @internal The report as it was before its rows were read in chunks, kept so that the tests can show the new one gives the same table.
 */
final class LegacyJournalsReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(
        private readonly JournalLines $lines = new JournalLines(),
        private readonly TrialBalance $trialBalance = new TrialBalance(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $period   = $input->period();
        $source   = $input->choice('source', JournalSources::all(), '');
        $search   = $input->text('search');
        $journals = $this->lines->journals($input->scope, $period, $source === '' ? null : $source, $input->journalOwner, $search);

        $rows = [];

        foreach ($journals as $journal) {
            $rows[] = new ReportRow([
                'date'        => (string) $journal->journal_date,
                'number'      => $journal->series === 'auto' ? 'A-' . $journal->number : (string) $journal->number,
                'description' => (string) $journal->description,
                'source'      => JournalSources::label((string) $journal->source_type) . (in_array((string) $journal->entry_type, ['revenue', 'cogs'], true) ? ' - ' . $this->text('entry_' . $journal->entry_type) : ''),
                'branch'      => (string) ($journal->branch_code ?? ''),
                'status'      => $this->text('status_' . $journal->status),
                'debit'       => bcadd((string) $journal->total_debit, '0', SaleAmounts::SCALE),
            ], base_url('accounting_journals/view/' . (int) $journal->journal_id));
        }

        $grand = $this->totalRow($rows, ['debit'], 'description', $this->text('grand_total'));
        $ties  = [];
        $notes = [$this->text('note_journals_count', count($rows))];

        if ($source === '' && $search === '' && $input->journalOwner === null) {
            $movement = $input->scope->isEmpty() ? '0.0000' : $this->trialBalance->build($input->scope, $period)->totals['debit'];
            $ties[]   = new TieCheck($this->text('tie_trial_balance'), $grand->cell('debit'), $movement);
        } else {
            $notes[] = $this->text('note_journal_narrowed');
        }

        $columns = [
            $this->column('date', ColumnKind::Date), $this->column('number'), $this->column('description'), $this->column('source'), $this->column('branch'), $this->column('status'), $this->column('debit', ColumnKind::Amount),
        ];

        return new ReportTable($this->text('journals_title'), $columns, [new ReportBlock(null, $rows)], $grand, $ties, $notes);
    }
}
