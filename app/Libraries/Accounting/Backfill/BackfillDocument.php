<?php

namespace App\Libraries\Accounting\Backfill;

final readonly class BackfillDocument
{
    public function __construct(
        public BackfillFamily $family,
        public int $id,
        public string $date,
        public ?int $branchId,
    ) {
    }
}
