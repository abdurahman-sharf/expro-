<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalLineAnalysis;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\TrialBalance;

/**
 * The lines of the journals of a period, with the account, the words, the source, the debit and the
 * credit of each, grouped by journal, day, month, year or account, with a total for each group. The
 * debit and the credit come to the same, and when nothing narrows what is read they come to the
 * movement of the trial balance of the same branches and days. Each line says which cost centers it is
 * charged to, and the lines can be cut down to one cost center with the ones under it or to the lines
 * charged to none.
 */
final class JournalMovementsReport implements FamilyReport
{
    use BuildsReports;

    public const GROUPS = ['journal', 'day', 'month', 'year', 'account'];

    public function __construct(
        private readonly JournalLines $lines = new JournalLines(),
        private readonly TrialBalance $trialBalance = new TrialBalance(),
        private readonly ControlAccounts $accounts = new ControlAccounts(),
        private readonly JournalLineAnalysis $analysis = new JournalLineAnalysis(),
        private readonly CostCenterService $costCenters = new CostCenterService(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $group   = $input->choice('group', self::GROUPS, 'journal');
        $period  = $input->period();
        $source  = $input->choice('source', JournalSources::all(), '');
        $account = $input->id('account_id');
        $without = $input->flag('without_cost_center');
        $center  = $input->id('cost_center_id');
        $lines   = $this->lines->read($input->scope, $period, $source === '' ? null : $source, $account > 0 ? $account : null, $input->journalOwner, $without, $center > 0 ? $center : null);
        $charges = $this->chargesOf($lines);

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
                'centers'     => $charges[(int) $line->line_id] ?? '',
                'debit'       => bcadd((string) $line->debit, '0', SaleAmounts::SCALE),
                'credit'      => bcadd((string) $line->credit, '0', SaleAmounts::SCALE),
            ]);
        }

        if ($group === 'account') {
            ksort($sets);
        }

        $blocks = [];
        $all    = [];

        foreach ($sets as $set) {
            foreach ($set['lines'] as $row) {
                $all[] = $row;
            }

            $blocks[] = new ReportBlock($set['title'], $set['lines'], $this->totalRow($set['lines'], ['debit', 'credit'], 'account', $this->text('total')));
        }

        $grand = $this->totalRow($all, ['debit', 'credit'], 'account', $this->text('grand_total'));
        $ties  = [];
        $notes = [$this->text('note_journal_sources'), $this->text('note_journal_sides')];
        $whole = $source === '' && $input->journalOwner === null && ! $without && $center === 0;

        if ($account === 0 && ! $without && $center === 0) {
            $ties[] = new TieCheck($this->text('tie_debit_credit'), $grand->cell('debit'), $grand->cell('credit'));
        }

        if ($whole && $account === 0) {
            $movement = $input->scope->isEmpty() ? '0.0000' : $this->trialBalance->build($input->scope, $period)->totals['debit'];
            $ties[]   = new TieCheck($this->text('tie_trial_balance'), $grand->cell('debit'), $movement);
        } elseif ($whole && $account > 0) {
            $held   = $this->accounts->movement($this->accounts->under($account), $input->scope, $period->from, $period->to);
            $net    = bcsub($grand->cell('debit'), $grand->cell('credit'), SaleAmounts::SCALE);
            $ties[] = new TieCheck($this->text('tie_account_movement'), $net, $held);
        } else {
            $notes[] = $this->text('note_journal_narrowed');
        }

        $columns = [
            $this->column('date', ColumnKind::Date), $this->column('number'), $this->column('account'), $this->column('code'), $this->column('description'), $this->column('source'), $this->column('centers', ColumnKind::Text, 'col_cost_centers'),
            $this->column('debit', ColumnKind::Amount), $this->column('credit', ColumnKind::Amount),
        ];

        return new ReportTable($this->text('journal_movements_title'), $columns, $blocks, $grand, $ties, $notes);
    }

    /**
     * @param list<object> $lines
     *
     * @return array<int, string> What each line is charged to, in words.
     */
    private function chargesOf(array $lines): array
    {
        if ($lines === []) {
            return [];
        }

        $labels  = $this->costCenters->labels();
        $charges = [];

        foreach (array_chunk($lines, 2000) as $chunk) {
            foreach ($this->analysis->load($chunk) as $lineId => $analysis) {
                $charges[$lineId] = $analysis->costCenterId !== null
                    ? ($labels[$analysis->costCenterId] ?? '')
                    : implode('; ', array_map(static fn (array $share): string => ($labels[$share['cost_center_id']] ?? '') . ' ' . rtrim(rtrim($share['percent'], '0'), '.') . '%', $analysis->distribution));
            }
        }

        return $charges;
    }

    private function number(object $line): string
    {
        return $line->series === 'auto' ? 'A-' . $line->number : (string) $line->number;
    }
}
