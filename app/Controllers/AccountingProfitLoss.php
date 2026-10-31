<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\CashProfitAndLoss;
use App\Libraries\Accounting\CashProfitAndLossReport;
use App\Libraries\Accounting\CashProfitLine;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\ProfitAndLoss;
use App\Libraries\Accounting\ProfitAndLossReport;
use App\Libraries\Accounting\ProfitBasis;
use App\Libraries\Accounting\ProfitPeriodKind;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ScopedReportRequest;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The profit and loss of a range from the journals that are in the books, by accrual in months,
 * quarters or years, or by cash on the movements of the treasuries and banks, with its file. The
 * report always says its basis. It is read under the module of the financial reports, for one
 * branch, for several or for the company, and what an employee asks for is cut down to the branches
 * they work in. A basis that is not built is refused in words, on the screen and in the file.
 */
class AccountingProfitLoss extends AccountingController
{
    use ExportsCsv;
    use ScopedReportRequest;

    private ProfitAndLoss $accrual;
    private CashProfitAndLoss $cash;

    public function __construct()
    {
        parent::__construct('accounting_reports');

        $this->accrual = new ProfitAndLoss();
        $this->cash    = new CashProfitAndLoss();
    }

    public function getIndex(): string
    {
        $scope  = $this->requestedScope();
        $error  = null;
        $report = null;
        $basis  = ProfitBasis::Accrual;

        try {
            $period = $this->period();
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        try {
            $basis  = ProfitBasis::fromRequest($this->request->getGet('basis'));
            $report = $this->read($basis, $scope, $period);
        } catch (AccountingException $refusal) {
            $error = $this->messageFor($refusal);
        }

        $kind  = ProfitPeriodKind::fromRequest($this->request->getGet('period'));
        $level = $this->level();

        return view('accounting_statements/profit_loss', [
            'report'     => $report,
            'basis'      => $basis,
            'error'      => $error,
            'kind'       => $report instanceof ProfitAndLossReport ? $report->kind : $kind,
            'level'      => $report instanceof ProfitAndLossReport ? $report->level : $level,
            'levels'     => range(1, $report instanceof ProfitAndLossReport ? max(1, $report->deepestLevel) : 5),
            'filters'    => ['from' => $this->query('from'), 'to' => $this->toDay(), 'branches' => $scope->ids],
            'branches'   => $this->branchChoices($scope),
            'scopeLabel' => $this->scopeLabel($scope),
            'scopeKind'  => $scope->kind,
            'csvUrl'     => base_url('accounting_statements/profit_loss/csv') . $this->queryString($scope),
            'amount'     => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'       => fn (string $value): string => $this->formatDate($value),
            'ledgerUrl'  => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $period),
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $scope = $this->requestedScope();

        try {
            $period = $this->period();
            $basis  = ProfitBasis::fromRequest($this->request->getGet('basis'));
            $report = $this->read($basis, $scope, $period);
        } catch (AccountingException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        $to   = $report->to;
        $from = $report->from;
        $name = 'profit_loss_' . $basis->value . '_' . ($from === null ? '' : $from . '_') . $to . '.csv';

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody("\xEF\xBB\xBF" . ($report instanceof ProfitAndLossReport ? $this->accrualCsv($report) : $this->cashCsv($report)));
    }

    /**
     * @throws AccountingException
     */
    private function read(ProfitBasis $basis, ReportScope $scope, ReportPeriod $period): CashProfitAndLossReport|ProfitAndLossReport
    {
        return $basis === ProfitBasis::Cash
            ? $this->cash->build($scope, $period)
            : $this->accrual->build($scope, $period, ProfitPeriodKind::fromRequest($this->request->getGet('period')), $this->level());
    }

    private function accrualCsv(ProfitAndLossReport $report): string
    {
        $handle  = fopen('php://temp', 'r+b');
        $columns = array_map(static fn ($period): string => $period->label, $report->periods);

        $this->csvRow($handle, [lang('Accounting_profit_loss.basis'), lang('Accounting_profit_loss.basis_accrual')]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.csv_range'), $report->from . ' - ' . $report->to]);
        $this->csvRow($handle, [lang('Accounting_reports.csv_branches'), implode(' ', $report->scope->codes)]);
        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_profit_loss.summary'), ...$columns, lang('Accounting_profit_loss.total')]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.total_profit'), ...$report->profit, $report->totalProfit]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.total_revenue'), ...$report->revenue, $report->totalRevenue]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.total_expenses'), ...$report->expenses, $report->totalExpenses]);
        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_profit_loss.account'), ...$columns, lang('Accounting_profit_loss.total')]);

