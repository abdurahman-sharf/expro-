<?php

namespace App\Libraries\Accounting;

/**
 * One item in one stock location as the books value it. The quantity and the value are what the
 * posted movements add up to. The shelves are known only for the stock as it stands now and only
 * for a location whose movements are all in the scope, and the units they hold that no movement
 * accounts for are never given a value here.
 */
final readonly class InventoryValuationLine
{
    public function __construct(
        public StockPosition $position,
        public ?string $itemNumber,
        public bool $shelvesKnown,
    ) {
    }

    public function averageCost(): ?string
    {
        return $this->position->averageCost();
    }

    public function onHand(): ?string
    {
        return $this->shelvesKnown ? $this->position->onHand : null;
    }

    public function unposted(): ?string
    {
        return $this->shelvesKnown ? $this->position->unposted() : null;
    }

    public function hasUnpostedUnits(): bool
    {
        return $this->shelvesKnown && ! $this->position->covered();
    }

    /**
     * What the shelves would be worth at the cost the item carries, the basis the legacy
     * inventory report uses, or null when the shelves are not known.
     */
    public function valueAtItemCost(): ?string
    {
        return $this->shelvesKnown ? $this->position->valueAtItemCost() : null;
    }

    public function isEmpty(): bool
    {
        return bccomp($this->position->quantity, '0', 3) === 0
            && bccomp($this->position->value, '0', 4) === 0
            && ($this->onHand() === null || bccomp((string) $this->onHand(), '0', 3) === 0);
    }
}
