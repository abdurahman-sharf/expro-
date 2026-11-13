<?php

namespace App\Controllers;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\ExportsCsv;
use App\Libraries\Accounting\InventoryValuation;
use App\Libraries\Accounting\InventoryValuationReport;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\ReportScopeKind;
use App\Libraries\Accounting\ScopedReportRequest;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The value of the stock by the books: the movements that reached them, location by location and
 * item by item, beside the accounts of the locations. It is read under the module of the financial
 * reports, for one branch, for several or for the company, and what an employee asks for is cut
 * down to the branches they work in.
 */
class AccountingInventoryReports extends AccountingController
{
    use ExportsCsv;
    use ScopedReportRequest;

    private InventoryValuation $valuation;

    public function __construct()
    {
        parent::__construct('accounting_reports');

        $this->valuation = new InventoryValuation();
    }

    public function getIndex(): string
    {
        $scope = $this->requestedScope();
        $error = null;

        try {
            $report = $this->valuation->build($scope, $this->query('as_of'), $this->flag('empty'));
        } catch (AccountingException $refusal) {
            $error  = $this->messageFor($refusal);
            $report = $this->valuation->build($scope, null, $this->flag('empty'));
        }

        return view('accounting_reports/inventory_valuation', [
            'report'      => $report,
            'error'       => $error,
            'filters'     => ['as_of' => $this->query('as_of'), 'empty' => $this->flag('empty'), 'branches' => $scope->ids],
            'branches'    => $this->branchChoices($scope),
            'scope_label' => $this->scopeLabel($scope),
            'scope_kind'  => $scope->kind,
            'csvUrl'      => $this->csvUrl($scope),
            'amount'      => fn (string $value): string => $this->amountCell($this->formatAmount($value)),
            'quantity'    => fn (string $value): string => $this->amountCell(to_quantity_decimals($value)),
            'date'        => fn (string $value): string => $this->formatDate($value),
            'ledger_url'  => fn (int $accountId): string => $this->ledgerUrl($accountId, $scope, $report->asOf),
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        try {
            $report = $this->valuation->build($this->requestedScope(), $this->query('as_of'), $this->flag('empty'));
        } catch (AccountingException $refusal) {
            return $this->response->setStatusCode(ResponseInterface::HTTP_UNPROCESSABLE_ENTITY)->setBody($this->messageFor($refusal));
        }

        $name = 'inventory_valuation_' . ($report->asOf ?? date('Y-m-d')) . '.csv';

        return $this->response
            ->setContentType('text/csv', 'UTF-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $name . '"')
            ->setBody("\xEF\xBB\xBF" . $this->csv($report));
    }

    private function csv(InventoryValuationReport $report): string
    {
        $handle = fopen('php://temp', 'r+b');

        $this->csvRow($handle, [
            lang('Accounting_inventory_reports.col_type'),
            lang('Accounting_reports.ledger_branch'),
            lang('Accounting_inventory_reports.col_location'),
            lang('Accounting_inventory_reports.col_item_number'),
            lang('Accounting_inventory_reports.col_item'),
            lang('Accounting_inventory_reports.col_quantity'),
            lang('Accounting_inventory_reports.col_average_cost'),
            lang('Accounting_inventory_reports.col_value'),
            lang('Accounting_inventory_reports.col_shelves'),
            lang('Accounting_inventory_reports.col_unposted'),
            lang('Accounting_inventory_reports.col_account_balance'),
            lang('Accounting_inventory_reports.difference'),
        ]);

        foreach ($report->locations as $location) {
            foreach ($location->lines as $line) {
                $this->csvRow($handle, [
                    lang('Accounting_inventory_reports.row_item'),
                    $location->branchCode ?? '',
                    $location->name,
                    $line->itemNumber ?? '',
                    $line->position->itemName,
                    $line->position->quantity,
                    $line->averageCost() === null ? '' : bcadd($line->averageCost(), '0', 6),
                    $line->position->value,
                    $line->onHand() ?? '',
                    $line->unposted() ?? '',
                    '',
                    '',
                ]);
            }

            $this->csvRow($handle, [
                lang('Accounting_inventory_reports.row_location'),
                $location->branchCode ?? '',
                $location->name,
                '',
                '',
                '',
                '',
                $location->value,
                '',
                '',
                $location->accountBalance,
                $location->difference(),
            ]);
        }

        $this->csvRow($handle, [
            lang($report->scope->kind === ReportScopeKind::Company ? 'Accounting_inventory_reports.row_total_company' : 'Accounting_inventory_reports.row_total_branches'),
            '',
            '',
            '',
            '',
            '',
            '',
            $report->value(),
            '',
            '',
            $report->stockAccountBalance ?? '',
            $report->stockAccountDifference() ?? '',
        ]);

        return $this->csvText($handle);
    }

    private function csvUrl(ReportScope $scope): string
    {
        return base_url('accounting_reports/inventory_valuation/csv') . $this->queryString($scope);
    }

    private function ledgerUrl(int $accountId, ReportScope $scope, ?string $asOf): string
    {
        $query = ['account' => $accountId];

        if ($asOf !== null) {
            $query['to'] = $asOf;
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

        if ($this->query('as_of') !== '') {
            $query['as_of'] = $this->query('as_of');
        }

        if ($this->flag('empty')) {
            $query['empty'] = 1;
        }

        $branches = $this->branchesToCarry($scope);

        if ($branches !== null) {
            $query['branches'] = $branches;
        }

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
