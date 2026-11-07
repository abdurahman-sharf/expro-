<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;

/**
 * What was spent, or what was received by receipt voucher, in a period: by category, by the employee who
 * entered it, by treasury or by period, as a summary or as the documents, each with its amount without
 * tax, its tax and its total. Every period of the range is listed once the range holds a document. The
 * total is set beside what the journals that stand for the documents hold.
 */
abstract class CashDocumentsReport implements FamilyReport
{
    use BuildsReports;

    private const KEYS = ['amount', 'tax', 'total'];

    public function __construct(private readonly CashDocumentRows $documents = new CashDocumentRows())
    {
    }

    abstract protected function kind(): string;

    /**
     * @return list<string>
     */
    abstract protected function groups(): array;

    abstract protected function titleKey(): string;

    public function build(ReportInput $input): ReportTable
    {
        $group   = $input->choice('group', $this->groups(), 'category');
        $view    = $input->choice('view', ['summary', 'detail'], 'summary');
        $unit    = PeriodUnit::tryFrom($group);
        $period  = $input->period($unit?->defaultFrom($input->today));
        $filters = ['treasury_id' => $input->id('treasury_id'), 'category_id' => $input->id('category_id'), 'employee_id' => $input->id('employee_id'), 'vendor' => $input->text('vendor')];
        $rows    = $this->documents->read($this->kind(), $input->scope, $period, $input->viewerId, $filters);
        $sets    = $this->grouped($rows, $group, $unit, $period->from, $period->to);

        $table = $view === 'detail' ? $this->detail($sets) : $this->summary($sets, $group);

        $reported = '0';
        $booked   = '0';
        $unposted = 0;

        foreach ($rows as $row) {
            $reported = bcadd($reported, $row->total, SaleAmounts::SCALE);

            if ($row->journal_total === null) {
                $unposted++;

                continue;
            }

            $booked = bcadd($booked, $row->journal_total, SaleAmounts::SCALE);
        }

        $notes = [$this->text('note_cash_total'), $this->text($this->kind() === CashDocumentRows::EXPENSE ? 'note_expense_groups' : 'note_income_groups')];

        $this->noteIfAny($notes, $unposted > 0, 'note_unposted', $unposted);
        $this->noteIfAny($notes, ! $input->scope->isCompany(), 'note_unbranched_hidden');

        return new ReportTable($this->text($this->titleKey()), $table['columns'], $table['blocks'], $table['grand'], [new TieCheck($this->text($this->kind() === CashDocumentRows::EXPENSE ? 'tie_expense_journals' : 'tie_income_journals'), $reported, $booked)], $notes);
    }

    /**
     * @param list<object> $rows
     *
     * @return array<string, array{label: string, rows: list<object>}>
     */
    private function grouped(array $rows, string $group, ?PeriodUnit $unit, ?string $from, string $to): array
    {
        $sets = [];

        if ($unit !== null && $rows !== []) {
            foreach ($unit->buckets($from ?? $rows[0]->date, $to) as $start) {
                $sets[$start] = ['label' => $unit->label($start), 'rows' => []];
            }
        }

        foreach ($rows as $row) {
            [$key, $label] = match ($group) {
                'user'     => ['u' . ($row->employee_id ?? 0), $row->employee === '' ? $this->text('no_user') : $row->employee],
                'vendor'   => ['v' . mb_strtolower($row->vendor), $row->vendor === '' ? $this->text('no_vendor') : $row->vendor],
                'treasury' => ['t' . ($row->treasury_id ?? 0), $row->treasury === '' ? $this->text('no_treasury') : $row->treasury],
                'category' => ['c' . ($row->category_id ?? 0), $row->category === '' ? $this->text('no_category') : $row->category],
                default    => [$unit?->start($row->date) ?? '', $unit?->label($unit->start($row->date)) ?? ''],
            };

            $sets[$key] ??= ['label' => $label, 'rows' => []];
            $sets[$key]['rows'][] = $row;
        }

        if ($unit === null) {
            uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['label']) <=> mb_strtolower($second['label']));
        } else {
            ksort($sets);
        }

        return $sets;
    }

    /**
     * @param array<string, array{label: string, rows: list<object>}> $sets
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function summary(array $sets, string $group): array
    {
        $lines = [];

        foreach ($sets as $set) {
            $amount = '0';
            $tax    = '0';

            foreach ($set['rows'] as $row) {
                $amount = bcadd($amount, $row->amount, SaleAmounts::SCALE);
                $tax    = bcadd($tax, $row->tax, SaleAmounts::SCALE);
            }

            $lines[] = new ReportRow(['group' => $set['label'], 'amount' => $this->amount($amount), 'tax' => $this->amount($tax), 'total' => bcadd($amount, $tax, SaleAmounts::SCALE)]);
        }

        return [
            'columns' => [$this->column('group', ColumnKind::Text, 'group_' . $group), $this->column('amount', ColumnKind::Amount), $this->column('tax', ColumnKind::Amount), $this->column('total', ColumnKind::Amount, 'col_total_with_tax')],
            'blocks'  => [new ReportBlock(null, $lines)],
            'grand'   => $this->totalRow($lines, self::KEYS, 'group', $this->text('total')),
        ];
    }

    /**
     * @param array<string, array{label: string, rows: list<object>}> $sets
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function detail(array $sets): array
    {
        $blocks  = [];
        $all     = [];
        $expense = $this->kind() === CashDocumentRows::EXPENSE;

        foreach ($sets as $set) {
            $lines = [];

            foreach ($set['rows'] as $row) {
                $lines[] = new ReportRow([
                    'number'   => $row->number,
                    'date'     => $row->date,
                    'treasury' => $row->treasury,
                    'category' => $row->category,
                    'vendor'   => $row->vendor,
                    'account'  => $row->account,
                    'user'     => $row->employee,
                    'note'     => $row->note,
                    'amount'   => $row->amount,
                    'tax'      => $row->tax,
                    'total'    => $row->total,
                ]);
            }

            $all      = [...$all, ...$lines];
            $blocks[] = new ReportBlock($set['label'], $lines, $this->totalRow($lines, self::KEYS, 'number', $this->text('total')));
        }

        $columns = [$this->column('number'), $this->column('date', ColumnKind::Date), $this->column('treasury'), $this->column('category')];

        if ($expense) {
            $columns[] = $this->column('vendor');
        }

        array_push($columns, $this->column('account'), $this->column('user'), $this->column('note'), $this->column('amount', ColumnKind::Amount), $this->column('tax', ColumnKind::Amount), $this->column('total', ColumnKind::Amount, 'col_total_with_tax'));

        return ['columns' => $columns, 'blocks' => $blocks, 'grand' => $this->totalRow($all, self::KEYS, 'number', $this->text('grand_total'))];
    }
}
