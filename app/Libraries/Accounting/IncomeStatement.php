<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

/**
 * What the revenue and the expenses of a period add up to, read from the journals that are in the
 * books, a journal that was reversed with its reversal. A debit balance inside the revenue, such
 * as the returns of sales, reads as a minus, and the cost of sales is one of the expenses. The
 * journals that close a year are left out, so a year reads the same before and after it is closed.
 * Without a first day the period starts with the books.
 */
final class IncomeStatement
{
    public const DEFAULT_LEVEL = 2;

    private TrialBalance $trialBalance;

    public function __construct(?BaseConnection $db = null)
    {
        $this->trialBalance = new TrialBalance($db);
    }

    public function build(ReportScope $scope, ReportPeriod $period, ?int $level = null, StatementDisplay $display = StatementDisplay::All, bool $expandEntities = false): IncomeStatementReport
    {
        $figures = StatementFigures::read($this->trialBalance, $scope, $period, leaveOutYearEnd: true);
        $level   = $figures->levelFor($level, self::DEFAULT_LEVEL);
        $revenue = $figures->section(AccountType::Revenue, $level, $display, $expandEntities);
        $expense = $figures->section(AccountType::Expense, $level, $display, $expandEntities);

        return new IncomeStatementReport(
            $revenue,
            $expense,
            bcsub($revenue->total, $expense->total, 4),
            $scope,
            $period->from,
            $period->to,
            $level,
            $figures->deepestLevel(),
            $display,
            $expandEntities,
        );
    }
}
