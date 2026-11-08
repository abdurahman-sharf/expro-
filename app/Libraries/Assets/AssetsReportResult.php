<?php

namespace App\Libraries\Assets;

final readonly class AssetsReportResult
{
    /**
     * @param list<AssetsReportGroup> $groups
     * @param list<AssetsTieRow>      $tie
     * @param numeric-string          $cost
     * @param numeric-string          $accumulated
     * @param numeric-string          $depreciation
     * @param numeric-string          $bookValue
     */
    public function __construct(
        public string $asOf,
        public array $groups,
        public string $cost,
        public string $accumulated,
        public string $depreciation,
        public string $bookValue,
        public array $tie,
    ) {
    }

    public function count(): int
    {
        return array_sum(array_map(static fn (AssetsReportGroup $group): int => count($group->rows), $this->groups));
    }

    public function ties(): bool
    {
        foreach ($this->tie as $row) {
            if (! $row->ties()) {
                return false;
            }
        }

        return true;
    }
}