        foreach ([['revenue', $report->revenueLines, $report->totalRevenue, $report->revenue], ['expenses', $report->expenseLines, $report->totalExpenses, $report->expenses]] as [$section, $lines, $total, $totals]) {
            $this->csvRow($handle, [lang('Accounting_profit_loss.' . $section)]);

            foreach ($lines as $line) {
                $this->csvRow($handle, [$line->code . ' ' . $line->name, ...$line->amounts, $line->total]);
            }

            $this->csvRow($handle, [lang('Accounting_profit_loss.total_' . $section), ...$totals, $total]);
        }

        return $this->csvText($handle);
    }

    private function cashCsv(CashProfitAndLossReport $report): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [lang('Accounting_profit_loss.basis'), lang('Accounting_profit_loss.basis_cash')]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.csv_range'), ($report->from ?? '') . ' - ' . $report->to]);
        $this->csvRow($handle, [lang('Accounting_reports.csv_branches'), implode(' ', $report->scope->codes)]);
        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_profit_loss.account'), lang('Accounting_profit_loss.total')]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.revenue')]);

        foreach ($report->revenueLines as $line) {
            $this->csvRow($handle, [$this->lineName($line), $line->amount]);
        }

        $this->csvRow($handle, [lang('Accounting_profit_loss.total_revenue'), $report->totalRevenue]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.expenses')]);

        foreach ($report->expenseLines as $line) {
            $this->csvRow($handle, [$this->lineName($line), $line->amount]);
        }

        $this->csvRow($handle, [lang('Accounting_profit_loss.total_expenses'), $report->totalExpenses]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.total_profit'), $report->profit]);
        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_profit_loss.other_movements')]);

        foreach ($report->otherLines as $line) {
            $this->csvRow($handle, [$this->lineName($line), $line->amount]);
        }

        $this->csvRow($handle, [lang('Accounting_profit_loss.other_total'), $report->otherTotal]);
        $this->csvRow($handle, [lang('Accounting_profit_loss.cash_change'), $report->cashChange]);
        $this->csvRow($handle, [lang($report->ties() ? 'Accounting_profit_loss.ties' : 'Accounting_profit_loss.differs')]);

        return $this->csvText($handle);
    }

    private function lineName(CashProfitLine $line): string
    {
        return $line->kind === CashProfitLine::ACCOUNT ? $line->code . ' ' . $line->name : lang('Accounting_profit_loss.line_' . $line->kind);
    }

    /**
     * @throws AccountingException
     */
    private function period(): ReportPeriod
    {
        return ReportPeriod::of($this->query('from'), $this->toDay());
    }

    private function toDay(): string
    {
        $to = $this->query('to');

        return $to === '' ? date('Y-m-d') : $to;
    }

    private function level(): ?int
    {
        $level = $this->query('level');

        return ctype_digit($level) && (int) $level >= 1 ? (int) $level : null;
    }

    private function ledgerUrl(int $accountId, ReportScope $scope, ReportPeriod $period): string
    {
        $query = ['account' => $accountId];

        if ($period->from !== null) {
            $query['from'] = $period->from;
        }

        $query['to'] = $period->to;

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return base_url('accounting_reports/ledger') . '?' . http_build_query($query);
    }

    private function queryString(ReportScope $scope): string
    {
        $query = [];

        foreach (['basis', 'period', 'level', 'from', 'to'] as $name) {
            if ($this->query($name) !== '') {
                $query[$name] = $this->query($name);
            }
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
