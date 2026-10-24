<?php

namespace App\Libraries\Accounting;

/**
 * What the books say one item is worth in one stock location, beside what the shelves hold.
 * The quantity and the value are the sum of the movements that reached the books. The cost is
 * the one the item carries, which prices the next sale, and it moves only when goods are bought.
 */
final readonly class StockPosition
{
    private const TOLERANCE = '0.0005';

    public function __construct(
        public int $itemId,
        public string $itemName,
        public int $locationId,
        public string $quantity,
        public string $value,
        public string $onHand,
        public string $itemCost,
    ) {
    }

    /**
     * Units on the shelves that no posted movement accounts for: stock there before the books
     * began, or moved without being posted.
     */
    public function unposted(): string
    {
        return bcsub($this->onHand, $this->quantity, 3);
    }

    /**
     * Whether the posted movements account for every unit on the shelves, which is when what
     * they say a unit is worth can be trusted for the whole of the stock.
     */
    public function covered(): bool
    {
        return bccomp(self::absolute($this->unposted()), self::TOLERANCE, 4) <= 0;
    }

    /**
     * What a unit is worth by the movements, or null when there is nothing to divide by.
     */
    public function averageCost(): ?string
    {
        return bccomp($this->quantity, '0', 3) > 0 ? bcdiv($this->value, $this->quantity, 8) : null;
    }

    /**
     * What the shelves would be worth at the cost the item carries.
     */
    public function valueAtItemCost(): string
    {
        return bcadd(bcmul($this->onHand, $this->itemCost, 8), '0', 4);
    }

    /**
     * How far valuing the stock at the cost the item carries is from the movements.
     */
    public function differenceFromItemCost(): string
    {
        return bcsub($this->valueAtItemCost(), $this->value, 4);
    }

    private static function absolute(string $value): string
    {
        return ltrim($value, '-') === '' ? '0' : ltrim($value, '-');
    }
}
