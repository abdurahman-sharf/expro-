<?php

namespace App\Libraries\Accounting;

/**
 * A balance sheet as it was read: the assets, the liabilities, and the equity with the result of
 * the years that were not closed beside the accounts of equity, and for the company as a whole the
 * difference left between the branches.
 */
final readonly class BalanceSheetReport
{
    /**
     * @param numeric-string $unclosedResult
     */
    public function __construct(
        public StatementSection $assets,
        public StatementSection $liabilities,
        public StatementSection $equity,
        public string $unclosedResult,
        public ReportScope $scope,
        public string $asOf,
        public int $level,
        public int $deepestLevel,
        public StatementDisplay $display,
        public bool $expandEntities,
        public ?string $interBranchDifference,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function equityTotal(): string
    {
        return bcadd($this->equity->total, $this->unclosedResult, 4);
    }

    /**
     * @return numeric-string
     */
    public function liabilitiesAndEquity(): string
    {
        return bcadd($this->liabilities->total, $this->equityTotal(), 4);
    }

    public function balances(): bool
    {
        return bccomp($this->assets->total, $this->liabilitiesAndEquity(), 4) === 0;
    }
}
