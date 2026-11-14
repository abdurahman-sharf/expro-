<?php

namespace App\Models;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\StockTracking;
use CodeIgniter\Database\RawSql;
use CodeIgniter\Model;
use stdClass;

/**
 * Item_quantity class
 */
class Item_quantity extends Model
{
    protected $table            = 'item_quantities';
    protected $primaryKey       = 'item_id';
    protected $useAutoIncrement = false;
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'quantity',
    ];
    protected $item_id;
    protected $location_id;
    protected $quantity;

    public function exists(int $item_id, int $location_id): bool
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);

        return $builder->get()->getNumRows() === 1;    // TODO: ===
    }

    public function save_value(array $location_detail, int $item_id, int $location_id): bool
    {
        if (isset($location_detail['quantity']) && bccomp((string) $location_detail['quantity'], (string) $this->get_item_quantity($item_id, $location_id)->quantity, 3) !== 0) {
            StockTracking::assertShelfMayChange($item_id, $this->db);
        }

        if (! $this->exists($item_id, $location_id)) {
            $builder = $this->db->table('item_quantities');

            return $builder->insert($location_detail);
        }

        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);

        return $builder->update($location_detail);
    }

    /**
     * Gives an item an empty shelf in every stock location it has none in, whichever branch the
     * location belongs to, because the lists of items read the shelf of the location they show.
     */
    public function createMissingRows(int $itemId): bool
    {
        $locations = $this->db->table('stock_locations')->select('location_id')->where('deleted', 0)->get()->getResultArray();

        foreach (array_column($locations, 'location_id') as $locationId) {
            if (! $this->exists($itemId, (int) $locationId) && ! $this->db->table('item_quantities')->insert(['item_id' => $itemId, 'location_id' => (int) $locationId, 'quantity' => 0])) {
                return false;
            }
        }

        return true;
    }

    public function get_item_quantity(int $item_id, int $location_id): array|Item_quantity|stdClass|null
    {
        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);
        $builder->where('location_id', $location_id);
        $result = $builder->get()->getRow();

        if (empty($result)) {
            // Get empty base parent object, as $item_id is NOT an item
            $result = model(Item_quantity::class);

            // Get all the fields from items table (TODO: to be reviewed)
            foreach ($this->db->getFieldNames('item_quantities') as $field) {
                $result->{$field} = '';
            }

            $result->quantity = 0;
        }

        return $result;
    }

    /**
     * Atomically changes an item's quantity at a location by a signed delta.
     * Positive delta adds; negative delta subtracts. Creates the
     * item_quantities row if it doesn't yet exist. Negative resulting
     * quantity is allowed (no floor guard).
     */
    public function changeQuantity(int $itemId, int $locationId, float $quantityChange): bool
    {
        StockTracking::assertShelfMayChange($itemId, $this->db);

        $builder = $this->db->table('item_quantities');
        $builder->set([
            'item_id'     => $itemId,
            'location_id' => $locationId,
            'quantity'    => $quantityChange,
        ]);
        $builder->updateFields(['quantity' => new RawSql('quantity + ' . $this->db->escape($quantityChange))]);

        return $builder->upsert() !== false;
    }

    /**
     * Set to 0 all quantity in the given item
     */
    public function reset_quantity(int $item_id): bool
    {
        $this->assertHoldingMayBeReset([$item_id]);

        $builder = $this->db->table('item_quantities');
        $builder->where('item_id', $item_id);

        return $builder->update(['quantity' => 0]);
    }

    /**
     * Set to 0 all quantity in the given list of items
     */
    public function reset_quantity_list(array $item_ids): bool
    {
        $this->assertHoldingMayBeReset($item_ids);

        $builder = $this->db->table('item_quantities');
        $builder->whereIn('item_id', $item_ids);

        return $builder->update(['quantity' => 0]);
    }

    /**
     * Only an item that holds something is changed by a reset, so a tracked item with an empty shelf may
     * be deleted and one that holds stock is refused by name.
     *
     * @param list<int|string> $itemIds
     *
     * @throws AccountingException
     */
    private function assertHoldingMayBeReset(array $itemIds): void
    {
        if ($itemIds === []) {
            return;
        }

        $holding = $this->db->table('item_quantities')->select('item_id')->whereIn('item_id', array_map('intval', $itemIds))->where('quantity <>', 0)->distinct()->get()->getResultArray();

        foreach (array_column($holding, 'item_id') as $itemId) {
            StockTracking::assertShelfMayChange((int) $itemId, $this->db);
        }
    }
}
