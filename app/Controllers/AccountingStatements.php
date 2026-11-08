<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\BalanceSheet;
use App\Libraries\Accounting\BalanceSheetReport;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\IncomeStatement;
use App\Libraries\Accounting\IncomeStatementReport;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ScopedReportRequest;
use App\Libraries\Accounting\StatementDisplay;
use App\Libraries\Accounting\StatementSection;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The income statement and the balance sheet over the journals that are in the books. Each is for
 * one branch, for several, or for the company, and what an employee asks for is cut down to the
 * branches they work in; someone limited to some branches is told that the total is of those.
 */
class AccountingStatements extends AccountingController
{
    use ExportsCsv;
    use ScopedReportRequest;

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
        $scope = $this->requestedScope();
        $error = null;

        try {
            $period = ReportPeriod::of($this->query('from'), $this->asOf());
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        $report = $this->incomeStatement->build($scope, $period, $this->level(), StatementDisplay::fromRequest($this->query('display')));

        return view('accounting_statements/income_statement', [
            'report'     => $report,
            'error'      => $error,
            'filters'    => $this->filterValues($scope, $report->level, $report->display) + ['from' => $this->query('from')],
            'levels'     => range(1, $report->deepestLevel),
            'branches'   => $this->branchChoices($scope),
            'scopeLabel' => $this->scopeLabel($scope),
            'amount'     => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'       => fn (string $value): string => $this->formatDate($value),
            'ledgerUrl'  => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $report->from ?? '', $report->to),
            'otherUrl'   => $this->statementUrl('balance_sheet', $scope, $report->to),
            'csvUrl'     => $this->csvUrl('income_statement', $scope, ['from', 'to', 'level', 'display']),
        ]);
    }

    public function getBalanceSheet(): string
    {
        $scope   = $this->requestedScope();
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
            'report'      => $report,
            'error'       => $error,
            'filters'     => $this->filterValues($scope, $report->level, $report->display, $report->expandEntities),
            'fromIgnored' => $this->query('from') !== '',
            'levels'      => range(1, $report->deepestLevel),
            'branches'    => $this->branchChoices($scope),
            'scopeLabel'  => $this->scopeLabel($scope),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'ledgerUrl'   => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, '', $report->asOf),
            'otherUrl'    => $this->statementUrl('income_statement', $scope, $report->asOf),
            'csvUrl'      => $this->csvUrl('balance_sheet', $scope, ['to', 'level', 'display', 'entities']),
        ]);
    }

    public function getIncomeStatementCsv(): ResponseInterface
    {
        $scope = $this->requestedScope();

        try {
            $report = $this->incomeStatement->build($scope, ReportPeriod::of($this->query('from'), $this->asOf()), $this->level(), StatementDisplay::fromRequest($this->query('display')));
        } catch (AccountingException $refusal) {
            return $this->refused($this->messageFor($refusal));
        }

        return $this->download('income_statement_' . ($report->from === null ? '' : $report->from . '_') . $report->to . '.csv', $this->incomeStatementCsv($report));
    }

    public function getBalanceSheetCsv(): ResponseInterface
    {
        $scope = $this->requestedScope();

        try {
            $report = $this->balanceSheet->build($scope, $this->asOf(), $this->level(), StatementDisplay::fromRequest($this->query('display')), $this->flag('entities'));
        } catch (AccountingException $refusal) {
            return $this->refused($this->messageFor($refusal));
        }

        return $this->download('balance_sheet_' . $report->asOf . '.csv', $this->balanceSheetCsv($report));
    }

    private function refused(string $message): ResponseInterface
    {
        return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($message);
    }

    private function download(string $name, string $text): ResponseInterface
    {
        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody("\xEF\xBB\xBF" . $text);
    }

    private function incomeStatementCsv(IncomeStatementReport $report): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [lang('Accounting_statements.income_statement')]);
        $this->csvRow($handle, [lang('Accounting_reports.csv_period'), $report->from === null ? lang('Accounting_statements.period_all_years', [$report->to]) : lang('Accounting_reports.period_between', [$report->from, $report->to])]);
        $this->statementContext($handle, $report->scope);
        $this->csvRow($handle, [lang('Accounting_reports.code'), lang('Accounting_reports.account'), lang('Accounting_statements.amount')]);

        $this->sectionRows($handle, $report->revenue, lang('Accounting_statements.revenue'), lang('Accounting_statements.total_revenue'), $report->revenue->total);
        $this->sectionRows($handle, $report->expenses, lang('Accounting_statements.expenses'), lang('Accounting_statements.total_expenses'), $report->expenses->total);

        $this->csvRow($handle, ['', lang('Accounting_statements.net_income'), $report->netIncome]);
        $this->csvRow($handle, []);
        $this->csvNote($handle, lang('Accounting_statements.nominal_note_income_statement'));

        return $this->csvText($handle);
    }

    private function balanceSheetCsv(BalanceSheetReport $report): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [lang('Accounting_statements.balance_sheet_as_of', [$report->asOf])]);
        $this->statementContext($handle, $report->scope);

        if ($report->interBranchDifference !== null && bccomp($report->interBranchDifference, '0', 4) !== 0) {
            $this->csvRow($handle, [lang('Accounting_reports.inter_branch_difference', [$report->interBranchDifference])]);
        }

        $this->csvRow($handle, [lang('Accounting_reports.code'), lang('Accounting_reports.account'), lang('Accounting_statements.amount')]);

        $this->sectionRows($handle, $report->assets, lang('Accounting_statements.assets'), lang('Accounting_statements.total_assets'), $report->assets->total);
        $this->sectionRows($handle, $report->liabilities, lang('Accounting_statements.liabilities'), lang('Accounting_statements.total_liabilities'), $report->liabilities->total);
        $this->sectionRows($handle, $report->equity, lang('Accounting_statements.capital_and_equity'), lang('Accounting_statements.total_equity'), $report->equityTotal(), [['', lang('Accounting_statements.result_unclosed'), $report->unclosedResult]]);

        $this->csvRow($handle, ['', lang('Accounting_statements.total_liabilities_equity'), $report->liabilitiesAndEquity()]);
        $this->csvRow($handle, []);
        $this->csvNote($handle, lang('Accounting_statements.nominal_note_balance_sheet'));
        $this->csvRow($handle, [lang($report->balances() ? 'Accounting_statements.balanced' : 'Accounting_statements.unbalanced')]);

        return $this->csvText($handle);
    }

    /**
     * @param resource $handle
     */
    private function statementContext($handle, ReportScope $scope): void
    {
        $this->csvRow($handle, [lang('Accounting_reports.csv_branches'), implode(' ', $scope->codes)]);
        $this->csvNote($handle, $this->scopeLabel($scope));
    }

    /**
     * @param resource                         $handle
     * @param list<array{string, string, string}> $extra
     */
    private function sectionRows($handle, StatementSection $section, string $heading, string $totalLabel, string $total, array $extra = []): void
    {
        $this->csvRow($handle, [$heading]);

        foreach ($section->lines as $line) {
            $this->csvRow($handle, [$line->code, $line->name, $line->amount]);
        }

        foreach ($extra as $row) {
            $this->csvRow($handle, $row);
        }

        $this->csvRow($handle, ['', $totalLabel, $total]);
    }

    /**
     * The branches the statements carry into their links are those the request named as a list: a request
     * that names them in any other way is read as naming none and carries nothing.
     *
     * @return list<int>|null
     */
    protected function branchesToCarry(ReportScope $scope): ?array
    {
        return is_array($this->request->getGet('branches')) ? ($scope->isEmpty() ? [0] : $scope->ids) : null;
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

    /**
     * What the filter form shows: the last day as it was asked for, so a day that is not one is
     * shown back to be corrected, and the level and choices the statement was read with.
     *
     * @return array{to: string, level: int, display: StatementDisplay, entities: bool, branches: list<int>}
     */
    private function filterValues(ReportScope $scope, int $level, StatementDisplay $display, bool $entities = false): array
    {
        return ['to' => $this->asOf(), 'level' => $level, 'display' => $display, 'entities' => $entities, 'branches' => $scope->ids];
    }

    private function ledgerUrl(int $accountId, ReportScope $scope, string $from, string $to): string
    {
        $query = ['account' => $accountId];

        if ($from !== '') {
            $query['from'] = $from;
        }

        $query['to'] = $to;

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return base_url('accounting_reports/ledger') . '?' . http_build_query($query);
    }

    /**
     * @param list<string> $names The filters the statement was asked with.
     */
    private function csvUrl(string $screen, ReportScope $scope, array $names): string
    {
        $query = [];

        foreach ($names as $name) {
            if ($this->query($name) !== '') {
                $query[$name] = $this->query($name);
            }
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return base_url('accounting_statements/' . $screen . '/csv') . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function statementUrl(string $screen, ReportScope $scope, string $to): string
    {
        $query = ['to' => $to];

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return base_url('accounting_statements/' . $screen) . '?' . http_build_query($query);
    }
}
