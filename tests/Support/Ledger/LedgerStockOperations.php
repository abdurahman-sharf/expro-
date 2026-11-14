<?php

namespace Tests\Support\Ledger;

use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\Stocktake;

/**
 * Stock that changes without a sale or a purchase: the permits that add, issue and move units, and the
 * counts that bring the books to the shelf.
 */
trait LedgerStockOperations
{
    /** @var array<int, string> */
    private array $permitBook = [];

    /** @var array<int, string> */
    private array $stocktakeBook = [];

    private function resetStock(): void
    {
        $this->permitBook    = [];
        $this->stocktakeBook = [];
    }

    private function operationStockPermit(LedgerRandom $random): string
    {
        $branchId = $random->pick($this->world->branches);
        $location = $random->pick($this->locationsOfBranch($branchId));
        $kind     = $random->weighted(['add' => 40, 'issue' => 35, 'transfer' => 25]);
        $onHand   = [];

        foreach ($this->db->table('item_quantities')->where('location_id', $location)->where('quantity >', 0)->get()->getResultArray() as $row) {
            $onHand[(int) $row['item_id']] = (string) $row['quantity'];
        }

        $stock = array_keys(array_filter($this->world->items, static fn (array $item): bool => $item['stock']));
        $input = ['type' => $kind, 'date' => date('Y-m-d'), 'location_id' => $location, 'note' => 'ledger'];

        if ($kind === 'add') {
            $itemId = $random->pick($stock);
            $cost   = $random->chance(15) ? '0' : $random->price();

            $input['lines'] = [['item_id' => $itemId, 'quantity' => $random->quantity(), 'unit_cost' => $cost]];
        } else {
            $itemId = $onHand === [] || $random->chance(10) ? $random->pick($stock) : $random->pick(array_keys($onHand));
            $units  = isset($onHand[$itemId]) ? bcmul($onHand[$itemId], (string) ($random->int(10, 100) / 100), 3) : $random->quantity();

            if (bccomp($units, '0.001', 3) < 0) {
                $units = '0.001';
            }

            $input['lines'] = [['item_id' => $itemId, 'quantity' => $units]];

            if ($kind === 'transfer') {
                $targets = array_values(array_diff(array_keys($this->world->locations), [$location]));

                if ($targets === []) {
                    $kind          = 'issue';
                    $input['type'] = 'issue';
                } else {
                    $input['to_location_id'] = $random->pick($targets);
                }
            }
        }

        if ($kind !== 'add' && $this->costBelowNothing((int) $input['lines'][0]['item_id'], $location)) {
            return $this->operationStockPermit($random);
        }

        $this->note(sprintf('stock %s permit loc=%d%s lines=%s', $kind, $location, isset($input['to_location_id']) ? '->' . $input['to_location_id'] : '', json_encode($input['lines'])));

        $permitId = (new StockPermit())->create($input, $this->world->boss);

        $this->permitBook[$permitId] = 'completed';

        return sprintf('stock %s permit %d loc=%d%s lines=%s', $kind, $permitId, $location, isset($input['to_location_id']) ? '->' . $input['to_location_id'] : '', json_encode($input['lines']));
    }

    /**
     * A location that holds units valued at nothing or less has no cost to move or count them at: the cost of goods sold takes the cost of the
     * item in every location together, so a cheap location can run out of value before it runs out of units. The operations that need the cost
     * of the location are left to a location that has one.
     */
    private function costBelowNothing(int $itemId, int $locationId): bool
    {
        $position = $this->db->query(
            'SELECT COALESCE(SUM(quantity), 0) AS quantity, COALESCE(SUM(value), 0) AS value FROM ' . $this->db->prefixTable('accounting_stock_entries') . ' WHERE item_id = ? AND location_id = ?',
            [$itemId, $locationId],
        )->getRow();

        $below = bccomp((string) $position->quantity, '0', 3) > 0 && bccomp((string) $position->value, '0.01', 4) < 0;

        if ($below) {
            $this->observe('F1-stock-operation-left-out-on-nonpositive-location-cost', sprintf('item %d in location %d: %s units valued at %s', $itemId, $locationId, $position->quantity, $position->value));
        }

        return $below;
    }

    private function operationStockPermitCancel(LedgerRandom $random): string
    {
        $candidates = array_keys(array_filter($this->permitBook, static fn (string $status): bool => $status === 'completed'));

        if ($candidates === []) {
            return $this->operationStockPermit($random);
        }

        $permitId = $random->pick($candidates);

        $this->note('cancel stock permit ' . $permitId);

        (new StockPermit())->cancel($permitId, 'ledger', $this->world->boss);

        $this->permitBook[$permitId] = 'cancelled';

        return 'cancel stock permit ' . $permitId;
    }

    private function operationStocktake(LedgerRandom $random): string
    {
        $branchId = $random->pick($this->world->branches);
        $location = $random->pick($this->locationsOfBranch($branchId));
        $takes    = new Stocktake();
        $held     = [];

        foreach ($this->db->table('item_quantities')->where('location_id', $location)->get()->getResultArray() as $row) {
            $held[(int) $row['item_id']] = (string) $row['quantity'];
        }

        if ($held === []) {
            return $this->operationStockPermit($random);
        }

        $items = array_values(array_filter(array_slice(array_keys($held), 0, $random->int(1, 3)), fn (int $itemId): bool => ! $this->costBelowNothing($itemId, $location)));

        if ($items === []) {
            return $this->operationStockPermit($random);
        }

        $this->note(sprintf('stocktake loc=%d items=%s', $location, implode(',', $items)));

        $stocktakeId = $takes->create(['location_id' => $location, 'date' => date('Y-m-d'), 'item_ids' => $items], $this->world->boss);

        $this->persisted();
        $counted     = [];

        foreach ($items as $itemId) {
            $delta = $random->weighted(['same' => 30, 'less' => 35, 'more' => 35]);

            $counted[$itemId] = match ($delta) {
                'same'  => $held[$itemId],
                'less'  => bcmul($held[$itemId], (string) ($random->int(0, 95) / 100), 3),
                default => bcadd($held[$itemId], (string) ($random->int(1, 500) / 100), 3),
            };

            if (bccomp($counted[$itemId], '0', 3) < 0) {
                $counted[$itemId] = '0.000';
            }
        }

        $this->note(sprintf('stocktake %d loc=%d counted=%s of %s', $stocktakeId, $location, json_encode($counted), json_encode(array_intersect_key($held, $counted))));

        $takes->count($stocktakeId, $counted, $this->world->boss);

        if ($random->chance(15)) {
            $takes->cancel($stocktakeId, 'ledger', $this->world->boss);
            $this->stocktakeBook[$stocktakeId] = 'cancelled';

            return sprintf('stocktake %d loc=%d cancelled', $stocktakeId, $location);
        }

        $takes->approve($stocktakeId, $this->world->boss);

        $this->stocktakeBook[$stocktakeId] = 'approved';

        return sprintf('stocktake %d loc=%d counted=%s of %s approved', $stocktakeId, $location, json_encode($counted), json_encode(array_intersect_key($held, $counted)));
    }
}
