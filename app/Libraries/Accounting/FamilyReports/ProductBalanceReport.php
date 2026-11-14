<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;
use DateTimeImmutable;

/**
 * For each item what it held before a period, what came in, what went out and what it holds after,
 * in quantity and in value, from the valued movements. The value after is set beside the accounts of
 * the stock locations, as the valuation of the stock is.
 */
final class ProductBalanceReport implements FamilyReport
{
    use BuildsReports;

    private const KEYS = ['before_quantity', 'before_value', 'in_quantity', 'in_value', 'out_quantity', 'out_value', 'net_quantity', 'net_value'];

    public function __construct(
        private readonly StockEntries $entries = new StockEntries(),
        private readonly StockTies $ties = new StockTies(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $period  = $input->period((new DateTimeImmutable($input->today))->format('Y-01-01'));
        $filters = ['location_id' => $input->id('location_id'), 'item_id' => $input->id('item_id'), 'category' => $input->text('category')];
        $items   = [];

        foreach ($this->entries->totalsByItem($input->scope, $period->from, null, $filters) as $row) {
            $items[(int) $row->item_id]                    = $this->fresh((string) $row->item_name, (string) ($row->item_number ?? ''), null);
            $items[(int) $row->item_id]['before_quantity'] = bcadd('0', (string) $row->quantity, 3);
            $items[(int) $row->item_id]['before_value']    = bcadd('0', (string) $row->value, SaleAmounts::SCALE);
        }

        $moved = $this->entries->inAndOutByItem($input->scope, $period->from, $period->to, $filters);

        usort($moved, static fn (object $first, object $second): int => strcmp($first->first_seen, $second->first_seen));

        foreach ($moved as $row) {
            $id         = (int) $row->item_id;
            $items[$id] = $this->fresh((string) $row->item_name, (string) ($row->item_number ?? ''), $items[$id] ?? null);

            foreach (['in', 'out'] as $way) {
                if ((int) $row->{$way . '_count'} > 0) {
                    $items[$id][$way . '_quantity'] = bcadd('0', (string) $row->{$way . '_quantity'}, 3);
                    $items[$id][$way . '_value']    = bcadd('0', (string) $row->{$way . '_value'}, SaleAmounts::SCALE);
                }
            }
        }

        uasort($items, static fn (array $first, array $second): int => mb_strtolower($first['name']) <=> mb_strtolower($second['name']));

        $rows = [];

        foreach ($items as $item) {
            $net = [
                bcsub(bcadd($item['before_quantity'], $item['in_quantity'], 3), $item['out_quantity'], 3),
                bcsub(bcadd($item['before_value'], $item['in_value'], SaleAmounts::SCALE), $item['out_value'], SaleAmounts::SCALE),
            ];

            $row = new ReportRow([
                'code'            => $item['code'], 'name' => $item['name'],
                'before_quantity' => $item['before_quantity'], 'before_value' => $item['before_value'],
                'in_quantity'     => $item['in_quantity'], 'in_value' => $item['in_value'],
                'out_quantity'    => $item['out_quantity'], 'out_value' => $item['out_value'],
                'net_quantity'    => $net[0], 'net_value' => $net[1],
            ]);

            if ($input->flag('empty') || array_filter($row->cells, static fn (string $cell, string $key): bool => $key !== 'code' && $key !== 'name' && is_numeric($cell) && bccomp($cell, '0', 4) !== 0, ARRAY_FILTER_USE_BOTH) !== []) {
                $rows[] = $row;
            }
        }

        $columns = [$this->column('code'), $this->column('name')];

        foreach (self::KEYS as $key) {
            $columns[] = $this->column($key, str_ends_with($key, '_value') ? ColumnKind::Amount : ColumnKind::Quantity);
        }

        $total = $this->totalRow($rows, self::KEYS, 'name', $this->text('grand_total'));
        $notes = [$this->text('note_product_balance')];
        $ties  = [];

        if ($filters['item_id'] === 0 && $filters['category'] === '') {
            $ties[] = $this->ties->closing($input->scope, $total->cell('net_value'), $period->to, $input->today, $filters['location_id'] > 0 ? [$filters['location_id']] : null);
        } else {
            $notes[] = $this->text('note_item_filter_not_compared');
        }

        return new ReportTable($this->text('product_balance_title'), $columns, [new ReportBlock(null, $rows)], $total, $ties, $notes);
    }

    /**
     * @param array<string, string>|null $item
     *
     * @return array<string, string>
     */
    private function fresh(string $name, string $code, ?array $item): array
    {
        return $item ?? ['name' => $name, 'code' => $code, 'before_quantity' => '0', 'before_value' => '0', 'in_quantity' => '0', 'in_value' => '0', 'out_quantity' => '0', 'out_value' => '0'];
    }
}
