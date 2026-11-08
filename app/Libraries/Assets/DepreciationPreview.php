<?php

namespace App\Libraries\Assets;

use App\Libraries\Accounting\AccountingException;

final readonly class DepreciationPreview
{
    /**
     * @param list<DepreciationBranchPlan> $branches
     * @param list<AccountingException>    $blockers
     */
    public function __construct(
        public DepreciationPeriod $period,
        public bool $wholeCompany,
        public array $branches,
        public array $blockers,
        public string $fingerprint,
    ) {
    }

    /**
     * @return list<DepreciationBranchPlan>
     */
    public function ready(): array
    {
        return array_values(array_filter($this->branches, static fn (DepreciationBranchPlan $plan): bool => $plan->isReady()));
    }

    public function canApprove(): bool
    {
        return $this->blockers === [] && $this->ready() !== [];
    }
}
