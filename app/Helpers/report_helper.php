<?php

use App\Libraries\Accounting\AccountingSettings;

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
