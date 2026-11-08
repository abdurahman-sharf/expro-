<?php

namespace App\Libraries\Assets;

final readonly class DepreciationLine
{
    /**
     * @param numeric-string $amount
     * @param numeric-string $accumulatedBefore
     * @param list<string>   $missedMonths       The earlier months the asset was due depreciation for and has none that stands.
     */
    public function __construct(
        public object $asset,
        public string $amount,
        public string $accumulatedBefore,
        public ?string $units,
        public array $missedMonths,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function accumulatedAfter(): string
    {
        return bcadd($this->accumulatedBefore, $this->amount, 4);
    }
}
