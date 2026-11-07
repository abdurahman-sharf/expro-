<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\LockedRead;
use App\Models\Inventory;
use App\Models\Item_quantity;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the stock documents do to the shelves: the units of an item in a stock location change, and each
 * change is written in the inventory record with the comment of the document. A change that would leave
 * a stock location with less than nothing of an item is refused before anything moves, whether it takes
 * units out for the first time or takes back what a document brought in, because the goods may have been
 * sold or moved since. Items are locked in the order of their ids, as every other change of stock does,
 * so that two documents on the same items wait for each other and never deadlock.
 */
final class StockShelves
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int> $itemIds
     */
    public function lockItems(array $itemIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $itemIds)));
        sort($ids);

        if ($ids !== []) {
            LockedRead::rows($this->db, 'SELECT item_id FROM ' . $this->db->prefixTable('items') . ' WHERE item_id IN (' . implode(',', $ids) . ') ORDER BY item_id FOR UPDATE');
        }
    }

    /**
     * What the stock of each item in each location changes by when the changes given are all made,
     * the changes of one item and location added together.
     *
     * @param list<array{itemId: int, locationId: int, delta: string}> $changes
     *
     * @return list<array{itemId: int, locationId: int, delta: string}>
     */
    public static function net(array $changes): array
    {
        $net = [];

        foreach ($changes as $change) {
            $key = $change['itemId'] . ':' . $change['locationId'];

            $net[$key] ??= ['itemId' => $change['itemId'], 'locationId' => $change['locationId'], 'delta' => '0.000'];
            $net[$key]['delta'] = bcadd($net[$key]['delta'], $change['delta'], 3);
        }

        ksort($net);

        return array_values(array_filter($net, static fn (array $change): bool => bccomp($change['delta'], '0', 3) !== 0));
    }

    /**
     * @param list<array{itemId: int, locationId: int, delta: string}> $deltas
     *
     * @throws AccountingException
     */
    public function assertStays(array $deltas): void
    {
        foreach (self::net($deltas) as $delta) {
            if (bccomp($delta['delta'], '0', 3) >= 0) {
                continue;
            }

            if (bccomp(bcadd($this->onHand($delta['itemId'], $delta['locationId'], true), $delta['delta'], 3), '0', 3) < 0) {
                $item     = $this->db->table('items')->select('name')->where('item_id', $delta['itemId'])->get()->getRow();
                $location = $this->db->table('stock_locations')->select('location_name')->where('location_id', $delta['locationId'])->get()->getRow();

                throw new AccountingException(AccountingError::PurchaseStockWouldGoNegative, 'The change would leave a stock location with less than nothing of an item.', [
                    'item_id'     => $delta['itemId'],
                    'location_id' => $delta['locationId'],
                    'name'        => $item === null ? (string) $delta['itemId'] : (string) $item->name,
                    'scope_name'  => $location === null ? (string) $delta['locationId'] : (string) $location->location_name,
                ]);
            }
        }
    }

    /**
     * @param list<array{itemId: int, locationId: int, delta: string}> $deltas
     */
    public function move(array $deltas, string $comment, int $employeeId): void
    {
        $inventory    = model(Inventory::class);
        $itemQuantity = model(Item_quantity::class);

        foreach (self::net($deltas) as $delta) {
            $units = (float) $delta['delta'];

            $itemQuantity->changeQuantity($delta['itemId'], $delta['locationId'], $units);
            $inventory->insert([
                'trans_date'      => date('Y-m-d H:i:s'),
                'trans_items'     => $delta['itemId'],
                'trans_user'      => $employeeId,
                'trans_location'  => $delta['locationId'],
                'trans_comment'   => $comment,
                'trans_inventory' => $units,
            ], false);
        }
    }

    public function onHand(int $itemId, int $locationId, bool $lock = false): string
    {
        $sql    = 'SELECT quantity FROM ' . $this->db->prefixTable('item_quantities') . ' WHERE item_id = ? AND location_id = ?' . ($lock ? ' FOR UPDATE' : '');
        $result = $lock ? LockedRead::row($this->db, $sql, [$itemId, $locationId]) : $this->db->query($sql, [$itemId, $locationId])->getRow();

        return $result === null ? '0.000' : bcadd((string) $result->quantity, '0', 3);
    }
}
