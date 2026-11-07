<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\SaleAmounts;

/**
 * The money paid to suppliers in a period, by supplier, employee, way of payment or period, or one by
 * one. The total is set beside what the journals of those payments took out of the treasury and bank
 * accounts, which it equals when the whole company is read.
 */
final class SupplierPaymentsReport implements FamilyReport
{
    use BuildsReports;

    public const GROUPS = ['supplier', 'user', 'method', 'day', 'week', 'month', 'year'];

    public function __construct(
        private readonly SupplierPaymentRows $payments = new SupplierPaymentRows(),
        private readonly ControlAccounts $accounts = new ControlAccounts(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $group   = $input->choice('group', self::GROUPS, 'supplier');
        $view    = $input->choice('view', ['summary', 'detail'], 'summary');
        $unit    = PeriodUnit::tryFrom($group);
        $period  = $input->period($unit?->defaultFrom($input->today));
        $filters = ['supplier_id' => $input->id('supplier_id'), 'employee_id' => $input->id('employee_id'), 'payment_type' => $input->text('payment_type')];
        $rows    = $this->payments->read($input->scope, $period, $input->viewerId, $filters);

        $sets = [];

        if ($unit !== null && $rows !== []) {
            foreach ($unit->buckets($period->from ?? $rows[0]->doc_date, $period->to) as $start) {
                $sets[$start] = ['label' => $unit->label($start), 'rows' => []];
            }
        }

        foreach ($rows as $row) {
            [$key, $label] = match (true) {
                $unit !== null        => [$unit->start($row->doc_date), $unit->label($unit->start($row->doc_date))],
                $group === 'supplier' => ['s' . $row->supplier_id, $row->supplier_name],
                $group === 'method'   => ['m' . $row->payment_type, $row->payment_type === '' ? $this->text('no_method') : $row->payment_type],
                default               => ['u' . ($row->employee_id ?? 0), $row->employee_name === '' ? $this->text('no_user') : $row->employee_name],
            };

            $sets[$key] ??= ['label' => $label, 'rows' => []];
            $sets[$key]['rows'][] = $row;
        }

        if ($unit === null) {
            uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['label']) <=> mb_strtolower($second['label']));
        } else {
            ksort($sets);
        }

        $reported = '0';

        foreach ($rows as $row) {
            $reported = bcadd($reported, $row->amount, SaleAmounts::SCALE);
        }

        $table = $view === 'detail' ? $this->detail($sets) : $this->summary($sets, $group);
        $notes = [$this->text('note_payments_sources')];
        $ties  = [];

        $comparable = $input->scope->isCompany() && $input->viewerId === null && $filters['supplier_id'] === 0 && $filters['employee_id'] === 0 && $filters['payment_type'] === '';

        if ($comparable) {
            $cash   = $this->accounts->movement($this->accounts->tree(RoutingKey::Treasuries, RoutingKey::Banks), $input->scope, $period->from, $period->to, SupplierPaymentRows::SOURCES);
            $ties[] = new TieCheck($this->text('tie_treasury_payments'), bcadd($reported, '0', SaleAmounts::SCALE), bcsub('0', $cash, SaleAmounts::SCALE));
        } else {
            $notes[] = $this->text('note_payments_not_compared');
        }

        return new ReportTable($this->text('supplier_payments_title'), $table['columns'], $table['blocks'], $table['grand'], $ties, $notes);
    }

    /**
     * @param array<string, array{label: string, rows: list<object>}> $sets
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function summary(array $sets, string $group): array
    {
        $rows = [];

        foreach ($sets as $set) {
            $amount = '0';

            foreach ($set['rows'] as $row) {
                $amount = bcadd($amount, $row->amount, SaleAmounts::SCALE);
            }

            $rows[] = new ReportRow(['group' => $set['label'], 'count' => (string) count($set['rows']), 'amount' => $this->amount($amount)]);
        }

        return [
            'columns' => [$this->column('group', ColumnKind::Text, 'group_' . $group), $this->column('count', ColumnKind::Plain), $this->column('amount', ColumnKind::Amount)],
            'blocks'  => [new ReportBlock(null, $rows)],
            'grand'   => $this->totalRow($rows, ['count', 'amount'], 'group', $this->text('total')),
        ];
    }

    /**
     * @param array<string, array{label: string, rows: list<object>}> $sets
     *
     * @return array{columns: list<ReportColumn>, blocks: list<ReportBlock>, grand: ReportRow}
     */
    private function detail(array $sets): array
    {
        $blocks = [];
        $all    = [];

        foreach ($sets as $set) {
            if ($set['rows'] === []) {
                continue;
            }

            $rows = [];

            foreach ($set['rows'] as $row) {
                $rows[] = new ReportRow([
                    'number'    => $row->number,
                    'kind'      => $this->text('payment_kind_' . $row->kind),
                    'date'      => $row->doc_date,
                    'user'      => $row->employee_name,
                    'supplier'  => $row->supplier_name,
                    'method'    => $row->payment_type,
                    'reference' => $row->reference_code,
                    'treasury'  => $row->treasury_name,
                    'amount'    => $row->amount,
                ]);
            }

            $all      = [...$all, ...$rows];
            $blocks[] = new ReportBlock($set['label'], $rows, $this->totalRow($rows, ['amount'], 'number', $this->text('total')));
        }

        return [
            'columns' => [
                $this->column('number'), $this->column('kind'), $this->column('date', ColumnKind::Date), $this->column('user'), $this->column('supplier'),
                $this->column('method'), $this->column('reference'), $this->column('treasury'), $this->column('amount', ColumnKind::Amount),
            ],
            'blocks' => $blocks,
            'grand'  => $this->totalRow($all, ['amount'], 'number', $this->text('grand_total')),
        ];
    }
}
