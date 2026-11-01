<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\SaleAmounts;

/**
 * How long the stock on hand has been there: for each item, or each item in each location, the
 * quantity in four fixed stretches of age (0 to 30 days, 31 to 60, 61 to 90 and over 90) and the
 * average age. What is on hand is taken to be what came in last: every quantity that went out takes
 * the oldest quantity that came in, and a document that was reversed takes back what it brought in.
 * An item with nothing on hand, or less than nothing, has none of it in any stretch.
 */
final class StockAgingReport implements FamilyReport
{
    use BuildsReports;

    private const BUCKETS = ['b0', 'b1', 'b2', 'b3'];

    public function __construct(private readonly StockEntries $entries = new StockEntries())
    {
    }

    public function build(ReportInput $input): ReportTable
    {
        $day     = ReportPeriod::of(null, $input->text('to') === '' ? $input->today : $input->text('to'))->to;
        $split   = $input->flag('split');
        $filters = ['location_id' => $input->id('location_id'), 'item_id' => $input->id('item_id'), 'category' => $input->text('category')];
        $search  = mb_strtolower($input->text('search'));
        $lots    = [];
        $named   = [];
        $nets    = [];

        foreach ($this->entries->movements($input->scope, null, $day, $filters) as $entry) {
            if ($search !== '' && ! str_contains(mb_strtolower($entry->item_name . ' ' . ($entry->item_number ?? '')), $search)) {
                continue;
            }

            $key         = (int) $entry->item_id . ':' . (int) $entry->location_id;
            $named[$key] = $entry;
            $nets[$key]  = bcadd($nets[$key] ?? '0', (string) $entry->quantity, 3);
            $lots[$key]  = $this->apply($lots[$key] ?? ['lots' => [], 'deficit' => '0'], $entry);
        }

        $items = [];

        foreach ($lots as $key => $state) {
            $remaining = $state['lots'];
            $entry     = $named[$key];
            $rowKey  = $split ? $key : (string) (int) $entry->item_id;
            $items[$rowKey] ??= ['name' => (string) $entry->item_name, 'code' => (string) ($entry->item_number ?? ''), 'location' => $split ? (string) $entry->location_name : '', 'lots' => []];
            $items[$rowKey]['lots'] = [...$items[$rowKey]['lots'], ...array_values($remaining)];
        }

        uasort($items, static fn (array $first, array $second): int => [mb_strtolower($first['name']), $first['location']] <=> [mb_strtolower($second['name']), $second['location']]);

        $rows    = [];
        $ages    = [];
        $onHand  = '0';
        $netSums = '0';

        foreach ($items as $item) {
            $buckets = array_fill_keys(self::BUCKETS, '0');
            $units   = '0';
            $aged    = '0';

            foreach ($item['lots'] as $lot) {
                if (bccomp($lot['quantity'], '0', 3) <= 0) {
                    continue;
                }

                $age     = max(0, AgingBuckets::ageInDays($lot['date'], $day));
                $slot    = $age <= 30 ? 'b0' : ($age <= 60 ? 'b1' : ($age <= 90 ? 'b2' : 'b3'));
                $buckets[$slot] = bcadd($buckets[$slot], $lot['quantity'], 3);
                $units   = bcadd($units, $lot['quantity'], 3);
                $aged    = bcadd($aged, bcmul($lot['quantity'], (string) $age, 3), 3);
            }

            $average = bccomp($units, '0', 3) > 0 ? SaleAmounts::round(bcdiv($aged, $units, 6), 1) : '0.0';

            if (bccomp($units, '0', 3) > 0) {
                $ages[] = $average;
            }

            $onHand   = bcadd($onHand, $units, 3);
            $netSums  = bcadd($netSums, $units, 3);
            $rows[]   = new ReportRow(['code' => $item['code'], 'name' => $item['name'], 'location' => $item['location'], 'available' => $units, ...$buckets, 'age' => $average]);
        }

        $columns = [$this->column('code'), $this->column('name')];

        if ($split) {
            $columns[] = $this->column('location');
        }

        array_push($columns, $this->column('available', ColumnKind::Quantity, 'col_quantity_available'));

        foreach (self::BUCKETS as $index => $bucket) {
            $columns[] = new ReportColumn($bucket, ['0 - 30', '31 - 60', '61 - 90', '90+'][$index] . ' ' . lang('Accounting_family_reports.age_days'), ColumnKind::Quantity);
        }

        $columns[] = $this->column('age', ColumnKind::Plain, 'col_average_age');

        $sums  = $this->sums($rows, ['available', ...self::BUCKETS]);
        $grand = new ReportRow(
            ['name' => $this->text('grand_total'), ...$sums, 'age' => $ages === [] ? '0.0' : SaleAmounts::round(bcdiv(array_reduce($ages, static fn (string $sum, string $age): string => bcadd($sum, $age, 6), '0'), (string) count($ages), 6), 1)],
            null,
            true,
        );

        $reported = $sums['available'];
        $book     = '0';

        foreach ($nets as $net) {
            $book = bcadd($book, bccomp($net, '0', 3) > 0 ? $net : '0', 3);
        }

        return new ReportTable($this->text('stock_aging_title'), $columns, [new ReportBlock(null, $rows)], $grand, [new TieCheck($this->text('tie_aging_quantity'), $reported, bcadd($book, '0', SaleAmounts::SCALE))], [$this->text('note_aging_rule'), $this->text('note_aging_average')]);
    }

    /**
     * @param array{lots: array<string, array{date: string, quantity: string}>, deficit: string} $state
     * @param object{kind: string, quantity: string, entry_date: string, source_type: string, source_id: int, entry_type: string, revision: int, line_no: int, entry_id: int} $entry
     *
     * @return array{lots: array<string, array{date: string, quantity: string}>, deficit: string}
     */
    private function apply(array $state, object $entry): array
    {
        $quantity = (string) $entry->quantity;
        $key      = implode('|', [$entry->source_type, $entry->source_id, $entry->entry_type, $entry->revision, $entry->line_no]);
        $lots     = $state['lots'];
        $deficit  = $state['deficit'];
        $out      = '0';

        if (bccomp($quantity, '0', 3) > 0) {
            $absorbed = bccomp($deficit, $quantity, 3) < 0 ? $deficit : $quantity;
            $deficit  = bcsub($deficit, $absorbed, 3);
            $left     = bcsub($quantity, $absorbed, 3);
            $id       = $entry->kind === 'posting' ? $key : 'r' . $entry->entry_id;

            if (bccomp($left, '0', 3) > 0) {
                $lots[$id] = ['date' => (string) $entry->entry_date, 'quantity' => $left];
            }

            return ['lots' => $lots, 'deficit' => $deficit];
        }

        $out = ltrim($quantity, '-');

        if ($entry->kind === 'reversal' && isset($lots[$key])) {
            $taken                 = bccomp($lots[$key]['quantity'], $out, 3) < 0 ? $lots[$key]['quantity'] : $out;
            $lots[$key]['quantity'] = bcsub($lots[$key]['quantity'], $taken, 3);
            $out                   = bcsub($out, $taken, 3);
        }

        foreach ($lots as $id => $lot) {
            if (bccomp($out, '0', 3) <= 0) {
                break;
            }

            $taken                 = bccomp($lot['quantity'], $out, 3) < 0 ? $lot['quantity'] : $out;
            $lots[$id]['quantity'] = bcsub($lot['quantity'], $taken, 3);
            $out                   = bcsub($out, $taken, 3);
        }

        return ['lots' => $lots, 'deficit' => bcadd($deficit, $out, 3)];
    }
}
