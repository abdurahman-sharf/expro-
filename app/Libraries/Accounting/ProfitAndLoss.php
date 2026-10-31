<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

/**
 * The revenue and the expenses of a range by accrual, a column for each calendar month, quarter or
 * year of it, read from the journals that are in the books, a journal that was reversed with its
 * reversal. A column is the income statement of its days, so the columns add up to the income
 * statement of the whole range, and the journals that close a year are left out of both. Without a
 * first day the range starts on the first of January of the year of the last day. Everything is
 * read in one snapshot, and the amounts keep the four decimals they are stored at: they are rounded
 * only when shown, so a total never differs from its columns by a rounding.
 */
final class ProfitAndLoss
{
    use RunsInTransaction;

    public const DEFAULT_LEVEL = 3;
    public const MAX_PERIODS   = 60;

    private BaseConnection $db;
    private TrialBalance $trialBalance;

    public function __construct(?BaseConnection $db = null)
    {
        $this->trialBalance = new TrialBalance($db);
        $this->db           = $db ?? Database::connect();
    }

    /**
     * @throws AccountingException When the range holds more columns than a screen can draw.
     */
    public function build(ReportScope $scope, ReportPeriod $period, ProfitPeriodKind $kind = ProfitPeriodKind::Monthly, ?int $level = null): ProfitAndLossReport
    {
        $from    = $period->from ?? substr($period->to, 0, 4) . '-01-01';
        $periods = $this->periodsOf($kind, $from, $period->to);

        return $this->transactional(fn (): ProfitAndLossReport => $this->read($scope, $kind, $periods, $from, $period->to, $level));
    }

    /**
     * @param list<ProfitPeriod> $periods
     */
    private function read(ReportScope $scope, ProfitPeriodKind $kind, array $periods, string $from, string $to, ?int $level): ProfitAndLossReport
    {
        $shown = [AccountType::Revenue, AccountType::Expense];
        $whole = StatementFigures::read($this->trialBalance, $scope, ReportPeriod::of($from, $to), leaveOutYearEnd: true, shown: $shown);
        $level = $whole->levelFor($level, self::DEFAULT_LEVEL);

        $revenueRows = $whole->section(AccountType::Revenue, $level, StatementDisplay::WithActivity, expandEntities: false);
        $expenseRows = $whole->section(AccountType::Expense, $level, StatementDisplay::WithActivity, expandEntities: false);

        $revenue  = [];
        $expenses = [];
        $profit   = [];
        $amounts  = [];

        foreach ($periods as $index => $column) {
            $figures = StatementFigures::read($this->trialBalance, $scope, ReportPeriod::of($column->from, $column->to), leaveOutYearEnd: true, shown: $shown);

            $revenueColumn = $figures->section(AccountType::Revenue, $level, StatementDisplay::All, expandEntities: false);
            $expenseColumn = $figures->section(AccountType::Expense, $level, StatementDisplay::All, expandEntities: false);

            $revenue[]  = $revenueColumn->total;
            $expenses[] = $expenseColumn->total;
            $profit[]   = bcsub($revenueColumn->total, $expenseColumn->total, 4);

            foreach ([$revenueColumn, $expenseColumn] as $section) {
                foreach ($section->lines as $line) {
                    $amounts[$line->accountId][$index] = $line->amount;
                }
            }
        }

        return new ProfitAndLossReport(
            ProfitBasis::Accrual,
            $kind,
            $periods,
            $revenue,
            $expenses,
            $profit,
            $this->linesOf($revenueRows, $amounts, count($periods)),
            $this->linesOf($expenseRows, $amounts, count($periods)),
            $revenueRows->total,
            $expenseRows->total,
            bcsub($revenueRows->total, $expenseRows->total, 4),
            $scope,
            $from,
            $to,
            $level,
            $whole->deepestLevel(),
        );
    }

    /**
     * @param array<int, array<int, string>> $amounts What each account stands at in each column.
     *
     * @return list<ProfitLine>
     */
    private function linesOf(StatementSection $rows, array $amounts, int $columns): array
    {
        $lines = [];

        foreach ($rows->lines as $line) {
            $cells = [];

            for ($index = 0; $index < $columns; $index++) {
                $cells[] = bcadd($amounts[$line->accountId][$index] ?? '0', '0', 4);
            }

            $lines[] = new ProfitLine($line->accountId, $line->code, $line->name, $line->isGroup, $line->level, $cells, $line->amount);
        }

        return $lines;
    }

    /**
     * @return list<ProfitPeriod>
     *
     * @throws AccountingException
     */
    private function periodsOf(ProfitPeriodKind $kind, string $from, string $to): array
    {
        $periods = [];
        $day     = new DateTimeImmutable($from);
        $last    = new DateTimeImmutable($to);

        while ($day <= $last) {
            [$start, $end] = $kind->around($day);

            if (count($periods) >= self::MAX_PERIODS) {
                throw new AccountingException(AccountingError::ReportTooManyPeriods, 'The range holds more columns than the report can draw.', ['max' => self::MAX_PERIODS]);
            }

            $periods[] = new ProfitPeriod($kind->label($start), max($start->format('Y-m-d'), $from), min($end->format('Y-m-d'), $to));
            $day       = $end->modify('+1 day');
        }

        return $periods;
    }
}
