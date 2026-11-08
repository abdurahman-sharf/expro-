<?php

namespace App\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\LockedRead;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The three options for a sale that takes stock below nothing. The first lets it happen, which is what
 * the system has always done and the default. The second refuses a sale of more than the location holds.
 * The third refuses a sale of more than is available: what the location holds less what invoices that
 * were issued before their goods left have already promised. The shelf is read under a lock, in the
 * transaction of the sale, so that two sales of the last units never both pass. How the reference system
 * words and applies its three options was read from the settings only (the blocking meaning of the first
 * comes from its help text), so which sales each option covers is an assumption of the design: a sale
 * that completes and delivers, and the delivery of an invoice. A return to a supplier, a permit and a
 * stocktake keep the rules they have.
 */
final class NegativeStockGuard
{
    private BaseConnection $db;
    private StockOptions $options;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->options = new StockOptions($this->db);
    }

    /**
     * @throws AccountingException
     */
    public function assertSale(int $itemId, int $locationId, string $quantity, int $exceptSaleId = 0): void
    {
        $option = $this->options->negativeStock();

        if ($option === NegativeStockOption::Allow || bccomp($quantity, '0', 3) <= 0) {
            return;
        }

        $shelf   = LockedRead::row($this->db, 'SELECT quantity FROM ' . $this->db->prefixTable('item_quantities') . ' WHERE item_id = ? AND location_id = ? FOR UPDATE', [$itemId, $locationId]);
        $onHand  = $shelf === null ? '0' : (string) $shelf->quantity;
        $reached = bcsub($option === NegativeStockOption::RefuseAvailable ? bcsub($onHand, $this->promised($itemId, $locationId, $exceptSaleId), 3) : $onHand, $quantity, 3);

        if (bccomp($reached, '0', 3) < 0) {
            $item = $this->db->table('items')->select('name')->where('item_id', $itemId)->get()->getRow();

            throw new AccountingException(AccountingError::NegativeStockRefused, 'The sale would take the stock below nothing.', ['name' => $item === null ? (string) $itemId : (string) $item->name]);
        }
    }

    /**
     * What issued invoices whose goods have not left promise of an item in a location, leaving out the
     * invoice that is being delivered.
     */
    public function promised(int $itemId, int $locationId, int $exceptSaleId = 0): string
    {
        $row = $this->db->query(
            'SELECT COALESCE(SUM(l.quantity_purchased), 0) AS promised FROM ' . $this->db->prefixTable('sales_items') . ' l
             JOIN ' . $this->db->prefixTable('sales') . ' s ON s.sale_id = l.sale_id
             WHERE l.item_id = ? AND l.item_location = ? AND s.sale_status = ? AND s.sale_type = ? AND s.delivered = 0 AND l.quantity_purchased > 0 AND s.sale_id <> ?',
            [$itemId, $locationId, COMPLETED, SALE_TYPE_INVOICE, $exceptSaleId],
        )->getRow();

        return bcadd((string) $row->promised, '0', 3);
    }
}
