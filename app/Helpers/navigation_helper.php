<?php

const NAVIGATION_GROUPS = [
    'group_main'       => ['home'],
    'group_sales'      => ['sales', 'customer_orders', 'recurring_invoices'],
    'group_purchases'  => ['purchase_requests', 'quotation_requests', 'supplier_quotations', 'purchase_orders', 'receivings', 'suppliers'],
    'group_inventory'  => ['items', 'item_kits', 'attributes', 'price_lists', 'stock_documents'],
    'group_customers'  => ['customers', 'giftcards', 'messages'],
    'group_finance'    => ['expenses', 'expenses_categories', 'recurring_expenses', 'receipt_vouchers', 'revenue_categories', 'cashups', 'taxes'],
    'group_accounting' => ['accounting_chart', 'accounting_journals', 'accounting_receivables', 'accounting_debit_notes', 'accounting_payables', 'accounting_cheques', 'accounting_reports', 'accounting_cost_centers', 'accounting_currencies', 'accounting_periods', 'accounting_routing', 'accounting_settings'],
    'group_analysis'   => ['reports'],
    'group_admin'      => ['employees', 'roles', 'branches', 'custom_fields', 'config'],
];

const NAVIGATION_ICONS = [
    'home'                    => 'bi-house',
    'sales'                   => 'bi-cart3',
    'customer_orders'         => 'bi-clipboard-check',
    'recurring_invoices'      => 'bi-arrow-repeat',
    'recurring_expenses'      => 'bi-calendar2-week',
    'purchase_requests'       => 'bi-card-checklist',
    'quotation_requests'      => 'bi-envelope-paper',
    'supplier_quotations'     => 'bi-file-earmark-text',
    'purchase_orders'         => 'bi-bag-check',
    'receivings'              => 'bi-box-arrow-in-down',
    'suppliers'               => 'bi-truck',
    'items'                   => 'bi-box-seam',
    'item_kits'               => 'bi-boxes',
    'attributes'              => 'bi-tags',
    'price_lists'             => 'bi-list-ol',
    'stock_documents'         => 'bi-clipboard-check',
    'customers'               => 'bi-people',
    'giftcards'               => 'bi-gift',
    'messages'                => 'bi-chat-dots',
    'expenses'                => 'bi-receipt',
    'expenses_categories'     => 'bi-folder2-open',
    'receipt_vouchers'        => 'bi-cash-stack',
    'revenue_categories'      => 'bi-folder-plus',
    'cashups'                 => 'bi-cash-coin',
    'taxes'                   => 'bi-percent',
    'accounting_chart'        => 'bi-diagram-3',
    'accounting_journals'     => 'bi-journal-text',
    'accounting_receivables'  => 'bi-person-lines-fill',
    'accounting_payables'     => 'bi-truck-flatbed',
    'accounting_cheques'      => 'bi-wallet2',
    'accounting_debit_notes'  => 'bi-file-earmark-plus',
    'accounting_reports'      => 'bi-file-earmark-bar-graph',
    'accounting_cost_centers' => 'bi-bullseye',
    'accounting_currencies'   => 'bi-currency-exchange',
    'accounting_periods'      => 'bi-calendar-range',
    'accounting_routing'      => 'bi-signpost-split',
    'accounting_settings'     => 'bi-toggles',
    'reports'                 => 'bi-bar-chart-line',
    'employees'               => 'bi-person-badge',
    'roles'                   => 'bi-shield-check',
    'branches'                => 'bi-building',
    'custom_fields'           => 'bi-input-cursor-text',
    'config'                  => 'bi-gear',
];

const NAVIGATION_FALLBACK_ICON = 'bi-grid';

/**
 * Arranges the modules the user may open into the sidebar groups.
 *
 * @param array  $allowedModules Rows of the modules table the user holds a grant for.
 * @param string $activeModule   Module id of the page being shown.
 *
 * @return array<int, array{label: string, items: array<int, array{id: string, label: string, url: string, icon: string, active: bool}>}>
 */
function navigationGroups(array $allowedModules, string $activeModule): array
{
    $itemsById = [];

    foreach ($allowedModules as $module) {
        $itemsById[$module->module_id] = [
            'id'     => $module->module_id,
            'label'  => lang("Module.{$module->module_id}"),
            'url'    => base_url($module->module_id),
            'icon'   => NAVIGATION_ICONS[$module->module_id] ?? NAVIGATION_FALLBACK_ICON,
            'active' => $module->module_id === $activeModule,
        ];
    }

    $groups = [];

    foreach (NAVIGATION_GROUPS as $labelKey => $moduleIds) {
        $items = [];

        foreach ($moduleIds as $moduleId) {
            if (isset($itemsById[$moduleId])) {
                $items[] = $itemsById[$moduleId];
                unset($itemsById[$moduleId]);
            }
        }
        $groups[$labelKey] = $items;
    }

    $groups['group_admin'] = array_merge($groups['group_admin'], array_values($itemsById));

    $result = [];

    foreach ($groups as $labelKey => $items) {
        if ($items !== []) {
            $result[] = ['label' => lang("Module.{$labelKey}"), 'items' => $items];
        }
    }

    return $result;
}

