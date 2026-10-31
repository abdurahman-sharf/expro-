<?php

namespace App\Libraries\Accounting;

final readonly class YearEndPreview
{
    /**
     * @param list<YearEndBranchPlan>   $branches
     * @param list<AccountingException> $blockers What stops the closing from being approved, built and not thrown.
     */
    public function __construct(
        public object $year,
        public bool $forCompany,
        public ?string $companyCutoff,
        public int $draftCount,
        public ?object $retainedAccount,
        public array $branches,
        public array $blockers,
        public string $fingerprint,
    ) {
    }

    /**
     * @return list<YearEndBranchPlan>
     */
    public function ready(): array
    {
        return array_values(array_filter($this->branches, static fn (YearEndBranchPlan $plan): bool => $plan->isReady()));
    }

    public function canApprove(): bool
    {
        return $this->blockers === [] && $this->ready() !== [];
    }
}
