<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Database\BaseConnection;

/**
 * The profit of the days of a report from the movements of stock that the books valued: what the goods that
 * left sold for before their discount and without tax, what the discount took off, and what the movements
 * cost. The profit is the price less the discount less the cost, and goods that cost nothing show their
 * whole price as profit. A row is dated by its movement, a service has no row, and a return costs what the
 * sale it takes back cost, so the cost of the report is the cost of sales of the books for the same days,
 * which the report says. It differs from the movements report of the reference system by the cost of a
 * return whose item changed its average cost between the sale and the return, which is deliberate.
 */
final class ProfitReport
{
    use BuildsBlocks;

    private const FIGURES = ['cost', 'sale_price', 'discount', 'profit'];

    private ProfitMovements $movements;

    public function __construct(?BaseConnection $db = null, ?ProfitMovements $movements = null)
    {
        $this->movements = $movements ?? new ProfitMovements($db);
    }

    public function build(ReportScope $scope, ItemGroup $group, bool $details, ?string $from, string $to, ?int $customerId = null, ?int $employeeId = null, ?int $itemId = null, string $category = '', bool $allDates = false): ReportTable
    {
        $period = $group->period();
        $from ??= $allDates ? null : $period?->defaultFrom($to);

        try {
            $profits = $this->movements->read($scope, $from, $to, $customerId, $employeeId, $itemId, $category);
        } catch (AccountingException $refusal) {
            return ReportTable::refused($scope, $from, $to, 'Accounting_operational_reports.too_many_documents', [(string) $refusal->context['count'], (string) $refusal->context['max']]);
        }
        $refused = ForeignAmounts::refuseProfit($profits, $scope, $from, $to);

        if ($refused !== null) {
            return $refused;
        }

        $groups  = $this->grouped($profits, $group, $from, $to);
        $blocks  = [];
        $summary = [];
        $all     = [];

        foreach ($groups as $key => $members) {
            $rows = array_map(fn (ProfitRow $row): array => $this->row($row), $members);

            foreach ($rows as $row) {
                $all[] = $row;
            }

            $sums  = $this->addUp($rows, ['quantity', ...self::FIGURES]);
            $label = $this->label($group, $key, $members);

            if ($details) {
                $blocks[] = new ReportBlock($this->heading($label['text'], $label['kind']), $rows, $sums);
            } else {
                $summary[] = [...$label['cells'], ...$sums];
            }
        }

        $filtered = $customerId !== null || $employeeId !== null || $itemId !== null || $category !== '';
        $total    = $this->addUp($all, ['quantity', ...self::FIGURES]);

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($group, $details),
            blocks: $details ? $blocks : [new ReportBlock([], $summary)],
            total: $total,
            ties: $filtered ? [] : [new TieCheck('Accounting_operational_reports.tie_profit_cost', $total['cost'], $this->movements->ledgerCost($scope, $from, $to))],
            notes: ['Accounting_operational_reports.note_profit'],
            documents: count($profits),
        );
    }

    /**
     * @param list<ProfitRow> $profits
     *
     * @return array<string, list<ProfitRow>>
     */
    private function grouped(array $profits, ItemGroup $group, ?string $from, string $to): array
    {
        $period = $group->period();
        $groups = [];

        if ($period !== null) {
            $start = $from ?? ($profits === [] ? null : $profits[0]->date);

            foreach ($start === null ? [] : $period->buckets($start, $to) as $bucket) {
                $groups[$bucket->key] = [];
            }

            foreach ($profits as $row) {
                $groups[$period->bucketOf($row->date)->key][] = $row;
            }

            ksort($groups);

            return $groups;
        }

        foreach ($profits as $row) {
            $key = match ($group) {
                ItemGroup::Item     => (string) $row->itemId,
                ItemGroup::Category => $row->category,
                ItemGroup::Customer => (string) ($row->customerId ?? 0),
                default             => (string) ($row->employeeId ?? 0),
            };

            $groups[$key][] = $row;
        }

        uasort($groups, fn (array $a, array $b): int => mb_strtolower($this->nameOf($group, $a[0])) <=> mb_strtolower($this->nameOf($group, $b[0])));

        return $groups;
    }

    private function nameOf(ItemGroup $group, ProfitRow $row): string
    {
        return match ($group) {
            ItemGroup::Item     => $row->itemName,
            ItemGroup::Category => $row->category === '' ? lang('Accounting_operational_reports.undefined') : $row->category,
            ItemGroup::Customer => $row->customerName !== '' ? $row->customerName : $this->walkIn(),
            default             => $row->employeeName !== '' ? $row->employeeName : $this->nobody(),
        };
    }

    /**
     * @param list<ProfitRow> $members
     *
     * @return array{text: string, kind: string, cells: array<string, string>}
     */
    private function label(ItemGroup $group, string $key, array $members): array
    {
        $period = $group->period();

        if ($period !== null) {
            $bucket = $period->bucketOf($key);

            return match ($period) {
                PeriodGroup::Day   => ['text' => $bucket->key, 'kind' => 'date', 'cells' => ['period' => $bucket->key]],
                PeriodGroup::Week  => ['text' => $bucket->from . '|' . $bucket->to, 'kind' => 'range', 'cells' => ['period_from' => $bucket->from, 'period_to' => $bucket->to]],
                PeriodGroup::Month => ['text' => substr($bucket->key, 0, 7), 'kind' => 'text', 'cells' => ['period' => substr($bucket->key, 0, 7)]],
                PeriodGroup::Year  => ['text' => substr($bucket->key, 0, 4), 'kind' => 'text', 'cells' => ['period' => substr($bucket->key, 0, 4)]],
            };
        }

        $name = $this->nameOf($group, $members[0]);

        return ['text' => $name, 'kind' => 'text', 'cells' => [$group->value => $name]];
    }

    /**
     * @return array<string, string>
     */
    private function row(ProfitRow $row): array
    {
        return [
            'number'     => $row->number,
            'date'       => $row->date,
            'customer'   => $row->customerName !== '' ? $row->customerName : $this->walkIn(),
            'user'       => $row->employeeName !== '' ? $row->employeeName : $this->nobody(),
            'item'       => $row->itemName,
            'quantity'   => $row->quantity,
            'unit_cost'  => $row->unitCost(),
            'cost'       => $row->cost,
            'unit_price' => $row->unitPrice(),
            'sale_price' => $row->salePrice,
            'discount'   => $row->discount,
            'profit'     => $row->profit(),
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(ItemGroup $group, bool $details): array
    {
        $money = [
            new ReportColumn('cost', 'Accounting_operational_reports.col_total_cost', ColumnKind::Amount),
            new ReportColumn('sale_price', 'Accounting_operational_reports.col_total_sales', ColumnKind::Amount),
            new ReportColumn('discount', 'Accounting_operational_reports.col_total_discounts', ColumnKind::Amount),
            new ReportColumn('profit', 'Accounting_operational_reports.col_total_profit', ColumnKind::Amount),
        ];

        if (! $details) {
            return [...match ($group) {
                ItemGroup::Item     => [new ReportColumn('item', 'Accounting_operational_reports.col_item')],
                ItemGroup::Category => [new ReportColumn('category', 'Accounting_operational_reports.col_category')],
                ItemGroup::Customer => [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')],
                ItemGroup::User     => [new ReportColumn('user', 'Accounting_operational_reports.col_user')],
                ItemGroup::Day      => [new ReportColumn('period', 'Accounting_operational_reports.col_date', ColumnKind::Date)],
                ItemGroup::Week     => [new ReportColumn('period_from', 'Accounting_operational_reports.col_week_start', ColumnKind::Date), new ReportColumn('period_to', 'Accounting_operational_reports.col_week_end', ColumnKind::Date)],
                ItemGroup::Month    => [new ReportColumn('period', 'Accounting_operational_reports.col_month')],
                ItemGroup::Year     => [new ReportColumn('period', 'Accounting_operational_reports.col_year')],
            }, new ReportColumn('quantity', 'Accounting_operational_reports.col_quantity', ColumnKind::Quantity), ...$money];
        }

        return [
            new ReportColumn('number', 'Accounting_operational_reports.col_number'),
            ...(in_array($group, [ItemGroup::Day, ItemGroup::Week, ItemGroup::Month, ItemGroup::Year], true) ? [] : [new ReportColumn('date', 'Accounting_operational_reports.col_date', ColumnKind::Date)]),
            ...($group === ItemGroup::Customer ? [] : [new ReportColumn('customer', 'Accounting_operational_reports.col_customer')]),
            ...($group === ItemGroup::User ? [] : [new ReportColumn('user', 'Accounting_operational_reports.col_user')]),
            ...($group === ItemGroup::Item ? [] : [new ReportColumn('item', 'Accounting_operational_reports.col_item')]),
            new ReportColumn('quantity', 'Accounting_operational_reports.col_quantity', ColumnKind::Quantity),
            new ReportColumn('unit_cost', 'Accounting_operational_reports.col_unit_cost', ColumnKind::Amount),
            new ReportColumn('cost', 'Accounting_operational_reports.col_cost', ColumnKind::Amount),
            new ReportColumn('unit_price', 'Accounting_operational_reports.col_unit_price', ColumnKind::Amount),
            new ReportColumn('sale_price', 'Accounting_operational_reports.col_sale_price', ColumnKind::Amount),
            new ReportColumn('discount', 'Accounting_operational_reports.col_discount', ColumnKind::Amount),
            new ReportColumn('profit', 'Accounting_operational_reports.col_profit', ColumnKind::Amount),
        ];
    }
}