/**
 * @param string $segment First segment of the request path.
 *
 * @return string Module id the sidebar highlights.
 */
function navigationActiveModule(string $segment): string
{
    return $segment === '' ? 'home' : $segment;
}

/**
 * @param string $controllerName Module id the controller was constructed with.
 *
 * @return string Title of the page, empty when the controller is not a module.
 */
function navigationPageTitle(string $controllerName): string
{
    $key   = "Module.{$controllerName}";
    $title = lang($key);

    return $title === $key ? '' : $title;
}

/**
 * Sections of the settings page, grouped as the settings navigation shows them.
 *
 * @return list<array{0: string, 1: list<array{0: string, 1: string, 2: string, 3: string}>}> Group label key and, per section, its id, icon, label key and title key.
 */
function settingsSections(): array
{
    return [
        ['Config.settings_group_company', [
            ['info', 'bi-building', 'Config.info', 'Config.info_configuration'],
            ['general', 'bi-sliders', 'Config.general', 'Config.general_configuration'],
        ]],
        ['Config.settings_group_sales', [
            ['receipt', 'bi-receipt', 'Config.receipt', 'Config.receipt_configuration'],
            ['invoice', 'bi-file-earmark-text', 'Config.invoice', 'Config.invoice_configuration'],
            ['numbering', 'bi-123', 'Numbering.menu', 'Numbering.title'],
            ['shortcuts', 'bi-keyboard', 'Config.shortcuts', 'Config.shortcuts_configuration'],
        ]],
        ['Config.settings_group_stock', [
            ['stock', 'bi-boxes', 'Config.location', 'Config.location_configuration'],
            ['barcode', 'bi-upc-scan', 'Config.barcode', 'Config.barcode_configuration'],
        ]],
        ['Config.settings_group_regional', [
            ['locale', 'bi-translate', 'Config.locale', 'Config.locale_configuration'],
            ['tax', 'bi-percent', 'Config.tax', 'Config.tax_configuration'],
            ['reward', 'bi-gift', 'Config.reward', 'Config.reward_configuration'],
        ]],
        ['Config.settings_group_system', [
            ['table', 'bi-table', 'Config.table', 'Config.table_configuration'],
            ['system', 'bi-gear', 'Config.system_conf', 'Config.system_conf'],
        ]],
    ];
}

/**
 * Screens, reports and settings sections the quick search offers.
 *
 * @param array        $allowedModules      Rows of the modules table the user holds a grant for.
 * @param list<string> $reportPermissionIds Permission ids of the reports the user may open.
 *
 * @return list<array{type: string, label: string, group: string, url: string, icon: string}>
 */
function navigationSearchIndex(array $allowedModules, array $reportPermissionIds): array
{
    $entries   = [];
    $moduleIds = array_map(static fn (object $module): string => $module->module_id, $allowedModules);

    foreach (navigationGroups($allowedModules, '') as $group) {
        foreach ($group['items'] as $item) {
            $entries[] = ['type' => 'module', 'label' => $item['label'], 'group' => $group['label'], 'url' => $item['url'], 'icon' => $item['icon']];
        }
    }

    if (in_array('reports', $moduleIds, true)) {
        helper('report');

        foreach ([...reportCatalog(openableReportPermissions($reportPermissionIds)), ...operationalReportCatalog($reportPermissionIds), ...familyReportCatalog($reportPermissionIds)] as $reportGroup) {
            foreach ($reportGroup['subjects'] as $subject) {
                foreach ($subject['links'] as $link) {
                    $entries[] = ['type' => 'report', 'label' => $subject['label'] . ' — ' . $link['label'], 'group' => lang('Module.reports'), 'url' => $link['path'], 'icon' => $link['icon']];
                }
            }
        }
    }

    if (in_array('config', $moduleIds, true)) {
        foreach (settingsSections() as [, $items]) {
            foreach ($items as [$id, $icon, $label]) {
                $entries[] = ['type' => 'setting', 'label' => lang($label), 'group' => lang('Module.config'), 'url' => site_url('config') . "#{$id}_tab", 'icon' => $icon];
            }
        }
    }

    return $entries;
}
