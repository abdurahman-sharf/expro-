<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the lines of a return take of the discount on the whole purchase it gives goods back from.
 * The purchase kept what each of its items took of that discount; a return takes the same share of
 * it as the units it gives back are of the units the purchase brought in, and the return that gives
 * back the last of an item takes what the returns before it left, so that everything given back of
 * an item takes exactly what the purchase gave it. A purchase without such a discount gives a
 * return none, whatever the return is. It must be asked inside the transaction that saves the return,
 * after the purchase has been locked, like the limit of the return.
 */
final class ReturnDiscountShares
{
    private const TOLERANCE = '0.0005';
    private const SCALE     = ReceivingAmounts::SCALE;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * What each line takes, in the order of the lines. The return itself, when it is already
     * stored, is not counted among the earlier ones.
     *
     * @param list<ReceivingLine> $lines
     *
     * @return list<numeric-string>
     */
    public function forReturn(int $originId, array $lines, int $returnId = 0): array
    {
        $shares = array_fill(0, count($lines), '0.0000');
        $origin = $this->byItem('r.receiving_id = ?', [$originId]);

        if ($lines === [] || ! $this->hasDiscount($origin)) {
            return $shares;
        }

        $earlier = $this->byItem("r.return_of_receiving_id = ? AND r.receiving_type = 'return' AND r.receiving_id <> ?", [$originId, $returnId]);
        $items   = [];

        foreach ($lines as $index => $line) {
            $items[$line->itemId][] = $index;
        }

        foreach ($items as $itemId => $indexes) {
            if (! isset($origin[$itemId])) {
                continue;
            }

            $giving = '0';

            foreach ($indexes as $index) {
                $giving = bcadd($giving, ReceivingAmounts::units($lines[$index]), 3);
            }

            $itemShare = $this->shareOf($origin[$itemId], $earlier[$itemId] ?? ['units' => '0', 'share' => '0'], $giving);
            $nets      = array_map(static fn (int $index): string => ReceivingAmounts::lineNet($lines[$index]), $indexes);

            foreach (InvoiceDiscountShares::split($nets, $itemShare) as $position => $share) {
                $shares[$indexes[$position]] = $share;
            }
        }

        return $shares;
    }

    /**
     * @param array{units: string, share: string} $origin
     * @param array{units: string, share: string} $earlier
     *
     * @return numeric-string
     */
    private function shareOf(array $origin, array $earlier, string $giving): string
    {
        $left = bcsub($origin['share'], $earlier['share'], self::SCALE);

        if (bccomp($left, '0', self::SCALE) <= 0 || bccomp($origin['units'], '0', 3) <= 0) {
            return '0.0000';
        }

        $afterwards = bcadd($earlier['units'], $giving, 3);

        if (bccomp($afterwards, bcsub($origin['units'], self::TOLERANCE, 4), 4) >= 0) {
            return $left;
        }

        $proportional = SaleAmounts::round(bcmul($origin['share'], bcdiv($giving, $origin['units'], 12), 12), InvoiceDiscountShares::SHARE_SCALE);

        return bccomp($proportional, $left, self::SCALE) > 0 ? $left : $proportional;
    }

    /**
     * @param array<int, array{units: string, share: string}> $items
     */
    private function hasDiscount(array $items): bool
    {
        foreach ($items as $item) {
            if (bccomp($item['share'], '0', self::SCALE) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The units and what they took of the discount, by item.
     *
     * @param list<int|string> $params
     *
     * @return array<int, array{units: string, share: string}>
     */
    private function byItem(string $where, array $params): array
    {
        $rows = $this->db->query(
            'SELECT l.item_id,
                    SUM(ABS(l.quantity_purchased * IF(l.receiving_quantity = 0, 1, l.receiving_quantity))) AS units,
                    SUM(COALESCE(l.invoice_discount_share, 0)) AS share
             FROM ' . $this->db->prefixTable('receivings') . ' r
             JOIN ' . $this->db->prefixTable('receivings_items') . ' l ON l.receiving_id = r.receiving_id
             WHERE ' . $where . '
             GROUP BY l.item_id',
            $params,
        )->getResult();

        $items = [];

        foreach ($rows as $row) {
            $items[(int) $row->item_id] = ['units' => bcadd((string) $row->units, '0', 3), 'share' => bcadd((string) $row->share, '0', self::SCALE)];
        }

        return $items;
    }
}
