<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\SaleAmounts;
use DateTimeImmutable;
use Generator;

/**
 * The movements of each item in each location in a period, one after the other, each with its kind and
 * its document, after the quantity the item held before the first day. Seen as valued, each movement
 * has what it was worth when it reached the books (what a purchase cost, what the average cost took
 * out, what a returned sale cost when it was sold), the quantity and the value after it, and the
 * average cost that follows. The value of the movements is set beside the accounts of the locations.
 */
final class StockMovementsReport implements StreamsReport
{
    use BuildsReports;

    public function __construct(
        private readonly StockEntries $entries = new StockEntries(),
        private readonly StockTies $ties = new StockTies(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        return $this->stream($input)->table();
    }

    public function stream(ReportInput $input): ReportStream
    {
        $view    = $input->choice('view', ['plain', 'valued'], 'plain');
        $period  = $input->period((new DateTimeImmutable($input->today))->format('Y-m-01'));
        $filters = ['location_id' => $input->id('location_id'), 'item_id' => $input->id('item_id'), 'category' => $input->text('category')];
        $valued  = $view === 'valued';
        $count   = 0;
        $value   = '0';
        $shown   = [];
        $plan    = null;

        $sets = function () use (&$plan, $input, $period, $filters): array {
            return $plan ??= $this->plan($input, $period, $filters);
        };

        $events = function () use ($sets, $input, $period, $filters, $valued, &$count, &$value, &$shown): Generator {
            $plan  = $sets();
            $moves = $this->entries->stream($input->scope, $period->from, $period->to, $filters, array_values(array_filter(array_column($plan['sets'], 'pair'))));

            foreach ($plan['sets'] as $key => $set) {
                $quantity = $set['opening'] === null ? '0' : (string) $set['opening']->quantity;
                $worth    = $set['opening'] === null ? '0' : (string) $set['opening']->value;

                yield StreamEvent::open($set['title']);

                if ($set['opening'] !== null) {
                    yield StreamEvent::row(new ReportRow($this->openingCells($valued, $period->from, $quantity, $worth)));
                }

                if ($set['pair'] !== null) {
                    foreach ($moves->current()[1] as $entry) {
                        $quantity = bcadd($quantity, (string) $entry->quantity, 3);
                        $worth    = bcadd($worth, (string) $entry->value, SaleAmounts::SCALE);
                        $count++;

                        yield StreamEvent::row(new ReportRow($this->moveCells($valued, $entry, $quantity, $worth)));
                    }

                    $moves->next();
                }

                $value                                       = bcadd($value, $worth, SaleAmounts::SCALE);
                $shown[(int) explode(':', (string) $key)[1]] = true;

                yield StreamEvent::close();
            }
        };

        $summary = function () use ($input, $period, $filters, $valued, &$count, &$value, &$shown): array {
            $notes = [$this->text('note_movements_reversals'), $this->text('note_movements_count', $count)];
            $ties  = [];

            if ($valued && $filters['item_id'] === 0 && $filters['category'] === '') {
                $ties[] = $this->ties->closing($input->scope, $value, $period->to, $input->today, array_keys($shown));
            } elseif ($valued) {
                $notes[] = $this->text('note_item_filter_not_compared');
            }

            if ($input->defaultsToTheMonth()) {
                $notes[] = $this->text('note_default_month', $period->from);
            }

            return ['grand' => null, 'ties' => $ties, 'notes' => $notes];
        };

        $columns = $valued
            ? [$this->column('date', ColumnKind::Date), $this->column('kind'), $this->column('reference'), $this->column('location'), $this->column('quantity', ColumnKind::Quantity), $this->column('unit_cost', ColumnKind::Amount), $this->column('quantity_after', ColumnKind::Quantity), $this->column('average_cost', ColumnKind::Amount), $this->column('value', ColumnKind::Amount), $this->column('value_after', ColumnKind::Amount)]
            : [$this->column('date', ColumnKind::Date), $this->column('kind'), $this->column('reference'), $this->column('location'), $this->column('in', ColumnKind::Quantity), $this->column('out', ColumnKind::Quantity), $this->column('quantity_after', ColumnKind::Quantity)];

        return new ReportStream($this->text('stock_movements_title'), $columns, $events, $summary, static function () use ($sets): int {
            $plan = $sets();

            return count(array_filter(array_column($plan['sets'], 'opening'))) + $plan['moves'];
        });
    }

    /**
     * The items in each location that have a block, in the order of their titles, with what each held before
     * the period and what moved in it. Titles that read the same keep the order in which the books first met them.
     *
     * @param array{location_id: int, item_id: int, category: string} $filters
     *
     * @return array{sets: array<string, array{title: string, opening: ?object, pair: ?object}>, moves: int}
     */
    private function plan(ReportInput $input, ReportPeriod $period, array $filters): array
    {
        $sets = [];

        foreach ($this->entries->totals($input->scope, $period->from, null, $filters) as $row) {
            $sets[(int) $row->item_id . ':' . (int) $row->location_id] = ['title' => $row->item_name . ' - ' . $row->location_name, 'opening' => $row, 'pair' => null];
        }

        $pairs = $this->entries->pairs($input->scope, $period->from, $period->to, $filters);
        $moves = array_sum(array_map(static fn (object $pair): int => $pair->found, $pairs));

        usort($pairs, static fn (object $first, object $second): int => strcmp($first->first_seen, $second->first_seen));

        foreach ($pairs as $pair) {
            $key = (int) $pair->item_id . ':' . (int) $pair->location_id;

            $sets[$key] ??= ['title' => $pair->item_name . ' - ' . $pair->location_name, 'opening' => null, 'pair' => null];
            $sets[$key]['pair'] = $pair;
        }

        uasort($sets, static fn (array $first, array $second): int => mb_strtolower($first['title']) <=> mb_strtolower($second['title']));

        return ['sets' => $sets, 'moves' => $moves];
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
