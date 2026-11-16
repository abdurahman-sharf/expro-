<?php

use App\Libraries\Navigation\SidebarMenu;
use App\Libraries\Organization\BranchGuard;
use App\Models\Employee;
use Config\Database;

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
    'stock_units'             => 'bi-rulers',
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
    'accounting_assets'       => 'bi-building-gear',
    'accounting_debit_notes'  => 'bi-file-earmark-plus',
    'accounting_reports'      => 'bi-file-earmark-bar-graph',
    'accounting_cost_centers' => 'bi-bullseye',
    'accounting_currencies'   => 'bi-currency-exchange',
    'accounting_periods'      => 'bi-calendar-range',
    'accounting_routing'      => 'bi-signpost-split',
    'accounting_einvoicing'   => 'bi-qr-code',
    'accounting_ai'           => 'bi-robot',
    'accounting_settings'     => 'bi-toggles',
    'reports'                 => 'bi-bar-chart-line',
    'employees'               => 'bi-person-badge',
    'roles'                   => 'bi-shield-check',
    'branches'                => 'bi-building',
    'custom_fields'           => 'bi-input-cursor-text',
    'integrations'            => 'bi-plug',
    'notifications'           => 'bi-bell',
    'config'                  => 'bi-gear',
];

const NAVIGATION_FALLBACK_ICON = 'bi-grid';

/**
 * Arranges the modules the user holds a grant for into the sections of the menu, one entry per module: the tiles of
 * the start page and the quick search read it. The sidebar itself is navigationSections(). A module whose first page
 * needs the whole company is left out for a person who works in some branches only, as the page would refuse them.
 *
 * @param array    $allowedModules Rows of the modules table the user holds a grant for.
 * @param string   $activeModule   Module id of the page being shown.
 * @param int|null $personId       Person whose branches decide, the one signed in when null.
 *
 * @return array<int, array{label: string, items: array<int, array{id: string, label: string, url: string, icon: string, active: bool}>}>
 */
function navigationGroups(array $allowedModules, string $activeModule, ?int $personId = null): array
{
    $personId ??= (int) session('person_id');
    $withheld  = (new BranchGuard())->isUnrestricted($personId) ? [] : SidebarMenu::companyModules();
    $itemsById = [];

    foreach ($allowedModules as $module) {
        if (in_array($module->module_id, $withheld, true)) {
            continue;
        }

        $itemsById[$module->module_id] = [
            'id'     => $module->module_id,
            'label'  => lang("Module.{$module->module_id}"),
            'url'    => base_url($module->module_id),
            'icon'   => NAVIGATION_ICONS[$module->module_id] ?? NAVIGATION_FALLBACK_ICON,
            'active' => $module->module_id === $activeModule,
        ];
    }

    $groups = [];

    foreach (SidebarMenu::tilesBySection() as $sectionKey => $moduleIds) {
        $items = [];

        foreach ($moduleIds as $moduleId) {
            if (isset($itemsById[$moduleId])) {
                $items[] = $itemsById[$moduleId];
                unset($itemsById[$moduleId]);
            }
        }
        $groups[$sectionKey] = $items;
    }

    $groups[SidebarMenu::SETTINGS] = array_merge($groups[SidebarMenu::SETTINGS], array_values($itemsById));

    $result = [];

    foreach ($groups as $sectionKey => $items) {
        if ($items !== []) {
            $result[] = ['label' => lang("Module.nav_section_{$sectionKey}"), 'items' => $items];
        }
    }

    return $result;
}

/**
 * The sections of the sidebar for the person signed in: only the pages they can open, only the sections that
 * keep at least one, the section of the page being shown marked open and the page itself marked current.
 *
 * @param array    $allowedModules Rows of the modules table the user holds a grant for.
 * @param string   $path           Path of the page being shown, relative to the site.
 * @param int|null $personId       Person whose grants decide, the one signed in when null.
 * @param string   $query          Query string of the page being shown.
 *
 * @return list<array{key: string, label: string, icon: string, active: bool, groups: list<array{label: ?string, items: list<array{id: string, label: string, url: string, active: bool}>}>}>
 */
function navigationSections(array $allowedModules, string $path, ?int $personId = null, string $query = ''): array
{
    $personId ??= (int) session('person_id');
    $held        = array_map('strtolower', array_column(model(Employee::class)->get_employee_grants($personId), 'permission_id'));
    $permissions = [];

    foreach (Database::connect()->table('permissions')->select('module_id, permission_id')->get()->getResultArray() as $row) {
        if (str_starts_with(strtolower($row['permission_id']), strtolower($row['module_id']) . '_')) {
            $permissions[$row['module_id']][] = $row['permission_id'];
        }
    }

    return SidebarMenu::sections(array_map(static fn (object $module): string => $module->module_id, $allowedModules), $held, $permissions, $path, $query);
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
 * @param int|null     $personId            Person whose branches decide, the one signed in when null.
 *
 * @return list<array{type: string, label: string, group: string, url: string, icon: string}>
 */
function navigationSearchIndex(array $allowedModules, array $reportPermissionIds, ?int $personId = null): array
{
    $entries   = [];
    $moduleIds = array_map(static fn (object $module): string => $module->module_id, $allowedModules);

    foreach (navigationGroups($allowedModules, '', $personId) as $group) {
        foreach ($group['items'] as $item) {
            $entries[] = ['type' => 'module', 'label' => $item['label'], 'group' => $group['label'], 'url' => $item['url'], 'icon' => $item['icon']];
        }
    }

    if (in_array('reports', $moduleIds, true)) {
        helper('report');

        foreach (allReportCatalogs($reportPermissionIds) as $reportGroup) {
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
