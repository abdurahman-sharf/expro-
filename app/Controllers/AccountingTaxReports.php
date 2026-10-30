<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
use App\Libraries\Accounting\ScopedReportRequest;
use App\Libraries\Accounting\TaxBasis;
use App\Libraries\Accounting\TaxReport;
use App\Libraries\Accounting\TaxReportResult;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The tax of a period from the journals that are in the books, with its file. It is read under the
 * module of the financial reports, for one branch, for several or for the company, and what an
 * employee asks for is cut down to the branches they work in. Only the accrual basis exists: a
 * request for another is refused in words.
 */
class AccountingTaxReports extends AccountingController
{
    use ScopedReportRequest;

    private TaxReport $taxes;

    public function __construct()
    {
        parent::__construct('accounting_reports');

        $this->taxes = new TaxReport();
    }

    public function getIndex(): string
    {
        $scope  = $this->requestedScope();
        $error  = null;
        $report = null;

        try {
            $period = $this->period();
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $period = ReportPeriod::of(null, date('Y-m-d'));
        }

        try {
            $report = $this->taxes->build($scope, $period, TaxBasis::fromRequest($this->request->getGet('basis')));
        } catch (AccountingException $refusal) {
            $error = $this->messageFor($refusal);
        }

        return view('accounting_reports/tax_report', [
            'report'     => $report,
            'error'      => $error,
            'filters'    => ['from' => $this->query('from'), 'to' => $this->toDay(), 'branches' => $scope->ids],
            'branches'   => $this->branchChoices($scope),
            'scope'      => $scope,
            'scopeLabel' => $this->scopeLabel($scope),
            'scopeKind'  => $scope->kind,
            'csvUrl'     => base_url('accounting_reports/tax/csv') . $this->queryString($scope),
            'amount'     => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'date'       => fn (string $value): string => $this->formatDate($value),
            'ledgerUrl'  => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $period),
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $scope = $this->requestedScope();

        try {
            $report = $this->taxes->build($scope, $this->period(), TaxBasis::fromRequest($this->request->getGet('basis')));
        } catch (AccountingException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        $name = 'tax_report_' . ($report->from === null ? '' : $report->from . '_') . $report->to . '.csv';

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody("\xEF\xBB\xBF" . $this->csv($report));
    }

    private function csv(TaxReportResult $report): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [
            lang('Accounting_tax_reports.col_type'),
            lang('Accounting_tax_reports.col_part'),
            lang('Accounting_tax_reports.col_rate'),
            lang('Accounting_tax_reports.col_documents'),
            lang('Accounting_tax_reports.col_base'),
            lang('Accounting_tax_reports.col_tax'),
        ]);

        foreach ($report->sections as $figures) {
            $part = lang('Accounting_tax_reports.section_' . $figures->section->value);

            $this->csvRow($handle, [lang('Accounting_tax_reports.row_part'), $part, '', (string) $figures->documents, $figures->base, $figures->tax]);

            foreach ($figures->rates as $rate) {
                $this->csvRow($handle, [lang('Accounting_tax_reports.row_rate'), $part, $rate->rate, (string) $rate->documents, $rate->base, $rate->tax]);
            }

            if ($figures->discountedDocuments > 0) {
                $this->csvRow($handle, [lang('Accounting_tax_reports.row_discounted'), $part, '', (string) $figures->discountedDocuments, $figures->discountedBase, $figures->discountedTax]);
            }
        }

        $total = lang('Accounting_tax_reports.row_total');

        $this->csvRow($handle, [$total, lang('Accounting_tax_reports.total_output'), '', '', $report->outputBase(), $report->output()]);
        $this->csvRow($handle, [$total, lang('Accounting_tax_reports.total_input'), '', '', $report->inputBase(), $report->input()]);
        $this->csvRow($handle, [$total, lang($report->scope->kind === ReportScopeKind::Company ? 'Accounting_tax_reports.row_total_company' : 'Accounting_tax_reports.row_total_branches') . ': ' . lang(bccomp($report->net(), '0', 4) < 0 ? 'Accounting_tax_reports.net_refundable' : 'Accounting_tax_reports.net_payable'), '', '', '', $report->net()]);

        $this->csvChecks($handle, $report);

        rewind($handle);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        return $text;
    }

    /**
     * What the screen says about the figures above: whether each account of tax ties to the books,
     * the basis, the day the books began, and the documents the figures leave out or count without
     * their taxable amount. A file taken to a tax return carries them too.
     *
     * @param resource $handle
     */
    private function csvChecks($handle, TaxReportResult $report): void
    {
        $this->csvRow($handle, []);
        $this->csvRow($handle, [
            lang('Accounting_tax_reports.account'),
            lang('Accounting_tax_reports.col_movement'),
            lang('Accounting_tax_reports.col_reported'),
            lang('Accounting_tax_reports.col_other'),
            lang('Accounting_tax_reports.col_other_count'),
            lang('Accounting_tax_reports.col_status'),
        ]);

        foreach ($report->accounts as $account) {
            $this->csvRow($handle, [
                $account->code . ' ' . $account->name,
                $account->movement,
                $account->reported,
                $account->other(),
                (string) $account->otherJournals,
                lang($account->ties() ? 'Accounting_tax_reports.agrees' : 'Accounting_tax_reports.differs'),
            ]);
        }

        $this->csvRow($handle, []);
        $this->csvRow($handle, [lang('Accounting_tax_reports.basis'), lang('Accounting_tax_reports.basis_' . $report->basis->value)]);
        $this->csvRow($handle, [lang('Accounting_tax_reports.csv_start'), $report->startDate ?? '']);
        $this->csvRow($handle, [lang('Accounting_tax_reports.csv_legacy'), (string) $report->legacyDocuments]);
        $this->csvRow($handle, [lang('Accounting_tax_reports.csv_unmatched'), (string) $report->unmatchedDocuments]);

        if (! $report->outputRouted) {
            $this->csvRow($handle, [lang('Accounting_tax_reports.not_routed_output')]);
        }

        if (! $report->inputRouted) {
            $this->csvRow($handle, [lang('Accounting_tax_reports.not_routed_input')]);
        }
    }

    /**
     * @param resource     $handle
     * @param list<string> $cells
     */
    private function csvRow($handle, array $cells): void
    {
        fputcsv($handle, $cells, ',', '"', '');
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
