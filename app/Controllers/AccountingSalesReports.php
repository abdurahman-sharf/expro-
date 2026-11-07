<?php

namespace App\Controllers;

use App\Libraries\Accounting\OperationalReports\ClientsSalesReport;
use App\Libraries\Accounting\OperationalReports\DateFilter;
use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\OperationalReports\RevenueGroup;
use App\Libraries\Accounting\OperationalReports\RevenueReport;
use App\Libraries\Accounting\OperationalReports\StatusFilter;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The sales of the books in two families of reports: the revenue by day, week, month, year, customer or
 * employee, split into what was paid, what is left and what was taken back, and the sales of each customer
 * document by document. Each is read under the permission of what it is grouped by, and every one of them
 * says whether its total, its tax and its revenue are what the journals of the same documents hold.
 */
class AccountingSalesReports extends AccountingOperationalReport
{
    private const REVENUE_CARRIED = ['group', 'view', 'preset', 'mode', 'from', 'to', 'date', 'status', 'customer', 'user', 'not_taxed', 'show'];
    private const REVENUE_FILTERS = ['customer' => 'reports_customers', 'user' => 'reports_employees'];
    private const CLIENTS_CARRIED = ['type', 'group', 'view', 'order', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'show'];

    private RevenueReport $revenue;
    private ClientsSalesReport $clients;

    public function __construct()
    {
        parent::__construct();

        $this->revenue = new RevenueReport();
        $this->clients = new ClientsSalesReport();
    }

    public function getRevenue(): ResponseInterface|string
    {
        $group = $this->revenueGroup();

        $this->allow($group->permission());
        $this->allowFilters(self::REVENUE_FILTERS);

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->runs($dates) ? $this->revenueTable($scope, $group, $dates) : null;

        return $this->page([
            'titleKey'      => 'Accounting_operational_reports.revenue_title',
            'route'         => 'accounting_reports/revenue',
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => $this->revenueGroups(),
            'group'         => $group->value,
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => $this->wantsDetails(),
            'offersView'    => true,
            'fields'        => $this->permittedFields([
                ['type' => 'multi', 'name' => 'status', 'labelKey' => 'Accounting_operational_reports.status', 'options' => $this->statusChoices(), 'value' => array_map(static fn (StatusFilter $filter): string => $filter->value, StatusFilter::fromRequest($this->request->getGet('status')))],
                ['type' => 'select', 'name' => 'customer', 'labelKey' => 'Accounting_operational_reports.customer', 'options' => $this->customerChoices(), 'value' => $this->query('customer')],
                ['type' => 'select', 'name' => 'user', 'labelKey' => 'Accounting_operational_reports.user', 'options' => $this->employeeChoices(), 'value' => $this->query('user')],
                ['type' => 'check', 'name' => 'not_taxed', 'labelKey' => 'Accounting_operational_reports.show_not_taxed', 'value' => $this->flag('not_taxed')],
            ], self::REVENUE_FILTERS),
            'csvUrl'   => base_url('accounting_reports/revenue/csv') . $this->queryString(self::REVENUE_CARRIED, $scope),
            'siblings' => $this->siblings('revenue'),
        ]);
    }

    public function getRevenueCsv(): ResponseInterface
    {
        $group = $this->revenueGroup();

        $this->allow($group->permission());
        $this->allowFilters(self::REVENUE_FILTERS);

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('revenue_' . $group->value, $this->revenueTable($this->requestedScope(), $group, $dates), 'Accounting_operational_reports.revenue_title');
    }

