<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\TrialBalance;

/**
 * The lines of the journals of a period, with the account, the words, the source, the debit and the
 * credit of each, grouped by journal, day, month, year or account, with a total for each group. The
 * debit and the credit come to the same, and when nothing narrows what is read they come to the
 * movement of the trial balance of the same branches and days.
 */
final class JournalMovementsReport implements FamilyReport
{
    use BuildsReports;

    public const GROUPS = ['journal', 'day', 'month', 'year', 'account'];

    public function __construct(
        private readonly JournalLines $lines = new JournalLines(),
        private readonly TrialBalance $trialBalance = new TrialBalance(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $group   = $input->choice('group', self::GROUPS, 'journal');
        $period  = $input->period();
        $source  = $input->choice('source', JournalSources::all(), '');
        $account = $input->id('account_id');
        $lines   = $this->lines->read($input->scope, $period, $source === '' ? null : $source, $account > 0 ? $account : null, $input->journalOwner);

        $sets = [];

        foreach ($lines as $line) {
            [$key, $title] = match ($group) {
                'day'     => [(string) $line->journal_date, (string) $line->journal_date],
                'month'   => [substr((string) $line->journal_date, 0, 7), PeriodUnit::Month->label(substr((string) $line->journal_date, 0, 7) . '-01')],
                'year'    => [substr((string) $line->journal_date, 0, 4), substr((string) $line->journal_date, 0, 4)],
                'account' => [(string) $line->account_code, $line->account_code . ' ' . $line->account_name],
                default   => ['j' . $line->journal_id, $this->number($line) . ' - ' . $line->journal_date . ' - ' . $line->journal_description],
            };

            $sets[$key]['title']   = $title;
            $sets[$key]['lines'][] = new ReportRow([
                'date'        => (string) $line->journal_date,
                'number'      => $this->number($line),
                'account'     => (string) $line->account_name,
                'code'        => (string) $line->account_code,
                'description' => trim((string) ($line->line_description ?? '')) === '' ? (string) $line->journal_description : (string) $line->line_description,
                'source'      => JournalSources::label((string) $line->source_type),
                'debit'       => bcadd((string) $line->debit, '0', SaleAmounts::SCALE),
                'credit'      => bcadd((string) $line->credit, '0', SaleAmounts::SCALE),
            ]);
        }

        ksort($sets);

        $blocks = [];
        $all    = [];

        foreach ($sets as $set) {
            $all      = [...$all, ...$set['lines']];
            $blocks[] = new ReportBlock($set['title'], $set['lines'], $this->totalRow($set['lines'], ['debit', 'credit'], 'account', $this->text('total')));
        }

        $grand = $this->totalRow($all, ['debit', 'credit'], 'account', $this->text('grand_total'));
        $ties  = [new TieCheck($this->text('tie_debit_credit'), $grand->cell('debit'), $grand->cell('credit'))];
        $notes = [$this->text('note_journal_sources'), $this->text('note_journal_sides')];

        if ($source === '' && $account === 0 && $input->journalOwner === null) {
            $movement = $input->scope->isEmpty() ? '0.0000' : $this->trialBalance->build($input->scope, $period)->totals['debit'];
            $ties[]   = new TieCheck($this->text('tie_trial_balance'), $grand->cell('debit'), $movement);
        } else {
            $notes[] = $this->text('note_journal_narrowed');
        }

        $columns = [
            $this->column('date', ColumnKind::Date), $this->column('number'), $this->column('account'), $this->column('code'), $this->column('description'), $this->column('source'),
            $this->column('debit', ColumnKind::Amount), $this->column('credit', ColumnKind::Amount),
        ];

        return new ReportTable($this->text('journal_movements_title'), $columns, $blocks, $grand, $ties, $notes);
    }

    private function number(object $line): string
    {
        return $line->series === 'auto' ? 'A-' . $line->number : (string) $line->number;
    }
}
