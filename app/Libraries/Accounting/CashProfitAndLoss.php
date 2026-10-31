<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The revenue and the expenses of a range counted on the day money moved, read from the journals
 * that touch a treasury or a bank, a journal that was reversed with its reversal. What a journal
 * moved through the treasuries is put on its other lines: what came in against a customer is a
 * receipt, against a supplier a payment, against a revenue account revenue, against an expense
 * account an expense, and against the tax paid on purchases the tax paid on expenses. Whatever else
 * moved, such as capital, a transfer between branches, an opening balance or the tax collected, is
 * listed apart as other movements and never enters the profit, so the profit and the other
 * movements add up to the change of the treasuries and the banks in the range, which the report
 * checks against that change read on its own. It is read in one snapshot.
 */
final class CashProfitAndLoss
{
    use RunsInTransaction;

    private BaseConnection $db;
    private ChartLookup $chart;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db    = $db ?? Database::connect();
        $this->chart = new ChartLookup($this->db);
    }

    public function build(ReportScope $scope, ReportPeriod $period): CashProfitAndLossReport
    {
        return $this->transactional(fn (): CashProfitAndLossReport => $this->read($scope, $period));
    }

    private function read(ReportScope $scope, ReportPeriod $period): CashProfitAndLossReport
    {
        $cash = array_keys($this->chart->cashAccounts());

        $moved  = $scope->isEmpty() || $cash === [] ? [] : $this->movedAgainstCash($scope, $period, $cash);
        $change = $scope->isEmpty() || $cash === [] ? '0' : $this->cashChange($scope, $period, $cash);

        $customers = array_flip(array_keys($this->chart->subtreeOf($this->chart->idsOfKeys('receivables', 'counter_customers'))));
        $suppliers = array_flip(array_keys($this->chart->subtreeOf($this->chart->idsOfKeys('payables'))));
        $taxInput  = array_flip($this->chart->idsOfKeys('tax_input'));
        $accounts  = $this->chart->accounts();

        $fromCustomers = '0.0000';
        $toSuppliers   = '0.0000';
        $taxPaid       = '0.0000';
        $revenue       = [];
        $expenses      = [];
        $others        = [];

        foreach ($moved as $accountId => $net) {
            $account = $accounts[$accountId];

            if (isset($customers[$accountId])) {
                $fromCustomers = bcadd($fromCustomers, $net, 4);
            } elseif (isset($suppliers[$accountId])) {
                $toSuppliers = bcsub($toSuppliers, $net, 4);
            } elseif (isset($taxInput[$accountId])) {
                $taxPaid = bcsub($taxPaid, $net, 4);
            } elseif ($account->account_type === AccountType::Revenue->value) {
                $revenue[] = $this->line($account, $net);
            } elseif ($account->account_type === AccountType::Expense->value) {
                $expenses[] = $this->line($account, bcsub('0', $net, 4));
            } elseif (bccomp($net, '0', 4) !== 0) {
                $others[] = $this->line($account, $net);
            }
        }

        $revenueLines = [new CashProfitLine(CashProfitLine::CUSTOMERS, $fromCustomers), ...$revenue];
        $expenseLines = [new CashProfitLine(CashProfitLine::SUPPLIERS, $toSuppliers), new CashProfitLine(CashProfitLine::TAX_INPUT, $taxPaid), ...$expenses];

        $totalRevenue  = $this->total($revenueLines);
        $totalExpenses = $this->total($expenseLines);

        return new CashProfitAndLossReport(
            $revenueLines,
            $expenseLines,
            $others,
            $totalRevenue,
            $totalExpenses,
            bcsub($totalRevenue, $totalExpenses, 4),
            $this->total($others),
            bcadd($change, '0', 4),
            $scope,
            $period->from,
            $period->to,
        );
    }

    private function line(object $account, string $amount): CashProfitLine
    {
        return new CashProfitLine(CashProfitLine::ACCOUNT, bcadd($amount, '0', 4), $account->account_id, (string) $account->code, (string) $account->name);
    }

    /**
     * @param list<CashProfitLine> $lines
     *
     * @return numeric-string
     */
    private function total(array $lines): string
    {
        $total = '0.0000';

        foreach ($lines as $line) {
            $total = bcadd($total, $line->amount, 4);
        }

        return $total;
    }

    /**
     * What the journals that touch a treasury or a bank credited less what they debited to each of
     * their other accounts: what came in against it.
     *
     * @param list<int> $cash
     *
     * @return array<int, numeric-string>
     */
    private function movedAgainstCash(ReportScope $scope, ReportPeriod $period, array $cash): array
    {
        $builder = $this->journals($scope, $period)
            ->select('l.account_id, SUM(l.credit - l.debit) AS net', false)
            ->whereNotIn('l.account_id', $cash)
            ->whereIn('j.journal_id', static fn (BaseBuilder $touching): BaseBuilder => $touching->select('t.journal_id')->from('accounting_journal_lines t')->whereIn('t.account_id', $cash))
            ->groupBy('l.account_id')
            ->orderBy('l.account_id', 'ASC');

        $moved = [];

        foreach ($builder->get()->getResult() as $row) {
            $moved[(int) $row->account_id] = bcadd((string) $row->net, '0', 4);
        }

        return $this->inOrderOfCode($moved);
    }

    /**
     * @param array<int, numeric-string> $moved
     *
     * @return array<int, numeric-string>
     */
    private function inOrderOfCode(array $moved): array
    {
        $ordered = [];

        foreach (array_keys($this->chart->accounts()) as $accountId) {
            if (isset($moved[$accountId])) {
                $ordered[$accountId] = $moved[$accountId];
            }
        }

        return $ordered;
    }

    /**
     * @param list<int> $cash
     */
    private function cashChange(ReportScope $scope, ReportPeriod $period, array $cash): string
    {
        $row = $this->journals($scope, $period)
            ->select('COALESCE(SUM(l.debit - l.credit), 0) AS gained', false)
            ->whereIn('l.account_id', $cash)
            ->get()
            ->getRow();

        return (string) $row->gained;
    }

    private function journals(ReportScope $scope, ReportPeriod $period): BaseBuilder
    {
        $builder = $this->db->table('accounting_journal_lines l')
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->where('j.company_id', CompanyContext::id())
            ->whereIn('j.status', [JournalStatus::Posted->value, JournalStatus::Reversed->value])
            ->whereIn('j.branch_id', $scope->ids)
            ->where('j.journal_date <=', $period->to);

        if ($period->from !== null) {
            $builder->where('j.journal_date >=', $period->from);
        }

        return YearEndEntries::leaveOut($builder, $this->db);
    }
}
