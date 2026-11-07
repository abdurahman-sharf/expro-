<?php

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Organization\BranchGuard;

/**
 * @param string $report_name
 * @param string $report_prefix
 * @param string $lang_key
 * @return array
 */
function get_report_link(string $report_name, string $report_prefix = '', string $lang_key = ''): array
{
    $path = 'reports/';
    if ($report_prefix !== '') {
        $path .= $report_prefix . '_';
    }

    /**
     * Sanitize the report name in case it has come from the permissions table.
     */
    $report_name = str_replace('reports_', '', $report_name);
    $path .= $report_name;

    if ($lang_key === '') {
        $lang_key = 'Reports.' . $report_name;
    }

    return [
        'path'  => site_url($path),
        'label' => lang($lang_key),
    ];
}

/**
 * @param string $permission_id
 * @param string[] $restrict_views
 *
 * @return bool
 */
function can_show_report(string $permission_id, array $restrict_views = []): bool
{
    if (!str_contains($permission_id, 'reports_')) {
        return false;
    }

    foreach ($restrict_views as $restrict_view) {
        if (str_contains($permission_id, $restrict_view)) {
            return false;
        }
    }

    return true;
}

/**
 * Reports offered in the reports index and in the quick search, grouped by area and subject. The
 * reports of the books are offered only while accounting is on, whoever holds their permission.
 *
 * @param list<string> $permissionIds
 * @param bool|null    $accountingOn  Null reads the setting, and only when a subject of the books is to be offered.
 *
 * @return list<array{label: string, subjects: list<array{icon: string, label: string, links: list<array{path: string, label: string, icon: string}>}>}>
 */
function reportCatalog(array $permissionIds, ?bool $accountingOn = null): array
{
    $types = [
        'graphical' => ['Reports.type_graphical', 'bi-bar-chart-line'],
        'summary'   => ['Reports.type_summary', 'bi-table'],
        'detailed'  => ['Reports.type_detailed', 'bi-card-list'],
    ];

    $link = static fn (string $type, string $path, string $label = ''): array => [
        'path'  => site_url("reports/{$path}"),
        'label' => lang($label !== '' ? $label : $types[$type][0]),
        'icon'  => $types[$type][1],
    ];

    $groups = [
        'Reports.group_sales' => [
            ['bi-cart3', 'Module.sales', 'reports_sales', [
                $link('graphical', 'graphical_summary_sales'),
                $link('summary', 'summary_sales'),
                $link('detailed', 'detailed_sales'),
                $link('detailed', 'detailed_item_sales', 'Reports.detailed_item_sales_report'),
            ]],
            ['bi-people', 'Reports.customers', 'reports_customers', [
                $link('graphical', 'graphical_summary_customers'),
                $link('summary', 'summary_customers'),
                $link('detailed', 'specific_customers'),
            ]],
            ['bi-person-badge', 'Reports.employees', 'reports_employees', [
                $link('graphical', 'graphical_summary_employees'),
                $link('summary', 'summary_employees'),
                $link('detailed', 'specific_employees'),
            ]],
            ['bi-credit-card', 'Reports.payments', 'reports_payments', [
                $link('graphical', 'graphical_summary_payments'),
                $link('summary', 'summary_payments'),
            ]],
            ['bi-percent', 'Reports.discounts', 'reports_discounts', [
                $link('graphical', 'graphical_summary_discounts'),
                $link('summary', 'summary_discounts'),
                $link('detailed', 'specific_discounts'),
            ]],
        ],
        'Reports.group_stock' => [
            ['bi-box-seam', 'Reports.items', 'reports_items', [
                $link('graphical', 'graphical_summary_items'),
                $link('summary', 'summary_items'),
            ]],
            ['bi-diagram-3', 'Reports.categories', 'reports_categories', [
                $link('graphical', 'graphical_summary_categories'),
                $link('summary', 'summary_categories'),
            ]],
            ['bi-clipboard-data', 'Reports.inventory_reports', 'reports_inventory', [
                $link('summary', 'inventory_low', 'Reports.inventory_low'),
                $link('summary', 'inventory_summary', 'Reports.inventory_summary'),
            ]],
            ['bi-boxes', 'Accounting_inventory_reports.title', 'accounting_reports', [
                ['path' => site_url('accounting_reports/inventory_valuation'), 'label' => lang('Accounting_inventory_reports.catalog_link'), 'icon' => 'bi-table'],
            ]],
            ['bi-box-arrow-in-down', 'Reports.receivings', 'reports_receivings', [
                $link('detailed', 'detailed_receivings'),
            ]],
            ['bi-truck', 'Reports.suppliers', 'reports_suppliers', [
                $link('graphical', 'graphical_summary_suppliers'),
                $link('summary', 'summary_suppliers'),
                $link('detailed', 'specific_suppliers'),
            ]],
        ],
        'Reports.group_finance' => [
            ['bi-receipt', 'Reports.taxes', 'reports_taxes', [
                $link('graphical', 'graphical_summary_taxes'),
                $link('summary', 'summary_taxes'),
            ]],
            ['bi-receipt-cutoff', 'Reports.sales_taxes', 'reports_sales_taxes', [
                $link('graphical', 'graphical_summary_sales_taxes'),
                $link('summary', 'summary_sales_taxes'),
            ]],
            ['bi-journal-check', 'Accounting_tax_reports.title', 'accounting_reports', [
                ['path' => site_url('accounting_reports/tax'), 'label' => lang('Accounting_tax_reports.catalog_link'), 'icon' => 'bi-table'],
            ]],
            ['bi-graph-up-arrow', 'Accounting_profit_loss.title', 'accounting_reports', [
                ['path' => site_url('accounting_statements/profit_loss'), 'label' => lang('Accounting_profit_loss.catalog_link'), 'icon' => 'bi-table'],
            ]],
            ['bi-cash-stack', 'Accounting_cash_flow.title', 'accounting_reports', [
                ['path' => site_url('accounting_statements/cash_flow'), 'label' => lang('Accounting_cash_flow.catalog_link'), 'icon' => 'bi-table'],
            ]],
            ['bi-folder2-open', 'Reports.expenses_categories', 'reports_expenses_categories', [
                $link('graphical', 'graphical_summary_expenses_categories'),
                $link('summary', 'summary_expenses_categories'),
            ]],
        ],
    ];

    $catalog = [];

    foreach ($groups as $label => $subjects) {
        $allowed = [];

        foreach ($subjects as [$icon, $subjectLabel, $permission, $links]) {
            if (! in_array($permission, $permissionIds, true)) {
                continue;
            }

            if (str_starts_with($permission, 'accounting_')) {
                $accountingOn ??= (new AccountingSettings())->isEnabled();

                if (! $accountingOn) {
                    continue;
                }
            }

            $allowed[] = ['icon' => $icon, 'label' => lang($subjectLabel), 'links' => $links];
        }

        if ($allowed !== []) {
            $catalog[] = ['label' => lang($label), 'subjects' => $allowed];
        }
    }

    return $catalog;
}

