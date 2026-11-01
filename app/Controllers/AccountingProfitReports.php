<?php

namespace App\Controllers;

use App\Libraries\Accounting\OperationalReports\DateFilter;
use App\Libraries\Accounting\OperationalReports\ItemGroup;
use App\Libraries\Accounting\OperationalReports\ItemProfitReport;
use App\Libraries\Accounting\OperationalReports\ProfitReport;
use App\Libraries\Accounting\OperationalReports\ReportTable;
use App\Libraries\Accounting\ReportScope;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * The profit of the sales from the movements of stock that the books valued: by day, week, month, year,
 * customer, employee, item or category, and the profit of each item with its share of the profit and its
 * ratios to sales and to cost. The cost is read from the movements, and the report says whether it is what the
 * journals of the cost of sales hold for the same days. Each grouping is read under the permission of what
 * it groups by. The profit by item starts from the last month when it is given no days.
 */
class AccountingProfitReports extends AccountingOperationalReport
{
    private const MOVEMENT_GROUPS  = [ItemGroup::Day, ItemGroup::Week, ItemGroup::Month, ItemGroup::Year, ItemGroup::Customer, ItemGroup::User, ItemGroup::Item, ItemGroup::Category];
    private const ITEM_GROUPS      = [ItemGroup::Item, ItemGroup::Category, ItemGroup::Customer, ItemGroup::User];
    private const MOVEMENT_CARRIED = ['group', 'view', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'item', 'category', 'show'];
    private const ITEM_CARRIED     = ['group', 'preset', 'mode', 'from', 'to', 'date', 'customer', 'user', 'item', 'category', 'show'];

    private ProfitReport $movements;
    private ItemProfitReport $items;

    public function __construct()
    {
        parent::__construct();

        $this->movements = new ProfitReport();
        $this->items     = new ItemProfitReport();
    }

    public function getIndex(): ResponseInterface|string
    {
        $group = $this->group(self::MOVEMENT_GROUPS, ItemGroup::Day);

        $this->allow($group->permission());

        $scope = $this->requestedScope();
        $dates = $this->dates();
        $table = $this->isRun() ? $this->movementTable($scope, $group, $dates->from, $dates->to) : null;

        return $this->screen('accounting_reports/profit', 'Accounting_operational_reports.profit_title', $table, $dates, $scope, self::MOVEMENT_GROUPS, $group, true, 'accounting_reports/profit/csv', self::MOVEMENT_CARRIED, 'item_profit', 'Accounting_operational_reports.item_profit_title');
    }

    public function getCsv(): ResponseInterface
    {
        $group = $this->group(self::MOVEMENT_GROUPS, ItemGroup::Day);

        $this->allow($group->permission());

        $dates = $this->dates();

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('profit_' . $group->value, $this->movementTable($this->requestedScope(), $group, $dates->from, $dates->to), 'Accounting_operational_reports.profit_title');
    }

    public function getItems(): ResponseInterface|string
    {
        $group = $this->group(self::ITEM_GROUPS, ItemGroup::Item);

        $this->allow($group->permission());

        $scope = $this->requestedScope();
        $dates = $this->dates('last_month');
        $table = $this->isRun() ? $this->itemTable($scope, $group, $dates->from, $dates->to) : null;

        return $this->screen('accounting_reports/item_profit', 'Accounting_operational_reports.item_profit_title', $table, $dates, $scope, self::ITEM_GROUPS, $group, false, 'accounting_reports/item_profit/csv', self::ITEM_CARRIED, 'profit', 'Accounting_operational_reports.profit_title');
    }

    public function getItemsCsv(): ResponseInterface
    {
        $group = $this->group(self::ITEM_GROUPS, ItemGroup::Item);

        $this->allow($group->permission());

        $dates = $this->dates('last_month');

        if ($dates->refusal !== null) {
            return $this->refused($this->messageFor($dates->refusal));
        }

        return $this->csvResponse('item_profit_' . $group->value, $this->itemTable($this->requestedScope(), $group, $dates->from, $dates->to), 'Accounting_operational_reports.item_profit_title');
    }

    /**
     * @param list<ItemGroup> $allowed
     */
    private function group(array $allowed, ItemGroup $default): ItemGroup
    {
        $group = ItemGroup::tryFrom($this->query('group'));

        return $group !== null && in_array($group, $allowed, true) ? $group : $default;
    }

    /**
     * @param list<ItemGroup> $groups
     * @param list<string>    $carried
     */
    private function screen(string $route, string $titleKey, ?ReportTable $table, DateFilter $dates, ReportScope $scope, array $groups, ItemGroup $group, bool $offersView, string $csvRoute, array $carried, string $siblingRoute, string $siblingKey): string
    {
        $options = [];

        foreach ($groups as $option) {
            if ($this->can($option->permission())) {
                $options[$option->value] = lang($option->languageKey());
            }
        }

        $query = $this->carried($carried, $scope);

        return $this->page([
            'titleKey'      => $titleKey,
            'route'         => $route,
            'table'         => $table,
            'dates'         => $dates,
            'scope'         => $scope,
            'branches'      => $this->branchChoices($scope),
            'scopeLabel'    => $this->scopeLabel($scope),
            'error'         => $dates->refusal === null ? null : $this->messageFor($dates->refusal),
            'groups'        => $options,
            'group'         => $group->value,
            'groupLabelKey' => 'Accounting_operational_reports.group_by',
            'details'       => $this->wantsDetails(),
            'offersView'    => $offersView,
            'fields'        => [
                ['type' => 'select', 'name' => 'item', 'labelKey' => 'Accounting_operational_reports.item', 'options' => $this->itemChoices(), 'value' => $this->query('item')],
                ['type' => 'select', 'name' => 'category', 'labelKey' => 'Accounting_operational_reports.category', 'options' => $this->categoryChoices(), 'value' => $this->query('category')],
                ['type' => 'select', 'name' => 'customer', 'labelKey' => 'Accounting_operational_reports.customer', 'options' => $this->customerChoices(), 'value' => $this->query('customer')],
                ['type' => 'select', 'name' => 'user', 'labelKey' => 'Accounting_operational_reports.user', 'options' => $this->employeeChoices(), 'value' => $this->query('user')],
            ],
            'csvUrl'   => base_url($csvRoute) . ($query === [] ? '' : '?' . http_build_query($query)),
            'siblings' => [['label' => lang($siblingKey), 'url' => base_url('accounting_reports/' . $siblingRoute)]],
        ]);
    }

    private function movementTable(ReportScope $scope, ItemGroup $group, ?string $from, string $to): ReportTable
    {
        return $this->movements->build($scope, $group, $this->wantsDetails(), $from, $to, $this->positiveId('customer'), $this->positiveId('user'), $this->positiveId('item'), $this->query('category'));
    }

    private function itemTable(ReportScope $scope, ItemGroup $group, ?string $from, string $to): ReportTable
    {
        return $this->items->build($scope, $group, $from, $to, $this->positiveId('customer'), $this->positiveId('user'), $this->positiveId('item'), $this->query('category'));
    }
}
