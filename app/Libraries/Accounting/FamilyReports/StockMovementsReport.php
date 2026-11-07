<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;

/**
 * The movements of each item in each location in a period, one after the other, each with its kind and
 * its document, after the quantity the item held before the first day. Seen as valued, each movement
 * has what it was worth when it reached the books (what a purchase cost, what the average cost took
 * out, what a returned sale cost when it was sold), the quantity and the value after it, and the
 * average cost that follows. The value of the movements is set beside the accounts of the locations.
 */
final class StockMovementsReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(
        private readonly StockEntries $entries = new StockEntries(),
        private readonly StockTies $ties = new StockTies(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $view    = $input->choice('view', ['plain', 'valued'], 'plain');
        $period  = $input->period();
        $filters = ['location_id' => $input->id('location_id'), 'item_id' => $input->id('item_id'), 'category' => $input->text('category')];
        $opening = [];

        foreach ($this->entries->totals($input->scope, $period->from, null, $filters) as $row) {
            $opening[(int) $row->item_id . ':' . (int) $row->location_id] = $row;
        }

        $sets = [];

        foreach ($opening as $key => $row) {
            $sets[$key] = ['title' => $row->item_name . ' - ' . $row->location_name, 'opening' => $row, 'moves' => []];
        }

        $locations = [];

        foreach ($this->entries->movements($input->scope, $period->from, $period->to, $filters) as $entry) {
            $key = (int) $entry->item_id . ':' . (int) $entry->location_id;

            $sets[$key] ??= ['title' => $entry->item_name . ' - ' . $entry->location_name, 'opening' => null, 'moves' => []];
            $sets[$key]['moves'][]                = $entry;
            $locations[(int) $entry->location_id] = true;
        }

        uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['title']) <=> mb_strtolower($second['title']));

        $valued = $view === 'valued';
        $blocks = [];
        $count  = 0;
        $value  = '0';
        $shown  = [];

        foreach ($sets as $key => $set) {
            $quantity = $set['opening'] === null ? '0' : (string) $set['opening']->quantity;
            $worth    = $set['opening'] === null ? '0' : (string) $set['opening']->value;
            $rows     = [];

            if ($set['opening'] !== null) {
                $rows[] = new ReportRow($this->openingCells($valued, $period->from, $quantity, $worth));
            }

            foreach ($set['moves'] as $entry) {
                $quantity = bcadd($quantity, (string) $entry->quantity, 3);
                $worth    = bcadd($worth, (string) $entry->value, SaleAmounts::SCALE);
                $rows[]   = new ReportRow($this->moveCells($valued, $entry, $quantity, $worth));
                $count++;
            }

            $value = bcadd($value, $worth, SaleAmounts::SCALE);

            $shown[(int) explode(':', $key)[1]] = true;
            $blocks[]                           = new ReportBlock($set['title'], $rows);
        }

        $columns = $valued
            ? [$this->column('date', ColumnKind::Date), $this->column('kind'), $this->column('reference'), $this->column('location'), $this->column('quantity', ColumnKind::Quantity), $this->column('unit_cost', ColumnKind::Amount), $this->column('quantity_after', ColumnKind::Quantity), $this->column('average_cost', ColumnKind::Amount), $this->column('value', ColumnKind::Amount), $this->column('value_after', ColumnKind::Amount)]
            : [$this->column('date', ColumnKind::Date), $this->column('kind'), $this->column('reference'), $this->column('location'), $this->column('in', ColumnKind::Quantity), $this->column('out', ColumnKind::Quantity), $this->column('quantity_after', ColumnKind::Quantity)];

        $notes = [$this->text('note_movements_reversals'), $this->text('note_movements_count', $count)];
        $ties  = [];

        if ($valued && $filters['item_id'] === 0 && $filters['category'] === '') {
            $ties[] = $this->ties->closing($input->scope, $value, $period->to, $input->today, array_keys($shown));
        } elseif ($valued) {
            $notes[] = $this->text('note_item_filter_not_compared');
        }

        return new ReportTable($this->text('stock_movements_title'), $columns, $blocks, null, $ties, $notes);
    }

    /**
     * @return array<string, string>
     */
    private function openingCells(bool $valued, ?string $from, string $quantity, string $worth): array
    {
        $cells = ['kind' => $this->text('opening_before', $from ?? ''), 'quantity_after' => bcadd($quantity, '0', 3)];

        if ($valued) {
            return [...$cells, 'quantity' => bcadd($quantity, '0', 3), 'value' => bcadd($worth, '0', SaleAmounts::SCALE), 'value_after' => bcadd($worth, '0', SaleAmounts::SCALE), 'average_cost' => $this->average($worth, $quantity)];
        }

        return $cells;
    }

    /**
     * @return array<string, string>
     */
    private function moveCells(bool $valued, object $entry, string $quantity, string $worth): array
    {
        $moved = bcadd((string) $entry->quantity, '0', 3);
        $cells = [
            'date'           => (string) $entry->entry_date,
            'kind'           => StockOperation::label((string) $entry->source_type, $moved, (string) $entry->kind),
            'reference'      => (string) $entry->source_type . ' #' . (int) $entry->source_id,
            'location'       => (string) $entry->location_name,
            'quantity_after' => $quantity,
        ];

        if ($valued) {
            return [
                ...$cells,
                'quantity'     => $moved,
                'unit_cost'    => bccomp($moved, '0', 3) === 0 ? '0.0000' : bcdiv(ltrim((string) $entry->value, '-') === '' ? '0' : ltrim((string) $entry->value, '-'), ltrim($moved, '-'), SaleAmounts::SCALE),
                'average_cost' => $this->average($worth, $quantity),
                'value'        => bcadd((string) $entry->value, '0', SaleAmounts::SCALE),
                'value_after'  => $worth,
            ];
        }

        return [...$cells, 'in' => bccomp($moved, '0', 3) > 0 ? $moved : '', 'out' => bccomp($moved, '0', 3) < 0 ? bcsub('0', $moved, 3) : ''];
    }

    private function average(string $worth, string $quantity): string
    {
        return bccomp($quantity, '0', 3) === 0 ? '0.0000' : bcdiv($worth, $quantity, SaleAmounts::SCALE);
    }
}
