<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\RoutingKey;
use App\Libraries\Accounting\SaleAmounts;
use DateTimeImmutable;

/**
 * How fast the stock turns over in a period, in the usual way: the cost of what was sold, divided by
 * the average of the value of the stock at the start and at the end, is the turnover, and the days of
 * the period divided by it are the days it takes to sell the stock. The cost of sales is what the
 * sales took out of the stock, less what returned to it, from the valued movements. The total is the
 * turnover of the whole, not the sum of the turnovers of the items. The cost of sales is set beside
 * what the sales journals charged to the cost of sales, and the value at the end beside the accounts
 * of the stock locations.
 */
final class StockTurnoverReport implements FamilyReport
{
    use BuildsReports;

    private const SALE = 'sale';

    public function __construct(
        private readonly StockEntries $entries = new StockEntries(),
        private readonly StockTies $ties = new StockTies(),
        private readonly ControlAccounts $accounts = new ControlAccounts(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $to      = ReportPeriod::of(null, $input->text('to') === '' ? $input->today : $input->text('to'))->to;
        $from    = $input->text('from') === '' ? (new DateTimeImmutable($to))->format('Y-01-01') : $input->text('from');
        $period  = ReportPeriod::of($from, $to);
        $group   = $input->choice('group', ['item', 'category'], 'item');
        $days    = (string) ((int) (new DateTimeImmutable($period->from ?? $period->to))->diff(new DateTimeImmutable($period->to))->format('%a') + 1);
        $filters = ['location_id' => $input->id('location_id'), 'item_id' => $input->id('item_id'), 'category' => $input->text('category')];
        $sets    = [];

        $add = function (object $row, string $slot, string $amount) use (&$sets, $group): void {
            $key = $group === 'category' ? (string) $row->category : (string) (int) $row->item_id;

            $sets[$key] ??= ['name' => $group === 'category' ? ((string) $row->category === '' ? $this->text('no_category') : (string) $row->category) : (string) $row->item_name, 'code' => $group === 'category' ? '' : (string) ($row->item_number ?? ''), 'category' => (string) $row->category, 'cogs' => '0', 'opening' => '0', 'closing' => '0'];
            $sets[$key][$slot] = bcadd($sets[$key][$slot], $amount, SaleAmounts::SCALE);
        };

        foreach ($this->entries->totalsByItem($input->scope, $period->from, null, $filters) as $row) {
            $add($row, 'opening', (string) $row->value);
        }

        foreach ($this->entries->totalsByItem($input->scope, null, $period->to, $filters) as $row) {
            $add($row, 'closing', (string) $row->value);
        }

        $cogs = '0';

        foreach ($this->entries->costOfSalesByItem($input->scope, $period->from, $period->to, self::SALE, $filters) as $row) {
            $add($row, 'cogs', (string) $row->cost);
            $cogs = bcadd($cogs, (string) $row->cost, SaleAmounts::SCALE);
        }

        uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['name']) <=> mb_strtolower($second['name']));

        $rows = [];

        foreach ($sets as $set) {
            $rows[] = new ReportRow($this->cells($set, $days));
        }

        $total = $this->totalRow($rows, ['cogs', 'opening', 'closing', 'average'], 'name', $this->text('grand_total'));
        $cells = [...$total->cells, ...$this->ratios($total->cell('cogs'), $total->cell('average'), $days)];

        $columns = [$this->column('code'), $this->column('name')];

        if ($group === 'item') {
            $columns[] = $this->column('category');
        }

        array_push(
            $columns,
            $this->column('cogs', ColumnKind::Amount),
            $this->column('opening', ColumnKind::Amount, 'col_opening_value'),
            $this->column('closing', ColumnKind::Amount, 'col_closing_value'),
            $this->column('average', ColumnKind::Amount, 'col_average_value'),
            $this->column('ratio', ColumnKind::Plain, 'col_turnover'),
            $this->column('days', ColumnKind::Plain, 'col_days_to_sell'),
        );

        $ties  = [];
        $notes = [$this->text('note_turnover_definition', $days), $this->text('note_turnover_total')];
        $whole = $filters['item_id'] === 0 && $filters['category'] === '';

        if ($whole) {
            $ties[] = $this->ties->closing($input->scope, $total->cell('closing'), $period->to, $input->today, $filters['location_id'] > 0 ? [$filters['location_id']] : null);

            if ($filters['location_id'] === 0) {
                $charged = $this->accounts->movement($this->accounts->tree(RoutingKey::CostOfGoodsSold), $input->scope, $period->from, $period->to, [self::SALE]);
                $ties[]  = new TieCheck($this->text('tie_cost_of_sales'), bcadd($cogs, '0', SaleAmounts::SCALE), $charged);
            }
        } else {
            $notes[] = $this->text('note_item_filter_not_compared');
        }

        return new ReportTable($this->text('stock_turnover_title'), $columns, [new ReportBlock(null, $rows)], new ReportRow($cells, null, true), $ties, $notes);
    }

    /**
     * @param array{name: string, code: string, category: string, cogs: string, opening: string, closing: string} $set
     *
     * @return array<string, string>
     */
    private function cells(array $set, string $days): array
    {
        $average = bcdiv(bcadd($set['opening'], $set['closing'], SaleAmounts::SCALE), '2', SaleAmounts::SCALE);

        return ['code' => $set['code'], 'name' => $set['name'], 'category' => $set['category'], 'cogs' => $set['cogs'], 'opening' => $set['opening'], 'closing' => $set['closing'], 'average' => $average, ...$this->ratios($set['cogs'], $average, $days)];
    }

    /**
     * @return array{ratio: string, days: string}
     */
    private function ratios(string $cogs, string $average, string $days): array
    {
        if (bccomp($average, '0', SaleAmounts::SCALE) <= 0 || bccomp($cogs, '0', SaleAmounts::SCALE) <= 0) {
            return ['ratio' => '0.00', 'days' => '0.00'];
        }

        return [
            'ratio' => SaleAmounts::round(bcdiv($cogs, $average, 6), 2),
            'days'  => SaleAmounts::round(bcdiv(bcmul($days, $average, 6), $cogs, 6), 2),
        ];
    }
}
