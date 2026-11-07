<?php

namespace App\Libraries\MasterData;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The documents that are not final and still name an item: a draft, a quote or a suspended sale, an open
 * or approved sales order, an active or paused schedule, a stock request that is not settled and a
 * stocktake that is still counting. An item they name is not deleted, because the document would lose its
 * line. A completed sale, a posted permit or a cancelled document is final and does not count.
 */
final class ItemOpenDocuments
{
    private const SOURCES = [
        'SELECT l.item_id, COUNT(DISTINCT s.sale_id) AS n FROM {sales} s JOIN {sales_items} l ON l.sale_id = s.sale_id WHERE s.sale_status = %d AND l.item_id IN (%s) GROUP BY l.item_id',
        "SELECT l.item_id, COUNT(DISTINCT o.order_id) AS n FROM {sales_orders} o JOIN {sales_order_lines} l ON l.order_id = o.order_id WHERE o.status IN ('open', 'approved') AND l.item_id IN (%2\$s) GROUP BY l.item_id",
        "SELECT l.item_id, COUNT(DISTINCT r.schedule_id) AS n FROM {recurring_schedules} r JOIN {recurring_schedule_lines} l ON l.schedule_id = r.schedule_id WHERE r.status IN ('active', 'paused') AND l.item_id IN (%2\$s) GROUP BY l.item_id",
        "SELECT l.item_id, COUNT(DISTINCT q.request_id) AS n FROM {stock_requests} q JOIN {stock_request_lines} l ON l.request_id = q.request_id WHERE q.status IN ('requested', 'approved') AND l.item_id IN (%2\$s) GROUP BY l.item_id",
        "SELECT l.item_id, COUNT(DISTINCT t.stocktake_id) AS n FROM {stocktakes} t JOIN {stocktake_lines} l ON l.stocktake_id = t.stocktake_id WHERE t.status = 'counting' AND l.item_id IN (%2\$s) GROUP BY l.item_id",
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * How many open documents name each item that is named in any.
     *
     * @param list<int> $itemIds
     *
     * @return array<int, int>
     */
    public function counts(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds), static fn (int $id): bool => $id > 0)));

        if ($itemIds === []) {
            return [];
        }

        $list   = implode(',', $itemIds);
        $counts = [];

        foreach (self::SOURCES as $source) {
            $sql    = sprintf($source, SUSPENDED, $list);
            $tables = [];

            preg_match_all('/\{([a-z_]+)\}/', $sql, $found);

            foreach (array_unique($found[1]) as $table) {
                $tables['{' . $table . '}'] = $this->db->prefixTable($table);
            }

            foreach ($tables as $prefixed) {
                if (! $this->db->tableExists($prefixed)) {
                    continue 2;
                }
            }

            foreach ($this->db->query(strtr($sql, $tables))->getResult() as $row) {
                $counts[(int) $row->item_id] = ($counts[(int) $row->item_id] ?? 0) + (int) $row->n;
            }
        }

        return $counts;
    }

    /**
     * The first of the items that an open document names, with its name and the number of documents.
     *
     * @param list<int> $itemIds
     *
     * @return array{item_id: int, name: string, count: int}|null
     */
    public function first(array $itemIds): ?array
    {
        $counts = $this->counts($itemIds);

        if ($counts === []) {
            return null;
        }

        $itemId = array_key_first($counts);
        $row    = $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

        return ['item_id' => $itemId, 'name' => $row === null ? (string) $itemId : (string) $row->name, 'count' => $counts[$itemId]];
    }
}
