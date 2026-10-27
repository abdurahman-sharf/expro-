<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Where the assets, the liabilities and the equity stand on a day, from the journals that are in
 * the books, a journal that was reversed with its reversal. It is a position, so it counts
 * everything from the start of the books to the day and has no first day. The journals that close
 * a year are in it. The result of the revenue and expense accounts that no year-end journal has
 * closed is added to the equity as a line of its own, so that the assets equal the liabilities and
 * the equity before any year is closed and after: closing a year moves that amount to retained
 * earnings and the total does not change.
 */
final class BalanceSheet
{
    public const DEFAULT_LEVEL = 3;

    private BaseConnection $db;
    private TrialBalance $trialBalance;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db           = $db ?? Database::connect();
        $this->trialBalance = new TrialBalance($this->db);
    }

    /**
     * @throws AccountingException
     */
    public function build(ReportScope $scope, string $asOf, ?int $level = null, StatementDisplay $display = StatementDisplay::All, bool $expandEntities = false): BalanceSheetReport
    {
        $period  = ReportPeriod::of(null, $asOf);
        $figures = StatementFigures::read($this->trialBalance, $scope, $period, leaveOutYearEnd: false, shown: [AccountType::Asset, AccountType::Liability, AccountType::Equity]);
        $level   = $figures->levelFor($level, self::DEFAULT_LEVEL);

        return new BalanceSheetReport(
            $figures->section(AccountType::Asset, $level, $display, $expandEntities),
            $figures->section(AccountType::Liability, $level, $display, $expandEntities),
            $figures->section(AccountType::Equity, $level, $display, $expandEntities),
            bcsub($figures->total(AccountType::Revenue), $figures->total(AccountType::Expense), 4),
            $scope,
            $period->to,
            $level,
            $figures->deepestLevel(),
            $display,
            $expandEntities,
            $scope->isCompany() ? (new InterBranchPosition($this->db))->difference($period->to) : null,
        );
    }
}
