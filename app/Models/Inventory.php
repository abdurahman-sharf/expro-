<?php

namespace App\Models;

use CodeIgniter\Database\ResultInterface;
use CodeIgniter\Model;

/**
 * Inventory class
 *
 * @property employee employee
 *
 */
class Inventory extends Model
{
    protected $table = 'inventory';
    protected $primaryKey = 'trans_id';
    protected $useAutoIncrement = true;
    protected $useSoftDeletes = false;
    protected $allowedFields = [
        'trans_items',
        'trans_user',
        'trans_date',
        'trans_comment',
        'trans_inventory',
        'trans_location'
    ];

    /**
     * @param $comment
     * @param $inventory_data
     * @return bool
     */
    public function update($comment = null, $inventory_data = null): bool
    {
        $builder = $this->db->table('inventory');
        $builder->where('trans_comment', $comment);

        return $builder->update($inventory_data);
    }

    /**
     * Retrieves inventory data given an item_id, of the location or the list of locations given.
     * An empty list matches nothing.
     *
     * @param array<int, int|string>|int|false $locations
     */
    public function get_inventory_data_for_item(int $item_id, array|int|false $locations = false): ResultInterface
    {
        $builder = $this->db->table('inventory');
        $builder->where('trans_items', $item_id);

        if (is_array($locations)) {
            $locations === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('trans_location', $locations);
        } elseif ($locations !== false) {
            $builder->where('trans_location', $locations);
        }

        $builder->orderBy('trans_date', 'desc');

        return $builder->get();
    }

    /**
     * @param int $item_id ID number for the item to have quantity reset.
     * @return bool|int|string The row id of the inventory table on insert or false on failure
     */
    public function reset_quantity(int $item_id): bool|int|string
    {
        $inventory_sums = $this->get_inventory_sum($item_id);
        foreach ($inventory_sums as $inventory_sum) {
            if ($inventory_sum['sum'] > 0) {
                $employee = model(Employee::class);

                return $this->insert([
                    'trans_inventory' => -1 * $inventory_sum['sum'],
                    'trans_items'     => $item_id,
                    'trans_location'  => $inventory_sum['location_id'],
                    'trans_comment'   => lang('Items.is_deleted'),
                    'trans_user'      => $employee->get_logged_in_employee_info()->person_id
                ]);
            }
        }

        return true;
    }

    /**
     * Takes every unit an item has on the shelves of the stock locations that are in use off
     * them, logging each location that held some as the item being deleted. The shelves
     * themselves are zeroed by Item_quantity. Returns what was taken, one row for each location
     * and the id of its row in the log, or false when a row could not be written.
     *
     * @return false|list<array{inventory_id: int, location_id: int, change: string}>
     */
    public function resetRows(int $item_id): array|false
    {
        $rows = $this->db->table('item_quantities')
            ->select('item_quantities.location_id, item_quantities.quantity')
            ->join('stock_locations', 'stock_locations.location_id = item_quantities.location_id')
            ->where('item_quantities.item_id', $item_id)
            ->where('stock_locations.deleted', 0)
            ->where('item_quantities.quantity <>', 0)
            ->get()
            ->getResult();

        $reset = [];

        foreach ($rows as $row) {
            $change = bcsub('0', (string) $row->quantity, 3);
            $id     = $this->insert([
                'trans_inventory' => $change,
                'trans_items'     => $item_id,
                'trans_location'  => $row->location_id,
                'trans_comment'   => lang('Items.is_deleted'),
                'trans_user'      => model(Employee::class)->get_logged_in_employee_info()->person_id,
            ]);

            if ($id === false) {
                return false;
            }

            $reset[] = ['inventory_id' => (int) $id, 'location_id' => (int) $row->location_id, 'change' => $change];
        }

        return $reset;
    }

    /**
     * @param int $item_id
     * @return array
     */
    public function get_inventory_sum(int $item_id): array
    {
        $builder = $this->db->table('inventory');
        $builder->select('SUM(trans_inventory) AS sum, MAX(trans_location) AS location_id');
        $builder->where('trans_items', $item_id);
        $builder->groupBy('trans_location');

        return $builder->get()->getResultArray();
    }
}
