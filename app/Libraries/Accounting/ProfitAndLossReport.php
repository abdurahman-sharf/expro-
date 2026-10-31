<?php

namespace App\Libraries\Accounting;

/**
 * A profit and loss statement by accrual as it was read: a column for each month, quarter or year of
 * the range, the revenue and the expenses of each, the accounts of both as a tree down to a level,
 * and the whole range. Revenue less expenses is the profit of each column. The totals are those of
 * the accounts at the top of the chart whatever the level shown, so the columns add up to the whole
 * range and the whole range is the income statement of the same days.
 */
final readonly class ProfitAndLossReport
{
    /**
     * @param list<ProfitPeriod>   $periods
     * @param list<numeric-string> $revenue       The revenue of each column.
     * @param list<numeric-string> $expenses      The expenses of each column.
     * @param list<numeric-string> $profit        Revenue less expenses of each column.
     * @param list<ProfitLine>     $revenueLines
     * @param list<ProfitLine>     $expenseLines
     * @param numeric-string       $totalRevenue
     * @param numeric-string       $totalExpenses
     * @param numeric-string       $totalProfit
     */
    public function __construct(
        public ProfitBasis $basis,
        public ProfitPeriodKind $kind,
        public array $periods,
        public array $revenue,
        public array $expenses,
        public array $profit,
        public array $revenueLines,
        public array $expenseLines,
        public string $totalRevenue,
        public string $totalExpenses,
        public string $totalProfit,
        public ReportScope $scope,
        public string $from,
        public string $to,
        public int $level,
        public int $deepestLevel,
    ) {
    }
}
