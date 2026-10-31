<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The cash flow of a range by the indirect method: each line takes the change of its accounts in the
 * range by its formula, the net income takes from every revenue and expense account, and the parts
 * add up their lines. What the lines leave out, such as the tax on sales, is not dropped: every
 * account that moved and is not fully taken by a line is set out under one line of other changes, so
 * the total change is the change of the accounts of the treasuries and the banks whichever accounts
 * moved, and the report says what they held when the range opened and when it ended and whether the
 * two agree. That is a difference on purpose from the reference system, whose total differs from the
 * money held as soon as an account with no line moves. The journals that close a year are left out, a
 * journal that was reversed is read with its reversal, and everything is read in one snapshot.
 */
final class CashFlow
{
    use RunsInTransaction;

    private BaseConnection $db;
    private ChartLookup $chart;
    private CashFlowSettings $settings;

    public function __construct(?BaseConnection $db = null, ?CashFlowSettings $settings = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->chart    = new ChartLookup($this->db);
        $this->settings = $settings ?? new CashFlowSettings($this->db);
    }

    /**
     * Without a first day the range starts on the first of January of the year of the last day.
     */
    public function build(ReportScope $scope, ReportPeriod $period): CashFlowReport
    {
        $from = $period->from ?? substr($period->to, 0, 4) . '-01-01';

        return $this->transactional(fn (): CashFlowReport => $this->read($scope, $from, $period->to));
    }

    private function read(ReportScope $scope, string $from, string $to): CashFlowReport
    {
        $cash      = $this->chart->cashAccounts();
        $movements = $scope->isEmpty() ? [] : $this->movements($scope, $from, $to, array_keys($cash));

        $lines     = [];
        $totals    = [];
        $taken     = [];
        $accounts  = $this->chart->accounts();
        $resultSet = array_fill_keys(array_map(static fn (CashFlowSection $section): string => $section->value, CashFlowSection::cases()), []);

        foreach (CashFlowSection::cases() as $section) {
            $totals[$section->value] = '0.0000';
        }

        foreach ($this->settings->lines() as $line) {
            if (! $line->visible) {
                continue;
            }

            $amount = '0.0000';

            foreach (array_keys($this->accountsOf($line)) as $accountId) {
                if (! isset($movements[$accountId])) {
                    continue;
                }

                $value  = $line->formula->of($movements[$accountId]['debit'], $movements[$accountId]['credit']);
                $amount = bcadd($amount, $value, 4);

                $taken[$accountId] = bcadd($taken[$accountId] ?? '0', $value, 4);
            }

            $resultSet[$line->section->value][] = new CashFlowLineResult($line->key, $line->title, $line->custom, $amount);
            $totals[$line->section->value]      = bcadd($totals[$line->section->value], $amount, 4);
        }

        $items = [];
        $other = '0.0000';

        foreach ($movements as $accountId => $moved) {
            $left = bcsub(bcsub($moved['credit'], $moved['debit'], 4), $taken[$accountId] ?? '0', 4);

            if (bccomp($left, '0', 4) !== 0) {
                $items[] = ['accountId' => $accountId, 'code' => (string) $accounts[$accountId]->code, 'name' => (string) $accounts[$accountId]->name, 'amount' => $left];
                $other   = bcadd($other, $left, 4);
            }
        }

        $change = $other;

        foreach ($totals as $total) {
            $change = bcadd($change, $total, 4);
        }

        $cashIds = array_keys($cash);

        return new CashFlowReport(
            $resultSet,
            $totals,
            $items,
            $other,
            $change,
            $scope->isEmpty() || $cashIds === [] ? '0.0000' : $this->held($scope, $cashIds, $from, true),
            $scope->isEmpty() || $cashIds === [] ? '0.0000' : $this->held($scope, $cashIds, $to, false),
            $scope,
            $from,
            $to,
        );
    }

    /**
     * @return array<int, true> The accounts the line takes from.
     */
    private function accountsOf(CashFlowLineConfig $line): array
    {
        if ($line->key === CashFlowDefaults::NET_INCOME) {
            $found = [];

            foreach ($this->chart->accounts() as $account) {
                if (in_array($account->account_type, [AccountType::Revenue->value, AccountType::Expense->value], true)) {
                    $found[$account->account_id] = true;
                }
            }

            return $found;
        }

        $roots = $line->accountIds;

        if ($roots === [] && ! $line->custom) {
            $default = CashFlowDefaults::byKey()[$line->key];
            $roots   = [...$this->chart->idsOfKeys(...$default['systemKeys']), ...$this->chart->idsOfCodes(...$default['codes'])];
        }

        return $this->chart->subtreeOf($roots);
    }

    /**
     * What each account other than a treasury or a bank was debited and credited in the range, on its
     * own lines.
     *
     * @param list<int> $cash
     *
     * @return array<int, array{debit: numeric-string, credit: numeric-string}>
     */
    private function movements(ReportScope $scope, string $from, string $to, array $cash): array
    {
        $builder = $this->journals($scope)
            ->select('l.account_id, SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)
            ->where('j.journal_date >=', $from)
            ->where('j.journal_date <=', $to)
            ->groupBy('l.account_id');

        if ($cash !== []) {
            $builder->whereNotIn('l.account_id', $cash);
        }

        $moved = [];

        foreach ($builder->get()->getResult() as $row) {
            $moved[(int) $row->account_id] = ['debit' => bcadd((string) $row->debit, '0', 4), 'credit' => bcadd((string) $row->credit, '0', 4)];
        }

        $ordered = [];

        foreach (array_keys($this->chart->accounts()) as $accountId) {
            if (isset($moved[$accountId])) {
                $ordered[$accountId] = $moved[$accountId];
            }
        }

        return $ordered;
    }

    /**
     * What the treasuries and the banks hold before the day, or up to and including it.
     *
     * @param list<int> $cash
     *
     * @return numeric-string
     */
    private function held(ReportScope $scope, array $cash, string $day, bool $before): string
    {
        $row = $this->journals($scope)
            ->select('COALESCE(SUM(l.debit - l.credit), 0) AS held', false)
            ->whereIn('l.account_id', $cash)
            ->where($before ? 'j.journal_date <' : 'j.journal_date <=', $day)
            ->get()
            ->getRow();

        return bcadd((string) $row->held, '0', 4);
    }

    private function journals(ReportScope $scope): BaseBuilder
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids);

        return YearEndEntries::leaveOut($builder, $this->db);
    }
}
