<?php

namespace App\Libraries\Accounting;

/**
 * A line of a saved purchase as posting reads it. The quantity counts packs and the units of a
 * pack multiply it, as the purchase screen does. Amounts are strings so that nothing is rounded
 * before posting does it.
 */
final readonly class ReceivingLine
{
    public function __construct(
        public int $itemId,
        public int $line,
        public string $quantity,
        public string $unitsPerPack,
        public string $unitPrice,
        public string $discount,
        public int $discountType,
        public string $taxPercent,
        public int $locationId,
        public bool $hasStock,
        public string $itemCost,
    ) {
    }

    /**
     * The units of the line, with the sign of its quantity.
     *
     * @return numeric-string
     */
    public function units(): string
    {
        return bccomp($this->unitsPerPack, '0', 3) === 0 ? bcadd($this->quantity, '0', 3) : bcmul($this->quantity, $this->unitsPerPack, 3);
    }
}
