<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Database\BaseConnection;

/**
 * The profit of each item, customer, employee or category: what was sold and what came back, each in units
 * and in value after discounts and without tax, the net of the two, what the net cost, and the profit as an
 * amount and as three percentages. The share is the profit of the group over the profit of all, the ratio to
 * sales is the profit over the net sales and the ratio to cost is the profit over the net cost; the share
 * of the total is a hundred, never an average of the rows. Cost is what the movements of the books valued,
 * so the total cost is the cost of sales of the books for the same days, which the report says.
 */
final class ItemProfitReport
{
    use BuildsBlocks;

    private const FIGURES = ['sales_quantity', 'sales_value', 'returns_quantity', 'returns_value', 'net_quantity', 'net_value', 'cost', 'profit'];

    private ProfitMovements $movements;

    public function __construct(?BaseConnection $db = null, ?ProfitMovements $movements = null)
    {
        $this->movements = $movements ?? new ProfitMovements($db);
    }

    public function build(ReportScope $scope, ItemGroup $group, ?string $from, string $to, ?int $customerId = null, ?int $employeeId = null, ?int $itemId = null, string $category = ''): ReportTable
    {
        $profits = $this->movements->read($scope, $from, $to, $customerId, $employeeId, $itemId, $category);
        $refused = ForeignAmounts::refuseProfit($profits, $scope, $from, $to);

        if ($refused !== null) {
            return $refused;
        }

        $groups = [];

        foreach ($profits as $row) {
            $key = match ($group) {
                ItemGroup::Category => $row->category,
                ItemGroup::Customer => (string) ($row->customerId ?? 0),
                ItemGroup::User     => (string) ($row->employeeId ?? 0),
                default             => (string) $row->itemId,
            };

            $groups[$key][] = $row;
        }

        $rows = [];

        foreach ($groups as $members) {
            $rows[] = [$this->name($group, $members[0]), $this->figures($members)];
        }

        usort($rows, static fn (array $a, array $b): int => mb_strtolower($a[0]) <=> mb_strtolower($b[0]));

        $total       = $this->figures($profits);
        $totalProfit = $total['profit'];
        $cells       = array_map(fn (array $row): array => [$group->value => $row[0], ...$row[1], ...$this->ratios($row[1], $totalProfit)], $rows);
        $filtered    = $customerId !== null || $employeeId !== null || $itemId !== null || $category !== '';

        return new ReportTable(
            scope: $scope,
            from: $from,
            to: $to,
            columns: $this->columns($group),
            blocks: [new ReportBlock([], $cells)],
            total: [...$total, ...$this->ratios($total, $totalProfit)],
            ties: $filtered ? [] : [new TieCheck('Accounting_operational_reports.tie_profit_cost', $total['cost'], $this->movements->ledgerCost($scope, $from, $to))],
            notes: ['Accounting_operational_reports.note_item_profit'],
            documents: count($profits),
        );
    }

    private function name(ItemGroup $group, ProfitRow $row): string
    {
        return match ($group) {
            ItemGroup::Category => $row->category === '' ? lang('Accounting_operational_reports.undefined') : $row->category,
            ItemGroup::Customer => $row->customerName !== '' ? $row->customerName : $this->walkIn(),
            ItemGroup::User     => $row->employeeName !== '' ? $row->employeeName : $this->nobody(),
            default             => $row->itemName,
        };
    }

    /**
     * @param list<ProfitRow> $rows
     *
     * @return array<string, string>
     */
    private function figures(array $rows): array
    {
        $sales   = array_filter($rows, static fn (ProfitRow $row): bool => ! $row->kind->isReturn());
        $returns = array_filter($rows, static fn (ProfitRow $row): bool => $row->kind->isReturn());

        $salesQuantity   = Amounts::sum(array_map(static fn (ProfitRow $row): string => $row->quantity, $sales));
        $salesValue      = Amounts::sum(array_map(static fn (ProfitRow $row): string => $row->value(), $sales));
        $returnsQuantity = Amounts::negate(Amounts::sum(array_map(static fn (ProfitRow $row): string => $row->quantity, $returns)));
        $returnsValue    = Amounts::negate(Amounts::sum(array_map(static fn (ProfitRow $row): string => $row->value(), $returns)));
        $cost            = Amounts::sum(array_map(static fn (ProfitRow $row): string => $row->cost, $rows));
        $netValue        = Amounts::sub($salesValue, $returnsValue);

        return [
            'sales_quantity'   => $salesQuantity,
            'sales_value'      => $salesValue,
            'returns_quantity' => $returnsQuantity,
            'returns_value'    => $returnsValue,
            'net_quantity'     => Amounts::sub($salesQuantity, $returnsQuantity),
            'net_value'        => $netValue,
            'cost'             => $cost,
            'profit'           => Amounts::sub($netValue, $cost),
        ];
    }

    /**
     * @param array<string, string> $figures
     *
     * @return array<string, string>
     */
    private function ratios(array $figures, string $totalProfit): array
    {
        return [
            'profit_share'    => Amounts::isZero($totalProfit) ? '0.00' : Amounts::percent($figures['profit'], $totalProfit),
            'profit_to_sales' => Amounts::percent($figures['profit'], $figures['net_value']),
            'profit_to_cost'  => Amounts::percent($figures['profit'], $figures['cost']),
        ];
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(ItemGroup $group): array
    {
        return [
            match ($group) {
                ItemGroup::Category => new ReportColumn('category', 'Accounting_operational_reports.col_category'),
                ItemGroup::Customer => new ReportColumn('customer', 'Accounting_operational_reports.col_customer'),
                ItemGroup::User     => new ReportColumn('user', 'Accounting_operational_reports.col_user'),
                default             => new ReportColumn('item', 'Accounting_operational_reports.col_item'),
            },
            new ReportColumn('sales_quantity', 'Accounting_operational_reports.col_sales_quantity', ColumnKind::Quantity),
            new ReportColumn('sales_value', 'Accounting_operational_reports.col_sales_value', ColumnKind::Amount),
            new ReportColumn('returns_quantity', 'Accounting_operational_reports.col_returns_quantity', ColumnKind::Quantity),
            new ReportColumn('returns_value', 'Accounting_operational_reports.col_returns_value', ColumnKind::Amount),
            new ReportColumn('net_quantity', 'Accounting_operational_reports.col_net_quantity', ColumnKind::Quantity),
            new ReportColumn('net_value', 'Accounting_operational_reports.col_net_value', ColumnKind::Amount),
            new ReportColumn('cost', 'Accounting_operational_reports.col_total_cost', ColumnKind::Amount),
            new ReportColumn('profit', 'Accounting_operational_reports.col_profit', ColumnKind::Amount),
            new ReportColumn('profit_share', 'Accounting_operational_reports.col_profit_share', ColumnKind::Percent),
            new ReportColumn('profit_to_sales', 'Accounting_operational_reports.col_profit_to_sales', ColumnKind::Percent),
            new ReportColumn('profit_to_cost', 'Accounting_operational_reports.col_profit_to_cost', ColumnKind::Percent),
        ];
    }
}
