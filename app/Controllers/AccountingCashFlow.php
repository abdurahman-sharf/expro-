<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CashFlow;
use App\Libraries\Accounting\CashFlowFormula;
use App\Libraries\Accounting\CashFlowLineResult;
use App\Libraries\Accounting\CashFlowReport;
use App\Libraries\Accounting\CashFlowSection;
use App\Libraries\Accounting\CashFlowSettings;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ScopedReportRequest;
use App\Libraries\Organization\BranchException;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The cash flow of a range from the journals that are in the books, with its file, and the screen
 * where its lines are set up. The report is read under the module of the financial reports, for one
 * branch, for several or for the company, and what an employee asks for is cut down to the branches
 * they work in. The setup can be read by whoever reads the report and is saved only by someone who
 * may manage the settings and works in every branch.
 */
class AccountingCashFlow extends AccountingController
{
    use ExportsCsv;
    use ScopedReportRequest;

    private CashFlow $flow;
    private CashFlowSettings $settings;

    public function __construct()
    {
        parent::__construct('accounting_reports');

        $this->settings = new CashFlowSettings();
        $this->flow     = new CashFlow(null, $this->settings);
    }

    public function getIndex(): string
    {
        $scope = $this->requestedScope();
        $error = null;

        try {
            $period = $this->period();
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        return view('accounting_statements/cash_flow', [
            'report'     => $this->flow->build($scope, $period),
            'error'      => $error,
            'filters'    => ['from' => $this->query('from'), 'to' => $this->toDay(), 'branches' => $scope->ids],
            'branches'   => $this->branchChoices($scope),
            'scopeLabel' => $this->scopeLabel($scope),
            'csvUrl'     => base_url('accounting_statements/cash_flow/csv') . $this->queryString($scope),
            'canSetUp'   => $this->canManage(AccountingGuard::SETTINGS_MANAGE),
            'amount'     => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'       => fn (string $value): string => $this->formatDate($value),
            'title'      => fn (CashFlowLineResult $line): string => $this->lineTitle($line->key, $line->title),
            'ledgerUrl'  => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $period),
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $scope = $this->requestedScope();

        try {
            $report = $this->flow->build($scope, $this->period());
        } catch (AccountingException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="cash_flow_' . $report->from . '_' . $report->to . '.csv"')
            ->setBody("\xEF\xBB\xBF" . $this->csv($report));
    }

    public function getSettings(): string
    {
        $lines = $this->settings->lines();

        return view('accounting_statements/cash_flow_settings', [
            'lines'      => $lines,
            'version'    => $this->settings->version(),
            'accounts'   => $this->settings->accountChoices(),
            'formulas'   => CashFlowFormula::cases(),
            'sections'   => CashFlowSection::cases(),
            'canManage'  => $this->canManage(AccountingGuard::SETTINGS_MANAGE),
            'maxCustom'  => CashFlowSettings::MAX_CUSTOM_LINES,
            'titleMax'   => CashFlowSettings::TITLE_MAX,
            'defaultFor' => fn (string $key): string => $this->lineTitle($key, ''),
        ]);
    }

    public function postSettingsSave(): ResponseInterface
    {
        $lines   = $this->request->getPost('lines');
        $version = $this->request->getPost('version');

        try {
            $this->settings->save(['lines' => is_array($lines) ? array_values($lines) : []], $this->employeeId(), is_string($version) ? $version : '');

            return $this->response->setJSON(['success' => true, 'message' => lang('Accounting_cash_flow.settings_saved'), 'id' => 1]);
        } catch (AccountingException|BranchException $exception) {
            return $this->response->setJSON(['success' => false, 'message' => $this->messageFor($exception), 'id' => NEW_ENTRY]);
        }
    }

    private function lineTitle(string $key, string $title): string
    {
        if ($title !== '') {
            return $title;
        }

        $text = lang('Accounting_cash_flow.line_' . $key);

        return $text === 'Accounting_cash_flow.line_' . $key ? $key : $text;
    }

    private function csv(CashFlowReport $report): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [lang('Accounting_cash_flow.period_beginning'), $report->from]);
        $this->csvRow($handle, [lang('Accounting_cash_flow.period_ending'), $report->to]);
        $this->csvRow($handle, [lang('Accounting_reports.csv_branches'), implode(' ', $report->scope->codes)]);
        $this->csvNote($handle, $this->scopeLabel($report->scope));
        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_cash_flow.line'), lang('Accounting_cash_flow.amount')]);

        foreach (CashFlowSection::cases() as $section) {
            $this->csvRow($handle, [lang('Accounting_cash_flow.section_' . $section->value)]);

            foreach ($report->lines[$section->value] as $line) {
                $this->csvRow($handle, [$this->lineTitle($line->key, $line->title), $line->amount]);
            }

            $this->csvRow($handle, [lang('Accounting_cash_flow.total_' . $section->value), $report->totals[$section->value]]);
        }

        $this->csvRow($handle, [lang('Accounting_cash_flow.other_changes'), $report->other]);

        foreach ($report->otherItems as $item) {
            $this->csvRow($handle, [$item['code'] . ' ' . $item['name'], $item['amount']]);
        }

        $this->csvRow($handle, [lang('Accounting_cash_flow.total_change'), $report->totalChange]);
        $this->csvRow($handle, [lang('Accounting_cash_flow.cash_opening'), $report->cashOpening]);
        $this->csvRow($handle, [lang('Accounting_cash_flow.cash_closing'), $report->cashClosing]);
        $this->csvRow($handle, [lang($report->ties() ? 'Accounting_cash_flow.ties' : 'Accounting_cash_flow.differs')]);

        return $this->csvText($handle);
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

    private function ledgerUrl(int $accountId, ReportScope $scope, ReportPeriod $period): string
    {
        $query = ['account' => $accountId, 'to' => $period->to];

        if ($period->from !== null) {
            $query['from'] = $period->from;
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return base_url('accounting_reports/ledger') . '?' . http_build_query($query);
    }

    private function queryString(ReportScope $scope): string
    {
        $query = [];

        foreach (['from', 'to'] as $name) {
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
