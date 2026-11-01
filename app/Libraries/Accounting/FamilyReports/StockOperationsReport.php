<?php

namespace App\Libraries\Accounting\FamilyReports;

use DateTimeImmutable;

/**
 * For each item the quantity that came in and went out in a period by kind of operation: purchases,
 * returned sales, transfers and manual changes in; sales, returns to suppliers, transfers and manual
 * changes out, and what it comes to. An operation that was reversed counts for nothing.
 */
final class StockOperationsReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(private readonly StockEntries $entries = new StockEntries())
    {
    }

    public function build(ReportInput $input): ReportTable
    {
        $period  = $input->period((new DateTimeImmutable($input->today))->modify('-1 month')->format('Y-m-d'));
        $filters = ['location_id' => $input->id('location_id'), 'item_id' => $input->id('item_id'), 'category' => $input->text('category')];
        $items   = [];
        $raw     = '0';

        foreach ($this->entries->movements($input->scope, $period->from, $period->to, $filters) as $entry) {
            $raw              = bcadd($raw, (string) $entry->quantity, 3);
            [$key, $quantity] = StockOperation::of((string) $entry->source_type, (string) $entry->kind, (string) $entry->quantity);

            $items[(int) $entry->item_id]['name']          = (string) $entry->item_name;
            $items[(int) $entry->item_id]['code']          = (string) ($entry->item_number ?? '');
            $items[(int) $entry->item_id]['sums'][$key]    = bcadd($items[(int) $entry->item_id]['sums'][$key] ?? '0', $quantity, 3);
        }

        uasort($items, static fn (array $first, array $second): int => mb_strtolower($first['name']) <=> mb_strtolower($second['name']));

        $rows = [];

        foreach ($items as $item) {
            $cells = ['code' => $item['code'], 'name' => $item['name']];
            $in    = '0';
            $out   = '0';

            foreach (StockOperation::KEYS as $key) {
                $cells[$key] = $item['sums'][$key] ?? '0.000';
                $in          = str_starts_with($key, 'in_') ? bcadd($in, $cells[$key], 3) : $in;
                $out         = str_starts_with($key, 'out_') ? bcadd($out, $cells[$key], 3) : $out;
            }

            $rows[] = new ReportRow([...$cells, 'in_total' => $in, 'out_total' => $out, 'movement' => bcadd($in, $out, 3)]);
        }

        $columns = [$this->column('code'), $this->column('name')];
        $keys    = ['in_total', 'out_total', 'movement'];

        foreach (StockOperation::KEYS as $key) {
            $columns[] = $this->column($key, ColumnKind::Quantity, 'op_' . $key);
            $keys[]    = $key;
        }

        array_push($columns, $this->column('in_total', ColumnKind::Quantity), $this->column('out_total', ColumnKind::Quantity), $this->column('movement', ColumnKind::Quantity));

        $total = $this->totalRow($rows, $keys, 'name', $this->text('grand_total'));

        return new ReportTable($this->text('stock_operations_title'), $columns, [new ReportBlock(null, $rows)], $total, [new TieCheck($this->text('tie_operations_movements'), $total->cell('movement'), bcadd($raw, '0', 4))], [$this->text('note_operations_kinds')]);
    }
}
