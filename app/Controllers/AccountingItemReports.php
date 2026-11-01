<?php

namespace App\Controllers;

use App\Libraries\Accounting\OperationalReports\DocumentKind;
use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ItemSalesReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\OperationalReports\StatusFilter;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * What was sold, from the documents that stand in the books: by item, category, customer, employee, day,
 * week, month or year, in summary or line by line, with the discount, the tax and the total of each. Each
 * grouping is read under the permission of what it groups by, and the report says whether its lines come to
 * what the journals of the same documents hold without their header charges.
 */
class AccountingItemReports extends AccountingOperationalReport
{
    private const CARRIED = ['group', 'view', 'preset', 'mode', 'from', 'to', 'date', 'type', 'status', 'customer', 'user', 'item', 'category', 'location', 'show'];

    private ItemSalesReport $report;

    public function __construct()
    {
        parent::__construct();

        $this->report = new ItemSalesReport();
    }

    public function getIndex(): ResponseInterface|string
    {
        $group = ItemGroup::tryFrom($this->query('group')) ?? ItemGroup::Item;

        $this->allow($group->permission());

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->isRun() ? $this->table($scope, $group, $dates->from, $dates->to) : null;

        return $this->page([
            'titleKey'      => 'Accounting_operational_reports.items_title',
            'route'         => 'accounting_reports/item_sales',
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => $this->groups(),
            'group'         => $group->value,
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => $this->wantsDetails(),
            'offersView'    => true,
            'fields'        => [
                ['type' => 'select', 'name' => 'type', 'labelKey' => 'Accounting_operational_reports.type', 'options' => $this->typeChoices(), 'value' => $this->query('type')],
                ['type' => 'multi', 'name' => 'status', 'labelKey' => 'Accounting_operational_reports.status', 'options' => $this->statusChoices(), 'value' => array_map(static fn (StatusFilter $filter): string => $filter->value, StatusFilter::fromRequest($this->request->getGet('status')))],
                ['type' => 'select', 'name' => 'item', 'labelKey' => 'Accounting_operational_reports.item', 'options' => $this->itemChoices(), 'value' => $this->query('item')],
                ['type' => 'select', 'name' => 'category', 'labelKey' => 'Accounting_operational_reports.category', 'options' => $this->categoryChoices(), 'value' => $this->query('category')],
                ['type' => 'select', 'name' => 'location', 'labelKey' => 'Accounting_operational_reports.location', 'options' => $this->locationChoices(), 'value' => $this->query('location')],
                ['type' => 'select', 'name' => 'customer', 'labelKey' => 'Accounting_operational_reports.customer', 'options' => $this->customerChoices(), 'value' => $this->query('customer')],
                ['type' => 'select', 'name' => 'user', 'labelKey' => 'Accounting_operational_reports.user', 'options' => $this->employeeChoices(), 'value' => $this->query('user')],
            ],
            'csvUrl'   => base_url('accounting_reports/item_sales/csv') . $this->queryString($scope),
            'siblings' => [],
        ]);
    }

    public function getCsv(): ResponseInterface
    {
        $group = ItemGroup::tryFrom($this->query('group')) ?? ItemGroup::Item;

        $this->allow($group->permission());

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('item_sales_' . $group->value, $this->table($this->requestedScope(), $group, $dates->from, $dates->to), 'Accounting_operational_reports.items_title');
    }

    private function table(ReportScope $scope, ItemGroup $group, ?string $from, string $to): ReportTable
    {
        $kind = DocumentKind::tryFrom($this->query('type'));

        return $this->report->build(
            $scope,
            $group,
            $this->wantsDetails(),
            $from,
            $to,
            $this->today(),
            $kind === DocumentKind::DebitNote ? null : $kind,
            StatusFilter::fromRequest($this->request->getGet('status')),
            $this->positiveId('customer'),
            $this->positiveId('user'),
            $this->positiveId('item'),
            $this->query('category'),
            $this->positiveId('location'),
        );
    }

    /**
     * @return array<string, string>
     */
    private function groups(): array
    {
        $groups = [];

        foreach (ItemGroup::cases() as $group) {
            if ($this->can($group->permission())) {
                $groups[$group->value] = lang($group->languageKey());
            }
        }

        return $groups;
    }

    /**
     * @return array<string, string>
     */
    private function typeChoices(): array
    {
        $choices = ['' => lang('Accounting_operational_reports.type_all')];

        foreach (DocumentKind::sales() as $kind) {
            $choices[$kind->value] = lang($kind->languageKey());
        }

        return $choices;
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

    private function queryString(ReportScope $scope): string
    {
        $query = $this->carried(self::CARRIED, $scope);

        return $query === [] ? '' : '?' . http_build_query($query);
    }
}
