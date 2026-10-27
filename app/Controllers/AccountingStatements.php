<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\BalanceSheet;
use App\Libraries\Accounting\IncomeStatement;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\StatementDisplay;

/**
 * The income statement and the balance sheet over the journals that are in the books. Each is for
 * one branch, for several, or for the company, and what an employee asks for is cut down to the
 * branches they work in; someone limited to some branches is told that the total is of those.
 */
class AccountingStatements extends AccountingController
{
    private IncomeStatement $incomeStatement;
    private BalanceSheet $balanceSheet;

    public function __construct()
    {
        parent::__construct('accounting_reports');

        $this->incomeStatement = new IncomeStatement();
        $this->balanceSheet    = new BalanceSheet();
    }

    public function getIncomeStatement(): string
    {
        $scope = $this->scope();
        $error = null;

        try {
            $period = ReportPeriod::of($this->query('from'), $this->asOf());
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        $report = $this->incomeStatement->build($scope, $period, $this->level(), StatementDisplay::fromRequest($this->query('display')), $this->flag('entities'));

        return view('accounting_statements/income_statement', [
            'report'      => $report,
            'error'       => $error,
            'filters'     => $this->filterValues($scope, $report->level, $report->display, $report->expandEntities) + ['from' => $this->query('from')],
            'levels'      => range(1, $report->deepestLevel),
            'branches'    => $this->branchChoices($scope),
            'scope_label' => $scope->label(),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'ledger_url'  => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $report->from ?? '', $report->to),
            'other_url'   => $this->statementUrl('balance_sheet', $scope, $report->to),
        ]);
    }

    public function getBalanceSheet(): string
    {
        $scope   = $this->scope();
        $level   = $this->level();
        $display = StatementDisplay::fromRequest($this->query('display'));
        $entity  = $this->flag('entities');
        $error   = null;

        try {
            $report = $this->balanceSheet->build($scope, $this->asOf(), $level, $display, $entity);
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $report = $this->balanceSheet->build($scope, date('Y-m-d'), $level, $display, $entity);
        }

        return view('accounting_statements/balance_sheet', [
            'report'       => $report,
            'error'        => $error,
            'filters'      => $this->filterValues($scope, $report->level, $report->display, $report->expandEntities),
            'from_ignored' => $this->query('from') !== '',
            'levels'       => range(1, $report->deepestLevel),
            'branches'     => $this->branchChoices($scope),
            'scope_label'  => $scope->label(),
            'amount'       => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'         => fn (string $value): string => $this->formatDate($value),
            'ledger_url'   => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, '', $report->asOf),
            'other_url'    => $this->statementUrl('income_statement', $scope, $report->asOf),
        ]);
    }

    /**
     * What the request names, and nothing when it names it more than once or not as text, which
     * is how a hand-made address gives an array.
     */
    private function query(string $name): string
    {
        $value = $this->request->getGet($name);

        return is_string($value) ? trim($value) : '';
    }

    private function scope(): ReportScope
    {
        $requested = $this->request->getGet('branches');

        return ReportScope::resolve(is_array($requested) ? array_map('intval', $requested) : null, $this->branchIds());
    }

    private function asOf(): string
    {
        $to = $this->query('to');

        return $to === '' ? date('Y-m-d') : $to;
    }

    private function level(): ?int
    {
        $level = $this->query('level');

        return ctype_digit($level) ? (int) $level : null;
    }

    private function flag(string $name): bool
    {
        return $this->query($name) === '1';
    }

    /**
     * What the filter form shows: the last day as it was asked for, so a day that is not one is
     * shown back to be corrected, and the level and choices the statement was read with.
     *
     * @return array{to: string, level: int, display: StatementDisplay, entities: bool, branches: list<int>}
     */
    private function filterValues(ReportScope $scope, int $level, StatementDisplay $display, bool $entities): array
    {
        return ['to' => $this->asOf(), 'level' => $level, 'display' => $display, 'entities' => $entities, 'branches' => $scope->ids];
    }

    /**
     * The branches the employee may choose from, none for someone who reads one only.
     *
     * @return list<object>
     */
    private function branchChoices(ReportScope $scope): array
    {
        return $scope->choosable() ? $scope->choices : [];
    }

    private function ledgerUrl(int $accountId, ReportScope $scope, string $from, string $to): string
    {
        $query = ['account' => $accountId];

        if ($from !== '') {
            $query['from'] = $from;
        }

        $query['to'] = $to;

        if ($this->request->getGet('branches') !== null) {
            $query['branches'] = $scope->isEmpty() ? [0] : $scope->ids;
        }

        return base_url('accounting_reports/ledger') . '?' . http_build_query($query);
    }

    private function statementUrl(string $screen, ReportScope $scope, string $to): string
    {
        $query = ['to' => $to];

        if ($this->request->getGet('branches') !== null) {
            $query['branches'] = $scope->isEmpty() ? [0] : $scope->ids;
        }

        return base_url('accounting_statements/' . $screen) . '?' . http_build_query($query);
    }
}
