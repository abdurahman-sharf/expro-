<?php

namespace App\Libraries\Accounting;

final readonly class YearEndBranchPlan
{
    /**
     * @param list<YearEndLine> $lines   The accounts of revenue and expense that hold something in the year, in the order of their codes.
     * @param numeric-string    $revenue What the revenue accounts stand at together, as a credit balance.
     * @param numeric-string    $expense What the expense accounts stand at together, as a debit balance.
     * @param numeric-string    $result  Revenue less expense: a profit is positive and a loss is negative.
     */
    public function __construct(
        public int $branchId,
        public string $code,
        public string $name,
        public bool $active,
        public YearEndBranchStatus $status,
        public array $lines,
        public string $revenue,
        public string $expense,
        public string $result,
        public ?object $closing,
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === YearEndBranchStatus::Ready;
    }

    public function isProfit(): bool
    {
        return bccomp($this->result, '0', 4) > 0;
    }

    public function isLoss(): bool
    {
        return bccomp($this->result, '0', 4) < 0;
    }
}
