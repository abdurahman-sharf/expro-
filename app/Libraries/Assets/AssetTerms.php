<?php

namespace App\Libraries\Assets;

final readonly class AssetTerms
{
    public function __construct(
        public AssetMethod $method,
        public string $cost,
        public string $salvage,
        public string $inServiceDate,
        public int $period = 1,
        public AssetInterval $interval = AssetInterval::Month,
        public ?string $amount = null,
        public ?string $percentage = null,
        public ?string $firstDate = null,
        public ?string $endDate = null,
        public ?string $costPerUnit = null,
        public string $openingAccumulated = '0',
        public ?string $openingThrough = null,
    ) {
    }

    public function base(): string
    {
        $base = bcsub($this->cost, $this->salvage, 4);

        return bccomp($base, '0', 4) < 0 ? '0.0000' : $base;
    }

    public function isOpening(): bool
    {
        return $this->openingThrough !== null;
    }
}
