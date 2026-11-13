<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingException;

final readonly class DepreciationBranchPlan
{
    /**
     * @param list<DepreciationLine> $lines
     * @param numeric-string         $total
     */
    public function __construct(
        public int $branchId,
        public string $code,
        public string $name,
        public bool $active,
        public DepreciationBranchStatus $status,
        public array $lines,
        public string $total,
        public ?AccountingException $blocker = null,
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === DepreciationBranchStatus::Ready;
    }
}
