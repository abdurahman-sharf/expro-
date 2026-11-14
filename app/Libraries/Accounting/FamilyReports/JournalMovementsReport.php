<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\CostCenterService;
use App\Libraries\Accounting\JournalLineAnalysis;
use App\Libraries\Accounting\SaleAmounts;
use App\Libraries\Accounting\TrialBalance;
use DateTimeImmutable;
use Generator;

/**
 * The lines of the journals of a period, with the account, the words, the source, the debit and the
 * credit of each, grouped by journal, day, month, year or account, with a total for each group. The
 * debit and the credit come to the same, and when nothing narrows what is read they come to the
 * movement of the trial balance of the same branches and days. Each line says which cost centers it is
 * charged to, and the lines can be cut down to one cost center with the ones under it or to the lines
 * charged to none.
 */
final class JournalMovementsReport implements StreamsReport
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
        return $this->stream($input)->table();
    }

    public function stream(ReportInput $input): ReportStream
    {
        $group   = $input->choice('group', self::GROUPS, 'journal');
        $first   = (new DateTimeImmutable($input->today))->format('Y-m-01');
        $period  = $input->period($first);
        $source  = $input->choice('source', JournalSources::all(), '');
        $account = $input->id('account_id');
        $without = $input->flag('without_cost_center');
        $center  = $input->id('cost_center_id');
        $filters = [$input->scope, $period, $source === '' ? null : $source, $account > 0 ? $account : null, $input->journalOwner, $without, $center > 0 ? $center : null];
        $debit   = '0';
        $credit  = '0';
        $plan    = null;
        $days    = function () use (&$plan, $filters): array {
            return $plan ??= $this->lines->days(...$filters);
        };

        $events = function () use ($group, $filters, $days, &$debit, &$credit): Generator {
            $labels  = $this->costCenters->labels();
            $total   = $this->text('total');
            $key     = null;
            $debits  = '0';
            $credits = '0';

            foreach ($this->lines->stream(...$filters, byAccount: $group === 'account', days: $group === 'account' ? null : $days()) as [, $lines]) {
                $charges = $this->chargesOf($lines, $labels);

                foreach ($lines as $line) {
                    [$next, $title] = match ($group) {
                        'day'     => [(string) $line->journal_date, (string) $line->journal_date],
                        'month'   => [substr((string) $line->journal_date, 0, 7), PeriodUnit::Month->label(substr((string) $line->journal_date, 0, 7) . '-01')],
                        'year'    => [substr((string) $line->journal_date, 0, 4), substr((string) $line->journal_date, 0, 4)],
                        'account' => [(string) $line->account_code, $line->account_code . ' ' . $line->account_name],
                        default   => ['j' . $line->journal_id, $this->number($line) . ' - ' . $line->journal_date . ' - ' . $line->journal_description],
                    };

                    if ($next !== $key) {
                        if ($key !== null) {
                            yield StreamEvent::close(new ReportRow(['account' => $total, 'debit' => bcadd($debits, '0', SaleAmounts::SCALE), 'credit' => bcadd($credits, '0', SaleAmounts::SCALE)], null, true));
                        }

                        yield StreamEvent::open($title);

                        $key     = $next;
                        $debits  = '0';
                        $credits = '0';
                    }

                    $row = new ReportRow([
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

                    $debits  = bcadd($debits, $row->cell('debit'), SaleAmounts::SCALE);
                    $credits = bcadd($credits, $row->cell('credit'), SaleAmounts::SCALE);
                    $debit   = bcadd($debit, $row->cell('debit'), SaleAmounts::SCALE);
                    $credit  = bcadd($credit, $row->cell('credit'), SaleAmounts::SCALE);

                    yield StreamEvent::row($row);
                }
            }

            if ($key !== null) {
                yield StreamEvent::close(new ReportRow(['account' => $total, 'debit' => bcadd($debits, '0', SaleAmounts::SCALE), 'credit' => bcadd($credits, '0', SaleAmounts::SCALE)], null, true));
            }
        };

        $summary = function () use ($input, $period, $source, $account, $without, $center, &$debit, &$credit): array {
            $grand = new ReportRow(['account' => $this->text('grand_total'), 'debit' => bcadd($debit, '0', SaleAmounts::SCALE), 'credit' => bcadd($credit, '0', SaleAmounts::SCALE)], null, true);
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

            if ($input->defaultsToTheMonth()) {
                $notes[] = $this->text('note_default_month', $period->from);
            }

            return ['grand' => $grand, 'ties' => $ties, 'notes' => $notes];
        };

        $columns = [
            $this->column('date', ColumnKind::Date), $this->column('number'), $this->column('account'), $this->column('code'), $this->column('description'), $this->column('source'), $this->column('centers', ColumnKind::Text, 'col_cost_centers'),
            $this->column('debit', ColumnKind::Amount), $this->column('credit', ColumnKind::Amount),
        ];

        return new ReportStream($this->text('journal_movements_title'), $columns, $events, $summary, static fn (): int => array_sum($days()));
    }

    /**
     * @param list<object>       $lines
     * @param array<int, string> $labels
     *
     * @return array<int, string> What each line is charged to, in words.
     */
    private function chargesOf(array $lines, array $labels): array
    {
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