/**
 * The operational reports of the books, offered beside the others in the reports index and in the quick
 * search: sales by period, customer and employee, what was sold, profit, payments and the position of the
 * invoices after their returns. Each is offered to someone who holds the module of the financial reports and
 * the permission of the report, and the link opens the first grouping the person may read. They are offered
 * only while accounting is on.
 *
 * @param list<string> $permissionIds
 * @param bool|null    $accountingOn  Null reads the setting.
 *
 * @return list<array{label: string, subjects: list<array{icon: string, label: string, links: list<array{path: string, label: string, icon: string}>}>}>
 */
function operationalReportCatalog(array $permissionIds, ?bool $accountingOn = null): array
{
    if (! in_array('accounting_reports', $permissionIds, true)) {
        return [];
    }

    $accountingOn ??= (new AccountingSettings())->isEnabled();

    if (! $accountingOn) {
        return [];
    }

    $groups = ['reports_sales' => 'day', 'reports_customers' => 'customer', 'reports_employees' => 'user'];
    $items  = ['reports_items' => 'item', 'reports_categories' => 'category', 'reports_customers' => 'customer', 'reports_employees' => 'user'];

    $subjects = [
        ['bi-cart3', 'Accounting_operational_reports.catalog_sales', [
            ['revenue', $groups, 'Accounting_operational_reports.revenue_title'],
            ['clients_sales', ['reports_customers' => ''], 'Accounting_operational_reports.clients_title'],
            ['net_position', ['reports_sales' => ''], 'Accounting_operational_reports.net_title'],
            ['invoice_returns', ['reports_sales' => ''], 'Accounting_operational_reports.cross_title'],
        ]],
        ['bi-box-seam', 'Accounting_operational_reports.catalog_items', [
            ['item_sales', $items, 'Accounting_operational_reports.items_title'],
        ]],
        ['bi-credit-card', 'Accounting_operational_reports.catalog_payments', [
            ['payments', ['reports_payments' => 'customer'], 'Accounting_operational_reports.payments_title'],
            ['advance_payments', ['reports_payments' => ''], 'Accounting_operational_reports.advances_title'],
        ]],
        ['bi-graph-up-arrow', 'Accounting_operational_reports.catalog_profit', [
            ['profit', ['reports_items' => 'day', 'reports_categories' => 'category', 'reports_customers' => 'customer', 'reports_employees' => 'user'], 'Accounting_operational_reports.profit_title'],
            ['item_profit', $items, 'Accounting_operational_reports.item_profit_title'],
        ]],
    ];

    $offered = [];

    foreach ($subjects as [$icon, $label, $reports]) {
        $links = [];

        foreach ($reports as [$route, $permissions, $linkLabel]) {
            foreach ($permissions as $permission => $group) {
                if (in_array($permission, $permissionIds, true)) {
                    $links[] = ['path' => site_url('accounting_reports/' . $route) . ($group === '' ? '' : '?group=' . $group), 'label' => lang($linkLabel), 'icon' => 'bi-table'];

                    break;
                }
            }
        }

        if ($links !== []) {
            $offered[] = ['icon' => $icon, 'label' => lang($label), 'links' => $links];
        }
    }

    return $offered === [] ? [] : [['label' => lang('Accounting_operational_reports.catalog_group'), 'subjects' => $offered]];
}

/**
 * The report permissions that open a report for the logged-in employee. The expenses reports hold
 * the figures of the whole company, since expenses carry no branch, so only an employee who works in
 * every branch is offered them.
 *
 * @param list<string> $permissionIds
 *
 * @return list<string>
 */
function openableReportPermissions(array $permissionIds): array
{
    $employeeId = (int) session()->get('person_id');

    if ($employeeId < 1 || (new BranchGuard())->isUnrestricted($employeeId)) {
        return $permissionIds;
    }

    return array_values(array_diff($permissionIds, ['reports_expenses_categories']));
}

/**
 * The branches the logged-in employee reads in the operational reports, for the note that says
 * whose figures a report holds.
 */
function reportScope(): ReportScope
{
    $employeeId = (int) session()->get('person_id');

    return ReportScope::resolve(null, $employeeId > 0 ? (new BranchGuard())->allowedBranchIds($employeeId) : null);
}
