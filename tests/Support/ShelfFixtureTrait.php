<?php

namespace Tests\Support;

/**
 * What a test of stock reports needs to see only the units it put on the shelves itself: the
 * items other tests left behind keep their quantities, so the shelves are emptied for the test
 * and put back after it. A class that uses it also uses AccountingFixtureTrait.
 */
trait ShelfFixtureTrait
{
    /** @var list<array<string, mixed>> */
    private array $shelfBackup = [];

    protected function emptyShelves(): void
    {
        $this->shelfBackup = $this->accountingDb()->table('item_quantities')->get()->getResultArray();
        $this->accountingDb()->table('item_quantities')->emptyTable();
    }

    protected function restoreShelves(): void
    {
        $this->accountingDb()->table('item_quantities')->emptyTable();

        if ($this->shelfBackup !== []) {
            $this->accountingDb()->table('item_quantities')->insertBatch($this->shelfBackup);
        }

        $this->shelfBackup = [];
    }

    /**
     * Units that stand on the shelves with no movement behind them, as stock does from before the
     * books began.
     */
    protected function putOnShelf(int $itemId, string $quantity, int $locationId = 1): void
    {
        $this->accountingDb()->table('item_quantities')->replace(['item_id' => $itemId, 'location_id' => $locationId, 'quantity' => $quantity]);
    }
}
