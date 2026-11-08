<?php

namespace App\Libraries\Stock;

use CodeIgniter\Database\BaseConnection;

/**
 * Where the stock of the tracked items does not add up, read straight from the tables so that it holds
 * whether the tracking setting is on or off: the shelf of an item in a location against its serial
 * numbers on hand there or the sum of its batches, and the tracked items that hold stock at all.
 */
final class TrackingTies
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    /**
     * @return list<object> Each with item_id, location_id, name, tracking_type, shelf and tracked.
     */
    public function untied(int $limit): array
    {
        $shelves = $this->db->prefixTable('item_quantities');
        $serials = $this->db->prefixTable('stock_serials');
        $batches = $this->db->prefixTable('stock_batches');
        $stock   = $this->db->prefixTable('stock_batch_quantities');
        $profile = $this->db->prefixTable('item_stock_profiles');
        $items   = $this->db->prefixTable('items');

        return $this->db->query(
            "SELECT k.item_id, k.location_id, i.name, p.tracking_type, COALESCE(q.quantity, 0) AS shelf,
                    CASE p.tracking_type
                        WHEN 'serial' THEN (SELECT COUNT(*) FROM {$serials} s WHERE s.item_id = k.item_id AND s.location_id = k.location_id AND s.state IN ('in_stock', 'returned'))
                        ELSE (SELECT COALESCE(SUM(bq.quantity), 0) FROM {$stock} bq JOIN {$batches} b ON b.batch_id = bq.batch_id WHERE b.item_id = k.item_id AND bq.location_id = k.location_id)
                    END AS tracked
             FROM (
                 SELECT item_id, location_id FROM {$shelves} WHERE item_id IN (SELECT item_id FROM {$profile} WHERE tracking_type <> 'none')
                 UNION SELECT item_id, location_id FROM {$serials} WHERE location_id IS NOT NULL
                 UNION SELECT b.item_id, bq.location_id FROM {$stock} bq JOIN {$batches} b ON b.batch_id = bq.batch_id
             ) k
             JOIN {$profile} p ON p.item_id = k.item_id AND p.tracking_type <> 'none'
             JOIN {$items} i ON i.item_id = k.item_id
             LEFT JOIN {$shelves} q ON q.item_id = k.item_id AND q.location_id = k.location_id
             HAVING ABS(shelf - tracked) > 0.0005
             ORDER BY k.item_id, k.location_id
             LIMIT " . $limit,
        )->getResult();
    }

    /**
     * @return list<object> Each with item_id and name: the items counted by serial number or batch that hold stock.
     */
    public function holding(int $limit): array
    {
        $shelves = $this->db->prefixTable('item_quantities');
        $serials = $this->db->prefixTable('stock_serials');
        $batches = $this->db->prefixTable('stock_batches');
        $stock   = $this->db->prefixTable('stock_batch_quantities');
        $profile = $this->db->prefixTable('item_stock_profiles');
        $items   = $this->db->prefixTable('items');

        return $this->db->query(
            "SELECT p.item_id, i.name FROM {$profile} p JOIN {$items} i ON i.item_id = p.item_id
             WHERE p.tracking_type <> 'none' AND (
                 EXISTS (SELECT 1 FROM {$shelves} q WHERE q.item_id = p.item_id AND q.quantity <> 0)
                 OR EXISTS (SELECT 1 FROM {$serials} s WHERE s.item_id = p.item_id AND s.state IN ('in_stock', 'returned'))
                 OR EXISTS (SELECT 1 FROM {$stock} bq JOIN {$batches} b ON b.batch_id = bq.batch_id WHERE b.item_id = p.item_id AND bq.quantity <> 0)
             )
             ORDER BY p.item_id
             LIMIT " . $limit,
        )->getResult();
    }
}
