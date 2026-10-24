<?php

namespace App\Libraries\Accounting;

final readonly class OpeningStockResult
{
    public function __construct(
        public OpeningStockPlan $plan,
        public ?int $journalId = null,
        public ?int $revision = null,
    ) {
    }

    public function written(): bool
    {
        return $this->revision !== null;
    }
}
