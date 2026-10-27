<?php

namespace App\Libraries\Accounting;

/**
 * An income statement as it was read: the revenue, the expenses and what is left of the one after
 * the other. There is no gross profit, the cost of sales being an expense.
 */
final readonly class IncomeStatementReport
{
    /**
     * @param numeric-string $netIncome
     */
    public function __construct(
        public StatementSection $revenue,
        public StatementSection $expenses,
        public string $netIncome,
        public ReportScope $scope,
        public ?string $from,
        public string $to,
        public int $level,
        public int $deepestLevel,
        public StatementDisplay $display,
    ) {
    }
}
