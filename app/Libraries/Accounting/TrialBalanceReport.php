<?php

namespace App\Libraries\Accounting;

/**
 * A trial balance as it was read: its rows in the order of the chart, the total of the accounts at
 * the top, and for the company as a whole the difference left between the branches. A run limited
 * to some accounts adds up only those, so it is marked and not expected to balance.
 */
final readonly class TrialBalanceReport
{
    /**
     * @param list<TrialBalanceRow>                                                                                                                                                                                                                                    $rows
     * @param array{opening_debit: string, opening_credit: string, debit: string, credit: string, closing_debit: string, closing_credit: string, gross_opening_debit: string, gross_opening_credit: string, gross_closing_debit: string, gross_closing_credit: string} $totals
     */
    public function __construct(
        public array $rows,
        public array $totals,
        public ReportScope $scope,
        public ?string $from,
        public string $to,
        public ?string $interBranchDifference,
        public bool $limitedToAccounts = false,
        public TrialBalanceClosing $closing = TrialBalanceClosing::Before,
    ) {
    }

    public function balances(): bool
    {
        return bccomp($this->totals['debit'], $this->totals['credit'], 4) === 0
            && bccomp($this->totals['closing_debit'], $this->totals['closing_credit'], 4) === 0
            && bccomp($this->totals['opening_debit'], $this->totals['opening_credit'], 4) === 0
            && bccomp($this->totals['gross_opening_debit'], $this->totals['gross_opening_credit'], 4) === 0
            && bccomp($this->totals['gross_closing_debit'], $this->totals['gross_closing_credit'], 4) === 0;
    }
}
