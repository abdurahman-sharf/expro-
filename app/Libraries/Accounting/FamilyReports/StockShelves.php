<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ReportScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the shelves hold: every stock item with its quantity in each stock location of the branches of
 * a scope. A shelf is not kept per branch, so the locations are the ones that belong to those branches.
 */
final class StockShelves
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return array<int, string> The name of each location the scope may read, by id.
     */
    public function locations(ReportScope $scope): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $found = [];

        foreach ($this->db->table('stock_locations')->select('location_id, location_name')->where('deleted', 0)->whereIn('branch_id', $scope->ids)->orderBy('location_name', 'ASC')->orderBy('location_id', 'ASC')->get()->getResult() as $location) {
            $found[(int) $location->location_id] = (string) $location->location_name;
        }

        return $found;
    }

    /**
     * @param list<int> $locationIds The locations to read, which are the ones of the scope.
     *
     * @return list<object{item_id: int, name: string, item_number: ?string, category: string, reorder_level: string, deleted: bool, quantities: array<int, string>}>
     */
    public function items(array $locationIds, string $category = '', bool $withDeleted = false): array
    {
        if ($locationIds === []) {
            return [];
        }

        $builder = $this->db->table('items i')
            ->select('i.item_id, i.name, i.item_number, i.category, i.reorder_level, i.deleted')
            ->where('i.stock_type', HAS_STOCK)
            ->orderBy('i.name', 'ASC')
            ->orderBy('i.item_id', 'ASC');

        if (! $withDeleted) {
            $builder->where('i.deleted', 0);
        }

        if ($category !== '') {
            $builder->where('i.category', $category);
        }

        $items = [];

        foreach ($builder->get()->getResult() as $item) {
            $items[(int) $item->item_id] = (object) [
                'item_id'       => (int) $item->item_id,
                'name'          => (string) $item->name,
                'item_number'   => $item->item_number === null || $item->item_number === '' ? null : (string) $item->item_number,
                'category'      => (string) $item->category,
                'reorder_level' => bcadd((string) $item->reorder_level, '0', 3),
                'deleted'       => (int) $item->deleted === 1,
                'quantities'    => array_fill_keys($locationIds, '0.000'),
            ];
        }

        if ($items === []) {
            return [];
        }

        foreach ($this->db->table('item_quantities')->select('item_id, location_id, quantity')->whereIn('location_id', $locationIds)->get()->getResult() as $shelf) {
            if (isset($items[(int) $shelf->item_id])) {
                $items[(int) $shelf->item_id]->quantities[(int) $shelf->location_id] = bcadd((string) $shelf->quantity, '0', 3);
            }
        }

        return array_values($items);
    }

    /**
     * @param object{quantities: array<int, string>} $item
     *
     * @return numeric-string
     */
    public static function total(object $item): string
    {
        $total = '0';

        foreach ($item->quantities as $quantity) {
            $total = bcadd($total, $quantity, 3);
        }

        return bcadd($total, '0', 3);
    }
}
