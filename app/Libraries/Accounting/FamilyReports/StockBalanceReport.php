<?php

namespace App\Libraries\Accounting\FamilyReports;

/**
 * The quantity of each stock item in each stock location, with its total and a status: available, low
 * (there is a level to warn at and the stock is at it or under), unavailable (nothing or less than
 * nothing) or inactive (the item was deleted). The total is set beside what the movements of the books
 * add up to in the same locations.
 */
final class StockBalanceReport implements FamilyReport
{
    use BuildsReports;

    public const STATUSES = ['all', 'available', 'low', 'out', 'inactive'];

    public function __construct(
        private readonly StockShelves $shelves = new StockShelves(),
        private readonly StockEntries $entries = new StockEntries(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $known      = $this->shelves->locations($input->scope);
        $locationId = $input->id('location_id');
        $names      = $locationId > 0 ? array_intersect_key($known, [$locationId => true]) : $known;
        $ids        = array_keys($names);
        $status     = $input->choice('status', self::STATUSES, 'all');
        $search     = mb_strtolower($input->text('search'));
        $category   = $input->text('category');
        $items      = $this->shelves->items($ids, $category, $status === 'inactive');

        $rows = [];

        foreach ($items as $item) {
            if ($search !== '' && ! str_contains(mb_strtolower($item->name . ' ' . ($item->item_number ?? '')), $search)) {
                continue;
            }

            $total = StockShelves::total($item);

            if ($status !== 'all' && $this->statusOf($item, $total) !== $status) {
                continue;
            }

            $cells = ['code' => $item->item_number ?? '', 'name' => $item->name, 'category' => $item->category, 'status' => $this->text('status_' . $this->statusOf($item, $total))];

            foreach ($item->quantities as $id => $quantity) {
                $cells['l' . $id] = $quantity;
            }

            $rows[] = new ReportRow([...$cells, 'total' => $total]);
        }

        $columns = [$this->column('code'), $this->column('name'), $this->column('category'), $this->column('status')];
        $keys    = ['total'];

        foreach ($names as $id => $name) {
            $columns[] = new ReportColumn('l' . $id, $name, ColumnKind::Quantity);
            $keys[]    = 'l' . $id;
        }

        $columns[] = $this->column('total', ColumnKind::Quantity);
        $total     = $this->totalRow($rows, $keys, 'name', $this->text('grand_total'));
        $filtered  = $status !== 'all' || $search !== '' || $category !== '';
        $ties      = $filtered ? [] : [new TieCheck($this->text('tie_shelves_movements'), $total->cell('total'), bcadd($this->entries->quantityIn($input->scope, $ids), '0', 4))];
        $notes     = [$this->text('note_stock_status')];

        $this->noteIfAny($notes, $filtered, 'note_balance_filtered');

        return new ReportTable($this->text('stock_balance_title'), $columns, [new ReportBlock(null, $rows)], $total, $ties, $notes);
    }

    /**
     * @param object{deleted: bool, reorder_level: string} $item
     */
    private function statusOf(object $item, string $total): string
    {
        return match (true) {
            $item->deleted                                                                           => 'inactive',
            bccomp($total, '0', 3) <= 0                                                              => 'out',
            bccomp($item->reorder_level, '0', 3) > 0 && bccomp($total, $item->reorder_level, 3) <= 0 => 'low',
            default                                                                                  => 'available',
        };
    }
}
