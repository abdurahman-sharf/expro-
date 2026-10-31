<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What came back of an invoice before its goods were delivered. A credit note on such an invoice
 * moves no stock and posts no cost, because nothing had left, so the delivery that comes later takes
 * out only what stayed sold: the units sold less the units the credit notes took back, item by item.
 */
final class UndeliveredReturns
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * The units of each item the completed returns of the sale took back, by item.
     *
     * @return array<int, numeric-string>
     */
    public function unitsOf(int $saleId): array
    {
        $rows = $this->db->query(
            'SELECT i.item_id, SUM(ABS(i.quantity_purchased)) AS units
             FROM ' . $this->db->prefixTable('sales') . ' s
             JOIN ' . $this->db->prefixTable('sales_items') . ' i ON i.sale_id = s.sale_id
             WHERE s.return_of_sale_id = ? AND s.sale_status = ? AND s.sale_type = ?
             GROUP BY i.item_id',
            [$saleId, COMPLETED, SALE_TYPE_RETURN],
        )->getResult();

        $units = [];

        foreach ($rows as $row) {
            $units[(int) $row->item_id] = bcadd((string) $row->units, '0', 3);
        }

        return $units;
    }

    /**
     * What a line of the sale still has to deliver after what came back is taken off it, and what is
     * left of the returned units for the lines that follow.
     *
     * @param numeric-string             $quantity
     * @param array<int, numeric-string> $back
     *
     * @return array{0: numeric-string, 1: array<int, numeric-string>}
     */
    public static function remaining(int $itemId, string $quantity, array $back): array
    {
        $units = SaleAmounts::absolute($quantity);

        if (! isset($back[$itemId])) {
            return [bcadd($units, '0', 3), $back];
        }

        $taken         = bccomp($back[$itemId], $units, 3) < 0 ? $back[$itemId] : $units;
        $back[$itemId] = bcsub($back[$itemId], $taken, 3);

        return [bcsub($units, $taken, 3), $back];
    }

    /**
     * Whether the sale a return takes its goods back from was never delivered.
     */
    public function neverDelivered(?int $saleId): bool
    {
        return $saleId !== null && $this->db->table('sales')->where(['sale_id' => $saleId, 'delivered' => 0])->countAllResults() > 0;
    }
}
