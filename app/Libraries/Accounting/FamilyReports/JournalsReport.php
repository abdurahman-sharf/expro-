<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\TrialBalance;
use DateTimeImmutable;
use Generator;

/**
 * The journals of a period, each once, with its date, number, words, source and what it debits, and the
 * total of what they debit, which is the movement of the trial balance of the same branches and days
 * when nothing narrows what is read.
 */
final class JournalsReport implements StreamsReport
{
    use BuildsReports;

    public function __construct(
        private readonly JournalLines $lines = new JournalLines(),
        private readonly TrialBalance $trialBalance = new TrialBalance(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        return $this->stream($input)->table();
    }

    public function stream(ReportInput $input): ReportStream
    {
        $period  = $input->period((new DateTimeImmutable($input->today))->format('Y-m-01'));
        $source  = $input->choice('source', JournalSources::all(), '');
        $search  = $input->text('search');
        $filters = [$input->scope, $period, $source === '' ? null : $source, $input->journalOwner, $search];
        $debit   = '0';
        $found   = 0;
        $plan    = null;
        $days    = function () use (&$plan, $filters): array {
            return $plan ??= $this->lines->journalDays(...$filters);
        };

        $events = function () use ($filters, $days, &$debit, &$found): Generator {
            yield StreamEvent::open(null);

            foreach ($this->lines->journalChunks(...$filters, days: $days()) as $journals) {
                foreach ($journals as $journal) {
                    $row = new ReportRow([
                        'date'        => (string) $journal->journal_date,
                        'number'      => $journal->series === 'auto' ? 'A-' . $journal->number : (string) $journal->number,
                        'description' => (string) $journal->description,
                        'source'      => JournalSources::label((string) $journal->source_type) . (in_array((string) $journal->entry_type, ['revenue', 'cogs'], true) ? ' - ' . $this->text('entry_' . $journal->entry_type) : ''),
                        'branch'      => (string) ($journal->branch_code ?? ''),
                        'status'      => $this->text('status_' . $journal->status),
                        'debit'       => bcadd((string) $journal->total_debit, '0', SaleAmounts::SCALE),
                    ], base_url('accounting_journals/view/' . (int) $journal->journal_id));

                    $debit = bcadd($debit, $row->cell('debit'), SaleAmounts::SCALE);
                    $found++;

                    yield StreamEvent::row($row);
                }
            }

            yield StreamEvent::close();
        };

        $summary = function () use ($input, $period, $source, $search, &$debit, &$found): array {
            $grand = new ReportRow(['description' => $this->text('grand_total'), 'debit' => bcadd($debit, '0', SaleAmounts::SCALE)], null, true);
            $ties  = [];
            $notes = [$this->text('note_journals_count', $found)];

            if ($source === '' && $search === '' && $input->journalOwner === null) {
                $movement = $input->scope->isEmpty() ? '0.0000' : $this->trialBalance->build($input->scope, $period)->totals['debit'];
                $ties[]   = new TieCheck($this->text('tie_trial_balance'), $grand->cell('debit'), $movement);
            } else {
                $notes[] = $this->text('note_journal_narrowed');
            }

            if ($input->defaultsToTheMonth()) {
                $notes[] = $this->text('note_default_month', $period->from);
            }

            return ['grand' => $grand, 'ties' => $ties, 'notes' => $notes];
        };

        $columns = [
            $this->column('date', ColumnKind::Date), $this->column('number'), $this->column('description'), $this->column('source'), $this->column('branch'), $this->column('status'), $this->column('debit', ColumnKind::Amount),
        ];

        return new ReportStream($this->text('journals_title'), $columns, $events, $summary, static fn (): int => array_sum($days()));
    }
}
