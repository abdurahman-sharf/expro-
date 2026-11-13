<?php

namespace App\Libraries\Assets;

final readonly class AssetsFilter
{
    public const GROUPS = ['all', 'status', 'in_service_date', 'purchase_date'];

    /**
     * @param list<AssetStatus> $statuses
     */
    public function __construct(
        public ?int $employeeId = null,
        public ?string $purchaseFrom = null,
        public ?string $purchaseTo = null,
        public ?string $depreciationFrom = null,
        public ?string $depreciationTo = null,
        public array $statuses = [],
        public ?int $accountId = null,
        public ?int $categoryId = null,
        public string $group = 'all',
        public ?string $asOf = null,
    ) {
    }

    public function groupIsKnown(): bool
    {
        return in_array($this->group, self::GROUPS, true);
    }
}
