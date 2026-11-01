<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\BranchException;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Posts a change to the stock of an item that no purchase or sale accounts for: a count that
 * found more or less than the books held, a quantity entered or edited on the item, one imported
 * from a file, or the stock an item took with it when it was deleted. The change is worth what a
 * unit is worth where it happens, and its other side is the account of the stock count the routing
 * names. Call it after the shelves have changed, with the id of the row of the inventory log that
 * says so: one adjustment is posted once for each such row. The journal belongs to the branch of
 * the location, and an employee who does not work in that branch cannot post it.
 */
final class StockAdjustment
{
    use RunsInTransaction;

    public const SOURCE = 'stock_adjustment';

    private BaseConnection $db;
    private PostingService $posting;
    private StockLedger $ledger;
    private RoutingResolver $resolver;
    private AccountingSettings $settings;
    private BranchGuard $branches;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->posting  = new PostingService($this->db);
        $this->ledger   = new StockLedger($this->db);
        $this->resolver = new RoutingResolver($this->db);
        $this->settings = new AccountingSettings($this->db);
        $this->branches = new BranchGuard($this->db);
    }

    /**
     * @param numeric-string $quantityChange Positive when the shelves gained units, negative when they lost them.
     *
     * @throws AccountingException|BranchException|PostingException
     */
    public function post(int $itemId, int $locationId, string $quantityChange, ?int $employeeId, int $inventoryId): void
    {
        $date = date('Y-m-d');

        if (! $this->settings->isEnabled() || bccomp($quantityChange, '0', 3) === 0 || $inventoryId < 1) {
            return;
        }

        $start = $this->settings->startDate();

        if ($start !== null && $date < $start) {
            return;
        }

        $item = $this->db->table('items')->select('stock_type, cost_price')->where('item_id', $itemId)->get()->getRow();

        if ($item === null || (int) $item->stock_type !== HAS_STOCK) {
            return;
        }

        $location = $this->db->table('stock_locations')->select('branch_id')->where('location_id', $locationId)->get()->getRow();

        if ($location === null) {
            return;
        }

        $this->branches->requireBranch((int) $location->branch_id, $employeeId);

        $this->transactional(function () use ($itemId, $locationId, $quantityChange, $employeeId, $inventoryId, $date, $item, $location): void {
            $gained    = bccomp($quantityChange, '0', 3) > 0;
            $movement  = $this->valued($itemId, $locationId, $quantityChange, (string) $item->cost_price, $quantityChange);
            $value     = SaleAmounts::absolute($movement->value);
            $journalId = null;

            if (bccomp($value, '0', StockLedger::VALUE_SCALE) > 0) {
                $inventory = $this->required(RoutingKey::StockLocations, $locationId);
                $count     = $this->required($gained ? RoutingKey::StockCountIn : RoutingKey::StockCountOut);

                $journalId = $this->posting->post(new JournalDraft(
                    date: $date,
                    lines: $gained
                        ? [new JournalLineDraft($inventory, debit: $value), new JournalLineDraft($count, credit: $value)]
                        : [new JournalLineDraft($count, debit: $value), new JournalLineDraft($inventory, credit: $value)],
                    sourceType: self::SOURCE,
                    sourceId: $inventoryId,
                    revision: 1,
                    origin: JournalOrigin::System,
                    employeeId: $employeeId,
                    description: ($gained ? 'Stock added #' : 'Stock removed #') . $inventoryId,
                    branchId: (int) $location->branch_id,
                    entryType: JournalEntryType::General,
                ))->journalId;
            }

            $this->ledger->record(self::SOURCE, $inventoryId, JournalEntryType::General, 1, $date, (int) $location->branch_id, [$movement], $journalId);
        });
    }

    /**
     * The stock movement that a change of quantity makes, valued at what a unit is worth where it
     * happens: what the movements of the location say a unit is worth, or the cost the item carries
     * when they do not account for the shelves.
     *
     * @param numeric-string $quantityChange   Positive when the shelves gain units, negative when they lose them.
     * @param numeric-string $pendingQuantity What the shelves changed by that is not yet a movement.
     */
    public function valued(int $itemId, int $locationId, string $quantityChange, string $itemCost, string $pendingQuantity = '0'): StockMovement
    {
        $cost  = $this->ledger->unitCost($itemId, $locationId, $itemCost, $pendingQuantity);
        $value = SaleAmounts::round(bcmul(SaleAmounts::absolute($quantityChange), $cost, 8), StockLedger::VALUE_SCALE);

        return new StockMovement($itemId, $locationId, bcadd($quantityChange, '0', 3), bccomp($quantityChange, '0', 3) > 0 ? $value : bcsub('0', $value, StockLedger::VALUE_SCALE));
    }

    /**
     * @throws PostingException
     */
    private function required(RoutingKey $key, ?int $entityId = null): int
    {
        $account = $this->resolver->accountFor($key, $entityId);

        if ($account === null) {
            throw new PostingException(PostingError::RoutingRowCancelled, "The routing of {$key->value} is cancelled, but a change of stock cannot be posted without it.", ['routing_key' => $key->value]);
        }

        return $account;
    }
}
