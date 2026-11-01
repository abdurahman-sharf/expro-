<?php

namespace App\Libraries\Accounting\FamilyReports;

/**
 * The sheet a count of the stock is written on: each stock item with the quantity the system holds
 * and two columns left empty to write the count and a note in. It lists the items that hold stock,
 * the ones with nothing too when asked, and its total is set beside the quantity the movements of
 * the books add up to in the same locations.
 */
final class StockCountSheetReport implements FamilyReport
{
    use BuildsReports;

    public function __construct(
        private readonly StockShelves $shelves = new StockShelves(),
        private readonly StockEntries $entries = new StockEntries(),
    ) {
    }

    public function build(ReportInput $input): ReportTable
    {
        $known      = $this->shelves->locations($input->scope);
        $locationId = $input->id('location_id');
        $ids        = $locationId > 0 ? array_values(array_intersect(array_keys($known), [$locationId])) : array_keys($known);
        $category   = $input->text('category');
        $items      = $this->shelves->items($ids, $category);
        $all        = $input->flag('all') && ! $input->flag('hide_zero');
        $group      = $input->choice('group', ['none', 'category'], 'none');
        $order      = $input->choice('order', ['name', 'quantity_asc', 'quantity_desc'], 'name');

        $items = array_values(array_filter($items, static fn (object $item): bool => $all || bccomp(StockShelves::total($item), '0', 3) !== 0));

        if ($order !== 'name') {
            usort($items, static fn (object $first, object $second): int => $order === 'quantity_asc'
                ? bccomp(StockShelves::total($first), StockShelves::total($second), 3)
                : bccomp(StockShelves::total($second), StockShelves::total($first), 3));
        }

        $sets = [];

        foreach ($items as $item) {
            $key = $group === 'category' ? $item->category : '';

            $sets[$key][] = new ReportRow([
                'code'     => $item->item_number ?? '',
                'name'     => $item->name,
                'category' => $item->category,
                'system'   => StockShelves::total($item),
                'count'    => '',
                'notes'    => '',
            ]);
        }

        ksort($sets);

        $blocks = [];
        $rows   = [];

        foreach ($sets as $title => $set) {
            $blocks[] = new ReportBlock($group === 'category' ? ($title === '' ? $this->text('no_category') : (string) $title) : null, $set);
            $rows     = [...$rows, ...$set];
        }

        $total = $this->totalRow($rows, ['system'], 'name', $this->text('grand_total'));
        $ties  = [];
        $notes = [$this->text('note_count_sheet')];

        if ($category === '') {
            $ties[] = new TieCheck($this->text('tie_shelves_movements'), $total->cell('system'), bcadd($this->entries->quantityIn($input->scope, $ids), '0', 4));
        } else {
            $notes[] = $this->text('note_category_not_compared');
        }

        $columns = [
            $this->column('code'), $this->column('name'), $this->column('category'),
            $this->column('system', ColumnKind::Quantity), $this->column('count', ColumnKind::Plain), $this->column('notes'),
        ];

        return new ReportTable($this->text('stock_count_title'), $columns, $blocks, $total, $ties, $notes);
    }
}
