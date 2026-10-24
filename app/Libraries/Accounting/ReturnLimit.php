<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * A return gives back at most what the sale or the purchase it comes from brought, counting what
 * earlier returns of the same document already gave back. An item the document never had is not
 * for this to judge: posting refuses it with a reason of its own. It must be asked inside the
 * transaction that saves the return, after the document it comes from has been locked, so that
 * two returns saved at the same moment cannot each take what only one of them may.
 */
final class ReturnLimit
{
    private const TOLERANCE = '0.0005';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @throws PostingException
     */
    public function assertPurchaseReturnWithin(int $returnId): void
    {
        $return = $this->db->table('receivings')->select('return_of_receiving_id')->where('receiving_id', $returnId)->get()->getRow();

        if ($return === null || $return->return_of_receiving_id === null) {
            return;
        }

        $originId = (int) $return->return_of_receiving_id;
        $bought   = $this->purchaseUnits('r.receiving_id = ?', [$originId]);
        $earlier  = $this->purchaseUnits("r.return_of_receiving_id = ? AND r.receiving_type = 'return' AND r.receiving_id <> ?", [$originId, $returnId]);
        $giving   = $this->purchaseUnits('r.receiving_id = ?', [$returnId]);

        $this->assertWithin($giving, $earlier, $bought, PostingError::PurchaseReturnExceedsOrigin, $originId);
    }

    /**
     * @throws PostingException
     */
    public function assertSaleReturnWithin(int $returnId): void
    {
        $return = $this->db->table('sales')->select('return_of_sale_id')->where('sale_id', $returnId)->get()->getRow();

        if ($return === null || $return->return_of_sale_id === null) {
            return;
        }

        $originId = (int) $return->return_of_sale_id;
        $sold     = $this->saleUnits('s.sale_id = ?', [$originId]);
        $earlier  = $this->saleUnits('s.return_of_sale_id = ? AND s.sale_status = ? AND s.sale_type = ? AND s.sale_id <> ?', [$originId, COMPLETED, SALE_TYPE_RETURN, $returnId]);
        $giving   = $this->saleUnits('s.sale_id = ?', [$returnId]);

        $this->assertWithin($giving, $earlier, $sold, PostingError::SaleReturnExceedsOrigin, $originId);
    }

    /**
     * @param array<int, string> $giving
     * @param array<int, string> $earlier
     * @param array<int, string> $original
     *
     * @throws PostingException
     */
    private function assertWithin(array $giving, array $earlier, array $original, PostingError $error, int $originId): void
    {
        foreach ($giving as $itemId => $units) {
            if (! isset($original[$itemId])) {
                continue;
            }

            $total = bcadd($units, $earlier[$itemId] ?? '0', 3);
            $limit = $original[$itemId];

            if (bccomp($total, bcadd($limit, self::TOLERANCE, 4), 4) > 0) {
                throw new PostingException(
                    $error,
                    'A return cannot give back more than the document it comes from brought.',
                    ['item_id' => $itemId, 'origin_id' => $originId, 'max' => bcsub($limit, $earlier[$itemId] ?? '0', 3)],
                );
            }
        }
    }

    /**
     * @param list<int|string> $params
     *
     * @return array<int, string>
     */
    private function purchaseUnits(string $where, array $params): array
    {
        $rows = $this->db->query(
            'SELECT l.item_id, SUM(ABS(l.quantity_purchased * IF(l.receiving_quantity = 0, 1, l.receiving_quantity))) AS units
             FROM ' . $this->db->prefixTable('receivings') . ' r
             JOIN ' . $this->db->prefixTable('receivings_items') . ' l ON l.receiving_id = r.receiving_id
             WHERE ' . $where . '
             GROUP BY l.item_id',
            $params,
        )->getResult();

        return $this->byItem($rows);
    }

    /**
     * @param list<int|string> $params
     *
     * @return array<int, string>
     */
    private function saleUnits(string $where, array $params): array
    {
        $rows = $this->db->query(
            'SELECT i.item_id, SUM(ABS(i.quantity_purchased)) AS units
             FROM ' . $this->db->prefixTable('sales') . ' s
             JOIN ' . $this->db->prefixTable('sales_items') . ' i ON i.sale_id = s.sale_id
             WHERE ' . $where . '
             GROUP BY i.item_id',
            $params,
        )->getResult();

        return $this->byItem($rows);
    }

    /**
     * @param list<object> $rows
     *
     * @return array<int, string>
     */
    private function byItem(array $rows): array
    {
        $units = [];

        foreach ($rows as $row) {
            $units[(int) $row->item_id] = bcadd((string) $row->units, '0', 3);
        }

        return $units;
    }
}
