<?php

const NAVIGATION_GROUPS = [
    'group_main'      => ['home'],
    'group_sales'     => ['sales'],
    'group_purchases' => ['receivings', 'suppliers'],
    'group_inventory' => ['items', 'item_kits', 'attributes'],
    'group_customers' => ['customers', 'giftcards', 'messages'],
    'group_finance'   => ['expenses', 'expenses_categories', 'cashups', 'taxes'],
    'group_analysis'  => ['reports'],
    'group_admin'     => ['employees', 'config'],
];

const NAVIGATION_ICONS = [
    'home'                => 'bi-house',
    'sales'               => 'bi-cart3',
    'receivings'          => 'bi-box-arrow-in-down',
    'suppliers'           => 'bi-truck',
    'items'               => 'bi-box-seam',
    'item_kits'           => 'bi-boxes',
    'attributes'          => 'bi-tags',
    'customers'           => 'bi-people',
    'giftcards'           => 'bi-gift',
    'messages'            => 'bi-chat-dots',
    'expenses'            => 'bi-receipt',
    'expenses_categories' => 'bi-folder2-open',
    'cashups'             => 'bi-cash-coin',
    'taxes'               => 'bi-percent',
    'reports'             => 'bi-bar-chart-line',
    'employees'           => 'bi-person-badge',
    'config'              => 'bi-gear',
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
