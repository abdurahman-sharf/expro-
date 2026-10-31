<?php

namespace App\Libraries\Accounting\Backfill;

final readonly class BackfillScope
{
    /**
     * @param list<BackfillFamily> $families
     */
    public function __construct(
        public string $from,
        public string $to,
        public ?int $branchId,
        public array $families,
    ) {
    }

    public function has(BackfillFamily $family): bool
    {
        return in_array($family, $this->families, true);
    }
}
