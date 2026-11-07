<?php

namespace App\Controllers;

use App\Libraries\Accounting\OperationalReports\CrossReferenceReport;
use App\Libraries\Accounting\OperationalReports\DateFilter;
use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\NetPositionReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The position of each invoice after its returns: the total, what was settled, what was taken back and
 * what is left of the total, and the invoices that have a return with the credit notes and refund
 * invoices under each. Both are read under the permission of the sales reports, and each says whether
 * its figures are what the ledger of the customers holds for the same invoices.
 */
class AccountingNetPosition extends AccountingOperationalReport
{
    private const PERMISSION    = 'reports_sales';
    private const NET_CARRIED   = ['view', 'preset', 'mode', 'from', 'to', 'date', 'hide_credit_notes', 'status', 'customer', 'show'];
    private const CROSS_CARRIED = ['group', 'view', 'order', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'type', 'status', 'show'];

    private NetPositionReport $net;
    private CrossReferenceReport $cross;

    public function __construct()
    {
        parent::__construct();

        $this->net   = new NetPositionReport();
        $this->cross = new CrossReferenceReport();
    }

    public function getIndex(): ResponseInterface|string
    {
        $this->allow(self::PERMISSION);

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->runs($dates) ? $this->netTable($scope, $dates) : null;
        $query = $this->carried(self::NET_CARRIED, $scope);

        return $this->page([
            'titleKey'      => 'Accounting_operational_reports.net_title',
            'route'         => 'accounting_reports/net_position',
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => [],
            'group'         => '',
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => $this->wantsDetails(),
            'offersView'    => true,
            'fields'        => [
                ['type' => 'multi', 'name' => 'status', 'labelKey' => 'Accounting_operational_reports.status', 'options' => $this->stateChoices(NetPositionReport::STATES), 'value' => $this->states(NetPositionReport::STATES)],
                ['type' => 'select', 'name' => 'customer', 'labelKey' => 'Accounting_operational_reports.customer', 'options' => $this->customerChoices(), 'value' => $this->query('customer')],
                ['type' => 'check', 'name' => 'hide_credit_notes', 'labelKey' => 'Accounting_operational_reports.credit_notes', 'checkLabelKey' => 'Accounting_operational_reports.hide_credit_notes', 'value' => $this->flag('hide_credit_notes')],
            ],
            'csvUrl'   => base_url('accounting_reports/net_position/csv') . ($query === [] ? '' : '?' . http_build_query($query)),
            'siblings' => [['label' => lang('Accounting_operational_reports.cross_title'), 'url' => base_url('accounting_reports/invoice_returns')]],
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $this->allow(self::PERMISSION);

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('net_position', $this->netTable($this->requestedScope(), $dates), 'Accounting_operational_reports.net_title');
    }

    public function getCross(): ResponseInterface|string
    {
        $this->allow(self::PERMISSION);

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->runs($dates) ? $this->crossTable($scope, $dates) : null;
        $query = $this->carried(self::CROSS_CARRIED, $scope);

        return $this->page([
            'titleKey'      => 'Accounting_operational_reports.cross_title',
            'route'         => 'accounting_reports/invoice_returns',
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => ['document' => lang('Accounting_operational_reports.group_document'), 'customer' => lang('Accounting_operational_reports.group_customer')],
            'group'         => $this->choice('group', ['document', 'customer'], 'document'),
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => $this->wantsDetails(),
            'offersView'    => true,
            'fields'        => [
                ['type' => 'select', 'name' => 'type', 'labelKey' => 'Accounting_operational_reports.return_type', 'options' => ['' => lang('Accounting_operational_reports.type_all'), DocumentKind::CreditNote->value => lang(DocumentKind::CreditNote->languageKey()), DocumentKind::RefundInvoice->value => lang(DocumentKind::RefundInvoice->languageKey())], 'value' => $this->query('type')],
                ['type' => 'multi', 'name' => 'status', 'labelKey' => 'Accounting_operational_reports.payment_state', 'options' => $this->stateChoices(['unpaid', 'partial', 'paid', 'excess']), 'value' => $this->states(['unpaid', 'partial', 'paid', 'excess'])],
                ['type' => 'select', 'name' => 'customer', 'labelKey' => 'Accounting_operational_reports.customer', 'options' => $this->customerChoices(), 'value' => $this->query('customer')],
                ['type' => 'select', 'name' => 'order', 'labelKey' => 'Accounting_operational_reports.order', 'options' => $this->orderChoices(), 'value' => $this->choice('order', CrossReferenceReport::ORDERS, 'date_desc')],
            ],
            'csvUrl'   => base_url('accounting_reports/invoice_returns/csv') . ($query === [] ? '' : '?' . http_build_query($query)),
            'siblings' => [['label' => lang('Accounting_operational_reports.net_title'), 'url' => base_url('accounting_reports/net_position')]],
        ]);
    }

    public function getCrossCsv(): ResponseInterface
    {
        $this->allow(self::PERMISSION);

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('invoice_returns', $this->crossTable($this->requestedScope(), $dates), 'Accounting_operational_reports.cross_title');
    }

    private function netTable(ReportScope $scope, DateFilter $dates): ReportTable
    {
        return $this->net->build($scope, $this->wantsDetails(), $dates->from, $dates->to, $this->flag('hide_credit_notes'), $this->states(NetPositionReport::STATES), $this->positiveId('customer'));
    }

    private function crossTable(ReportScope $scope, DateFilter $dates): ReportTable
    {
        $kind = DocumentKind::tryFrom($this->query('type'));

        return $this->cross->build(
            $scope,
            $this->wantsDetails(),
            $this->choice('group', ['document', 'customer'], 'document') === 'customer',
            $dates->from,
            $dates->to,
            $this->positiveId('customer'),
            $kind === DocumentKind::CreditNote || $kind === DocumentKind::RefundInvoice ? $kind : null,
            $this->states(['unpaid', 'partial', 'paid', 'excess']),
            $this->choice('order', CrossReferenceReport::ORDERS, 'date_desc'),
        );
    }

    /**
     * @param list<string> $allowed
     *
     * @return list<string>
     */
    private function states(array $allowed): array
    {
        $requested = $this->request->getGet('status');

        return is_array($requested) ? array_values(array_unique(array_filter($requested, static fn (mixed $state): bool => is_string($state) && in_array($state, $allowed, true)))) : [];
    }

    /**
     * @param list<string> $states
     *
     * @return array<string, string>
     */
    private function stateChoices(array $states): array
    {
        $choices = [];

        foreach ($states as $state) {
            $choices[$state] = lang('Accounting_operational_reports.state_' . $state);
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function orderChoices(): array
    {
        $choices = [];

        foreach (CrossReferenceReport::ORDERS as $order) {
            $choices[$order] = lang('Accounting_operational_reports.order_' . $order);
        }

        return $choices;
    }
}
