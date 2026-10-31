<?php

namespace App\Libraries\Accounting;

/**
 * A profit and loss statement by cash as it was read: the revenue and the expenses counted on the day
 * money moved through a treasury or a bank, and what moved through them that is neither, such as
 * capital, a transfer between branches or an opening balance. The profit and the other movements
 * add up to the change of the treasuries and the banks in the range, which is what is checked.
 */
final readonly class CashProfitAndLossReport
{
    public ProfitBasis $basis;

    /**
     * @param list<CashProfitLine> $revenueLines
     * @param list<CashProfitLine> $expenseLines
     * @param list<CashProfitLine> $otherLines
     * @param numeric-string       $totalRevenue
     * @param numeric-string       $totalExpenses
     * @param numeric-string       $profit
     * @param numeric-string       $otherTotal
     * @param numeric-string       $cashChange    What the accounts of the treasuries and the banks gained in the range, read on its own.
     */
    public function __construct(
        public array $revenueLines,
        public array $expenseLines,
        public array $otherLines,
        public string $totalRevenue,
        public string $totalExpenses,
        public string $profit,
        public string $otherTotal,
        public string $cashChange,
        public ReportScope $scope,
        public ?string $from,
        public string $to,
    ) {
        $this->basis = ProfitBasis::Cash;
    }

    public function ties(): bool
    {
        return bccomp(bcadd($this->profit, $this->otherTotal, 4), $this->cashChange, 4) === 0;
    }
}