    public function getClients(): ResponseInterface|string
    {
        $this->allow('reports_customers');

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->runs($dates) ? $this->clientsTable($scope, $dates) : null;

        return $this->page([
            'titleKey'      => 'Accounting_operational_reports.clients_title',
            'route'         => 'accounting_reports/clients_sales',
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => ['customer' => lang('Accounting_operational_reports.group_customer'), 'document' => lang('Accounting_operational_reports.group_document')],
            'group'         => $this->choice('group', ['customer', 'document'], 'customer'),
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => $this->wantsDetails(),
            'offersView'    => true,
            'fields'        => [
                ['type' => 'select', 'name' => 'type', 'labelKey' => 'Accounting_operational_reports.type', 'options' => $this->typeChoices(), 'value' => $this->query('type')],
                ['type' => 'select', 'name' => 'customer', 'labelKey' => 'Accounting_operational_reports.customer', 'options' => $this->customerChoices(), 'value' => $this->query('customer')],
                ['type' => 'select', 'name' => 'order', 'labelKey' => 'Accounting_operational_reports.order', 'options' => $this->orderChoices(), 'value' => $this->choice('order', ClientsSalesReport::ORDERS, 'date_desc')],
            ],
            'csvUrl'   => base_url('accounting_reports/clients_sales/csv') . $this->queryString(self::CLIENTS_CARRIED, $scope),
            'siblings' => $this->siblings('clients_sales'),
        ]);
    }

    public function getClientsCsv(): ResponseInterface
    {
        $this->allow('reports_customers');

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('clients_sales', $this->clientsTable($this->requestedScope(), $dates), 'Accounting_operational_reports.clients_title');
    }

    private function revenueTable(ReportScope $scope, RevenueGroup $group, DateFilter $dates): ReportTable
    {
        return $this->revenue->build(
            $scope,
            $group,
            $this->wantsDetails(),
            $dates->from,
            $dates->to,
            $this->today(),
            StatusFilter::fromRequest($this->request->getGet('status')),
            $this->positiveId('customer'),
            $this->positiveId('user'),
            $this->flag('not_taxed'),
            $dates->isOpenStart(),
        );
    }

    private function revenueGroup(): RevenueGroup
    {
        return RevenueGroup::tryFrom($this->query('group')) ?? $this->firstReadable(RevenueGroup::cases());
    }

    private function clientsTable(ReportScope $scope, DateFilter $dates): ReportTable
    {
        return $this->clients->build(
            $scope,
            DocumentKind::tryFrom($this->query('type')),
            $this->choice('group', ['customer', 'document'], 'customer') === 'customer',
            $this->wantsDetails(),
            $dates->from,
            $dates->to,
            $this->positiveId('customer'),
            $this->choice('order', ClientsSalesReport::ORDERS, 'date_desc'),
        );
    }

    /**
     * @return array<string, string> The groupings the employee may open.
     */
    private function revenueGroups(): array
    {
        $groups = [];

        foreach (RevenueGroup::cases() as $group) {
            if ($this->can($group->permission())) {
                $groups[$group->value] = lang($group->languageKey());
            }
        }

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    private function statusChoices(): array
    {
        $choices = [];

        foreach (StatusFilter::cases() as $filter) {
            $choices[$filter->value] = lang($filter->languageKey());
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function typeChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.type_all')];

        foreach (DocumentKind::cases() as $kind) {
            $choices[$kind->value] = lang($kind->languageKey());
        }

        return $choices;
    }

    /**
     * @return array<string, string>
     */
    private function orderChoices(): array
    {
        $choices = [];

        foreach (ClientsSalesReport::ORDERS as $order) {
            $choices[$order] = lang('Accounting_operational_reports.order_' . $order);
        }

        return $choices;
    }

    /**
     * @param list<string> $names
     */
    private function queryString(array $names, ReportScope $scope): string
    {
        $query = $this->carried($names, $scope);

        return $query === [] ? '' : '?' . http_build_query($query);
    }

    /**
     * @return list<array{label: string, url: string}> The other report, when the employee may open it.
     */
    private function siblings(string $current): array
    {
        $opens = [
            'revenue'       => array_filter(RevenueGroup::cases(), fn (RevenueGroup $group): bool => $this->can($group->permission())) !== [],
            'clients_sales' => $this->can('reports_customers'),
        ];
        $links = [];

        foreach (['revenue' => 'revenue_title', 'clients_sales' => 'clients_title'] as $route => $key) {
            if ($route !== $current && $opens[$route]) {
                $links[] = ['label' => lang('Accounting_operational_reports.' . $key), 'url' => base_url('accounting_reports/' . $route)];
            }
        }

        return $links;
    }
}
