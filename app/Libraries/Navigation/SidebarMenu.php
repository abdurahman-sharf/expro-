<?php

namespace App\Libraries\Navigation;

use App\Libraries\Organization\BranchGuard;

/**
 * The sidebar: eleven sections, each with the pages that exist for its job, arranged by what a person does with
 * them and not by the module that holds them. A section or page shows only to a person who can open what it
 * points at, by the very rule the page applies when it is asked for, so the menu never offers a page that answers
 * "no access". Nothing here grants or refuses anything: the controllers keep every check they had.
 */
final class SidebarMenu
{
    public const SETTINGS = 'settings';

    /**
     * The section the tile of a module on the start page belongs to, for the modules that appear in more than one.
     */
    private const TILE_SECTION = [
        'accounting_receivables' => 'customers',
        'accounting_reports'     => 'reports',
        'config'                 => self::SETTINGS,
        'integrations'           => self::SETTINGS,
        'reports'                => 'reports',
    ];

    /**
     * Each section: icon, the path prefixes that keep it open for a page the menu does not list by name, and its
     * groups. A page: id, the module whose grant opens it, its path, and optionally the permission it asks for as
     * well, the other modules it asks for, whether it answers "not found" to an employee who is limited to some
     * branches, whether it needs accounting to be on, a query string that makes it a view of another page's path, the
     * tab of a page that it opens (a fragment, which only the browser sees) and further paths that count as the page
     * itself.
     *
     * @return array<string, array{icon: string, also: list<string>, groups: list<array{label: ?string, items: list<array<string, mixed>>}>}>
     */
    public static function definition(): array
    {
        $page = static fn (string $id, string $module, string $path, string $label, array $more = []): array => ['id' => $id, 'module' => $module, 'path' => $path, 'label' => $label] + $more;

        return [
            'home' => ['icon' => 'bi-house', 'also' => [], 'groups' => [
                ['label' => null, 'items' => [
                    $page('home', 'home', 'home', 'Module.nav_dashboard', ['aliases' => ['']]),
                    $page('notifications', 'notifications', 'notifications', 'Module.notifications'),
                ]],
            ]],
            'sales' => ['icon' => 'bi-cart3', 'also' => [], 'groups' => [
                ['label' => null, 'items' => [
                    $page('sales_log', 'sales', 'sales/manage', 'Module.nav_sales_invoices', ['permission' => 'reports_sales']),
                    $page('sales', 'sales', 'sales', 'Module.nav_pos_new'),
                    $page('sales_documents', 'sales', 'sales/documents', 'Module.nav_sales_documents', ['permission' => 'reports_sales']),
                    $page('customer_orders', 'customer_orders', 'customer_orders', 'Module.customer_orders'),
                    $page('recurring_invoices', 'recurring_invoices', 'recurring_invoices', 'Module.recurring_invoices'),
                    $page('receivables_credit_notes', 'accounting_receivables', 'accounting_receivables/credit_notes', 'Module.nav_receivables_credit_notes', ['company' => true]),
                    $page('accounting_debit_notes', 'accounting_debit_notes', 'accounting_debit_notes', 'Module.nav_customer_debit_notes'),
                    $page('receivables_payments', 'accounting_receivables', 'accounting_receivables/payments', 'Module.nav_receivables_payments'),
                    $page('collected_invoices', 'accounting_reports', 'accounting_reports/net_position', 'Module.nav_collected_invoices', ['permission' => 'reports_sales', 'query' => 'status[]=paid&show=1']),
                    $page('returned_invoices', 'accounting_reports', 'accounting_reports/net_position', 'Module.nav_returned_invoices', ['permission' => 'reports_sales', 'query' => 'status[]=returned&status[]=partial_return&show=1']),
                    $page('giftcards', 'giftcards', 'giftcards', 'Module.giftcards'),
                ]],
            ]],
            'customers' => ['icon' => 'bi-people', 'also' => [], 'groups' => [
                ['label' => null, 'items' => [
                    $page('customers', 'customers', 'customers', 'Module.customers'),
                    $page('customer_classifications', 'customers', 'customer_classifications', 'Lookups.customer_classification_title'),
                    $page('accounting_receivables', 'accounting_receivables', 'accounting_receivables', 'Module.accounting_receivables', ['company' => true]),
                    $page('receivables_aged', 'accounting_receivables', 'accounting_receivables/aged_ledger', 'Module.nav_receivables_aged', ['company' => true]),
                    $page('messages', 'messages', 'messages', 'Module.messages'),
                ]],
            ]],
            'inventory' => ['icon' => 'bi-box-seam', 'also' => [], 'groups' => [
                ['label' => null, 'items' => [
                    $page('items', 'items', 'items', 'Module.items'),
                    $page('item_kits', 'item_kits', 'item_kits', 'Module.item_kits'),
                    $page('attributes', 'attributes', 'attributes', 'Module.nav_attributes'),
                    $page('item_brands', 'items', 'item_brands', 'Lookups.item_brand_title'),
                    $page('stock_units', 'stock_units', 'stock_units', 'Module.stock_units'),
                    $page('price_lists', 'price_lists', 'price_lists', 'Module.price_lists'),
                ]],
                ['label' => 'Module.nav_group_stock', 'items' => [
                    $page('stock_documents', 'stock_documents', 'stock_documents', 'Module.nav_stock_permits'),
                    $page('stocktakes', 'stock_documents', 'stock_documents/stocktakes', 'Module.nav_stocktakes'),
                    $page('stock_requests', 'stock_documents', 'stock_documents/requests', 'Module.nav_stock_requests'),
                    $page('warehouses', 'config', 'config', 'Config.location_configuration', ['fragment' => 'stock_tab']),
                ]],
            ]],
            'purchases' => ['icon' => 'bi-bag', 'also' => [], 'groups' => [
                ['label' => 'Module.nav_group_purchase_invoices', 'items' => [
                    $page('purchase_invoices', 'reports', 'purchases', 'Module.nav_purchase_invoices', ['permission' => 'reports_receivings']),
                    $page('receivings', 'receivings', 'receivings', 'Module.nav_purchase_new'),
                ]],
                ['label' => 'Module.nav_group_purchase_cycle', 'items' => [
                    $page('purchase_requests', 'purchase_requests', 'purchase_requests', 'Module.purchase_requests'),
                    $page('quotation_requests', 'quotation_requests', 'quotation_requests', 'Module.quotation_requests'),
                    $page('supplier_quotations', 'supplier_quotations', 'supplier_quotations', 'Module.supplier_quotations'),
                    $page('purchase_orders', 'purchase_orders', 'purchase_orders', 'Module.purchase_orders'),
                ]],
                ['label' => 'Module.nav_group_suppliers', 'items' => [
                    $page('suppliers', 'suppliers', 'suppliers', 'Module.suppliers'),
                    $page('accounting_payables', 'accounting_payables', 'accounting_payables', 'Module.accounting_payables', ['company' => true]),
                    $page('payables_aged', 'accounting_payables', 'accounting_payables/aged_ledger', 'Module.nav_payables_aged', ['company' => true]),
                    $page('payables_debit_notes', 'accounting_payables', 'accounting_payables/debit_notes', 'Module.nav_payables_debit_notes'),
                    $page('payables_payments', 'accounting_payables', 'accounting_payables/payments', 'Module.nav_payables_payments'),
                ]],
            ]],
            'finance' => ['icon' => 'bi-wallet2', 'also' => [], 'groups' => [
                ['label' => 'Module.nav_group_finance_flows', 'items' => [
                    $page('expenses', 'expenses', 'expenses', 'Module.nav_expenses_with_vouchers'),
                    $page('expenses_categories', 'expenses_categories', 'expenses_categories', 'Module.expenses_categories'),
                    $page('recurring_expenses', 'recurring_expenses', 'recurring_expenses', 'Module.recurring_expenses'),
                    $page('receipt_vouchers', 'receipt_vouchers', 'receipt_vouchers', 'Module.receipt_vouchers', ['accounting' => true]),
                    $page('revenue_categories', 'revenue_categories', 'revenue_categories', 'Module.revenue_categories', ['accounting' => true]),
                ]],
                ['label' => 'Module.nav_group_finance_treasury', 'items' => [
                    $page('accounting_treasuries', 'accounting_treasuries', 'accounting_treasuries', 'Module.nav_treasuries'),
                    $page('treasury_transfers', 'accounting_treasuries', 'accounting_treasuries/transfers', 'Module.nav_transfers'),
                    $page('accounting_cheques', 'accounting_cheques', 'accounting_cheques', 'Module.accounting_cheques'),
                    $page('bank_reconciliation', 'accounting_treasuries', 'accounting_treasuries/bank', 'Module.nav_bank_reconciliation', ['permission' => 'acc_bank_reconcile']),
                    $page('inter_branch', 'accounting_treasuries', 'accounting_treasuries/inter_branch', 'Module.nav_inter_branch'),
                    $page('cashups', 'cashups', 'cashups', 'Module.cashups'),
                ]],
            ]],
            'ledger' => ['icon' => 'bi-journal-bookmark', 'also' => [], 'groups' => [
                ['label' => 'Module.nav_group_books', 'items' => [
                    $page('accounting_overview', 'accounting_reports', 'accounting_reports/overview', 'Module.nav_overview'),
                    $page('accounting_chart', 'accounting_chart', 'accounting_chart', 'Module.nav_chart_tree'),
                    $page('journal_new', 'accounting_journals', 'accounting_journals/new', 'Accounting_journals.new', ['permission' => 'acc_journals_draft']),
                    $page('accounting_journals', 'accounting_journals', 'accounting_journals', 'Module.accounting_journals'),
                    $page('ledger', 'accounting_reports', 'accounting_reports/ledger', 'Module.nav_account_statement'),
                    $page('trial_balance', 'accounting_reports', 'accounting_reports/trial_balance', 'Module.nav_trial_balance', ['aliases' => ['accounting_reports']]),
                ]],
                ['label' => 'Module.nav_group_statements', 'items' => [
                    $page('balance_sheet', 'accounting_reports', 'accounting_statements/balance_sheet', 'Module.nav_balance_sheet'),
                    $page('income_statement', 'accounting_reports', 'accounting_statements/income_statement', 'Module.nav_income_statement'),
                    $page('cash_flow', 'accounting_reports', 'accounting_statements/cash_flow', 'Module.nav_cash_flow'),
                    $page('tax_report', 'accounting_reports', 'accounting_reports/tax', 'Module.nav_tax_report'),
                ]],
                ['label' => 'Module.nav_group_posting', 'items' => [
                    $page('post_journals', 'accounting_journals', 'accounting_journals', 'Module.nav_post_journals', ['permission' => 'acc_journals_post', 'query' => 'status[]=draft']),
                    $page('backfill', 'accounting_periods', 'accounting_periods/backfill', 'Module.nav_backfill', ['permission' => 'acc_backfill_manage']),
                    $page('accounting_periods', 'accounting_periods', 'accounting_periods', 'Module.nav_fiscal_periods'),
                    $page('closings', 'accounting_periods', 'accounting_periods/closings', 'Module.nav_close_periods'),
                    $page('year_end', 'accounting_periods', 'accounting_periods/year_end', 'Module.nav_year_end', ['permission' => 'acc_year_end_close']),
                    $page('opening', 'accounting_periods', 'accounting_periods/opening', 'Module.nav_opening_balances', ['permission' => 'acc_opening_manage']),
                    $page('accounting_assets', 'accounting_assets', 'accounting_assets', 'Module.accounting_assets'),
                ]],
                ['label' => 'Module.nav_group_accounting_settings', 'items' => [
                    $page('accounting_cost_centers', 'accounting_cost_centers', 'accounting_cost_centers', 'Module.accounting_cost_centers'),
                    $page('accounting_currencies', 'accounting_currencies', 'accounting_currencies', 'Module.accounting_currencies'),
                    $page('accounting_ai', 'accounting_ai', 'accounting_ai', 'Module.accounting_ai'),
                    $page('accounting_settings', 'accounting_settings', 'accounting_settings', 'Module.accounting_settings'),
                    $page('accounting_routing', 'accounting_routing', 'accounting_routing', 'Module.accounting_routing'),
                    $page('accounting_einvoicing', 'accounting_einvoicing', 'accounting_einvoicing', 'Module.accounting_einvoicing'),
                ]],
            ]],
            'employees' => ['icon' => 'bi-person-badge', 'also' => [], 'groups' => [
                ['label' => null, 'items' => [
                    $page('employees', 'employees', 'employees', 'Module.employees'),
                    $page('roles', 'roles', 'roles', 'Module.roles'),
                ]],
            ]],
            'reports' => ['icon' => 'bi-bar-chart-line', 'also' => ['accounting_reports', 'accounting_statements'], 'groups' => [
                ['label' => null, 'items' => [
                    $page('reports', 'reports', 'reports', 'Module.nav_reports_hub'),
                    $page('activity_log', 'accounting_journals', 'accounting_journals/audit', 'Accounting_journals.audit_title', ['permission' => 'acc_journals_audit', 'aliases' => ['accounting_journals/failures']]),
                ]],
            ]],
            'templates' => ['icon' => 'bi-file-earmark-text', 'also' => [], 'groups' => [
                ['label' => null, 'items' => [
                    $page('receipt_templates', 'config', 'config', 'Config.receipt_configuration', ['fragment' => 'receipt_tab']),
                    $page('invoice_templates', 'config', 'config', 'Config.invoice_configuration', ['fragment' => 'invoice_tab']),
                    $page('barcode_labels', 'config', 'config', 'Config.barcode_configuration', ['fragment' => 'barcode_tab']),
                    $page('notification_rules', 'integrations', 'integrations/rules', 'Integrations.notification_rules', ['permission' => 'acc_integrations_manage', 'also' => ['config']]),
                ]],
            ]],
            self::SETTINGS => ['icon' => 'bi-gear', 'also' => [], 'groups' => [
                ['label' => null, 'items' => [
                    $page('config', 'config', 'config', 'Module.config'),
                    $page('branches', 'branches', 'branches', 'Module.branches'),
                    $page('taxes', 'taxes', 'taxes', 'Module.taxes', ['aliases' => ['tax_definitions']]),
                    $page('payment_methods', 'integrations', 'integrations/payment_methods', 'Integrations.payment_methods', ['permission' => 'acc_integrations_manage', 'also' => ['config']]),
                    $page('api_keys', 'integrations', 'api_keys', 'Integrations.api_keys', ['permission' => 'api_keys_manage', 'company' => true, 'also' => ['config']]),
                    $page('integrations', 'integrations', 'integrations', 'Module.integrations', ['also' => ['config']]),
                    $page('custom_fields', 'custom_fields', 'custom_fields', 'Module.custom_fields'),
                ]],
            ]],
        ];
    }

    /**
     * A page of the menu by its id, with the rule that decides who may open it.
     *
     * @return array<string, mixed>|null
     */
    public static function page(string $id): ?array
    {
        foreach (self::definition() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $page) {
                    if ($page['id'] === $id) {
                        return $page;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Every module the menu names, in the order of the sections.
     *
     * @return list<string>
     */
    public static function modules(): array
    {
        $modules = [];

        foreach (self::definition() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $modules[$item['module']] = true;
                }
            }
        }

        return array_keys($modules);
    }

    /**
     * The modules whose first page answers "not found" to an employee limited to some branches, so that the tile of the
     * start page and the quick search do not offer them to one.
     *
     * @return list<string>
     */
    public static function companyModules(): array
    {
        $modules = [];

        foreach (self::definition() as $section) {
            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    if (($item['company'] ?? false) && $item['path'] === $item['module']) {
                        $modules[] = $item['module'];
                    }
                }
            }
        }

        return $modules;
    }

    /**
     * The module ids that open one tile each on the start page, by section, in the order of the menu. A module takes
     * its tile in the section of its first page, unless TILE_SECTION names another for it: then it takes it at its
     * first page in that section, or at the end of that section when the section has none of its pages.
     *
     * @return array<string, list<string>>
     */
    public static function tilesBySection(): array
    {
        $tiles    = [];
        $deferred = [];
        $seen     = [];

        foreach (self::definition() as $sectionKey => $section) {
            $tiles[$sectionKey] ??= [];

            foreach ($section['groups'] as $group) {
                foreach ($group['items'] as $item) {
                    $module = $item['module'];

                    if (isset($seen[$module])) {
                        continue;
                    }

                    $home = self::TILE_SECTION[$module] ?? $sectionKey;

                    if ($home !== $sectionKey) {
                        $deferred[$home][$module] = true;

                        continue;
                    }

                    $seen[$module]        = true;
                    $tiles[$sectionKey][] = $module;

                    unset($deferred[$home][$module]);
                }
            }
        }

        foreach ($deferred as $home => $modules) {
            $tiles[$home] = [...$tiles[$home], ...array_keys($modules)];
        }

        return $tiles;
    }

    /**
     * Whether a person holds a module the way the module's own screens ask for it: the grant of the module and,
     * for a module that has permissions of its own under its name, at least one of them besides. This is the rule of
     * Employee::has_module_grant, worked out from lists read once for the whole menu.
     *
     * @param list<string>                $held        Lower-case permission ids the person holds, their own and their roles'.
     * @param array<string, list<string>> $permissions Permission ids of each module that start with the module's id and an underscore.
     */
    public static function holdsModule(string $moduleId, array $held, array $permissions): bool
    {
        $own      = array_map('strtolower', $permissions[$moduleId] ?? []);
        $inModule = array_intersect($held, [strtolower($moduleId), ...$own]);

        return in_array(strtolower($moduleId), $inModule, true) && (count($inModule) > 1 || $own === []);
    }

    /**
     * Whether a person may open a page: the module it belongs to is among their modules and they hold it the way its
     * screens ask for it, they hold the permission the page asks for as well, work in the whole company when the page
     * needs that, and hold the other modules it asks for. The sidebar and the shortcuts of the start page both ask
     * this, so that the two never disagree.
     *
     * @param array<string, mixed>        $item           A page: module, and optionally permission, company, accounting and also.
     * @param list<string>                $allowedModules Ids of the modules the person holds a grant for, as the module list gives them.
     * @param list<string>                $held           Lower-case permission ids the person holds, their own and their roles'.
     * @param array<string, list<string>> $permissions    See holdsModule().
     * @param bool                        $accountingOn   Whether accounting is on, for the pages that answer "not found" while it is off.
     */
    public static function opens(array $item, array $allowedModules, array $held, array $permissions, bool $accountingOn = true): bool
    {
        $holds = static fn (string $module): bool => in_array($module, $allowedModules, true) && self::holdsModule($module, $held, $permissions);

        if (! $holds($item['module'])) {
            return false;
        }

        if (isset($item['permission']) && ! in_array(strtolower($item['permission']), $held, true)) {
            return false;
        }

        if (($item['company'] ?? false) && ! in_array(BranchGuard::ALL_BRANCHES, $held, true)) {
            return false;
        }

        if (($item['accounting'] ?? false) && ! $accountingOn) {
            return false;
        }

        foreach ($item['also'] ?? [] as $other) {
            if (! $holds($other)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string>                $allowedModules Ids of the modules the person holds a grant for, as the module list gives them.
     * @param list<string>                $held           Lower-case permission ids the person holds, their own and their roles'.
     * @param array<string, list<string>> $permissions    See holdsModule().
     * @param string                      $query          Query string of the page being shown, for the pages that are a view of another page's path.
     * @param bool                        $accountingOn   See opens().
     *
     * @return list<array{key: string, label: string, icon: string, active: bool, direct: bool, groups: list<array{label: ?string, items: list<array{id: string, label: string, url: string, fragment: ?string, active: bool}>}>}>
     */
    public static function sections(array $allowedModules, array $held, array $permissions, string $path, string $query = '', bool $accountingOn = true): array
    {
        $definition = self::definition();
        $path       = self::normalize($path);
        $known      = self::modules();
        $opens      = static fn (string $module): bool => in_array($module, $allowedModules, true) && self::holdsModule($module, $held, $permissions);
        $visible    = [];

        foreach ($definition as $sectionKey => $section) {
            foreach ($section['groups'] as $groupIndex => $group) {
                foreach ($group['items'] as $item) {
                    if (self::opens($item, $allowedModules, $held, $permissions, $accountingOn)) {
                        $visible[$sectionKey][$groupIndex][] = $item;
                    }
                }
            }
        }

        foreach ($allowedModules as $moduleId) {
            if (! in_array($moduleId, $known, true) && $opens($moduleId)) {
                $last                                 = array_key_last($definition[self::SETTINGS]['groups']);
                $visible[self::SETTINGS][$last + 1][] = ['id' => $moduleId, 'module' => $moduleId, 'path' => $moduleId, 'label' => "Module.{$moduleId}"];
            }
        }

        $activeId = self::activeItem($visible, $path, $query);
        $sections = [];

        foreach ($definition as $sectionKey => $section) {
            if (! isset($visible[$sectionKey])) {
                continue;
            }

            $groups = [];

            foreach ($visible[$sectionKey] as $groupIndex => $items) {
                $label = $section['groups'][$groupIndex]['label'] ?? null;

                $groups[] = [
                    'label' => $label === null ? null : lang($label),
                    'items' => array_map(static fn (array $item): array => [
                        'id'       => $item['id'],
                        'label'    => lang($item['label']),
                        'url'      => base_url($item['path']) . (isset($item['query']) ? '?' . $item['query'] : '') . (isset($item['fragment']) ? '#' . $item['fragment'] : ''),
                        'fragment' => $item['fragment'] ?? null,
                        'active'   => $item['id'] === $activeId,
                    ], $items),
                ];
            }

            $items  = array_merge(...array_column($groups, 'items'));
            $active = $activeId !== null && in_array($activeId, array_column($items, 'id'), true);

            if (! $active && $activeId === null) {
                $active = self::keepsOpen($section['also'], $path);
            }

            $sections[] = [
                'key'    => $sectionKey,
                'label'  => lang("Module.nav_section_{$sectionKey}"),
                'icon'   => $section['icon'],
                'active' => $active,
                'direct' => count($items) === 1,
                'groups' => $groups,
            ];
        }

        return $sections;
    }

    /**
     * @param array<string, array<int, list<array<string, mixed>>>> $visible
     */
    private static function activeItem(array $visible, string $path, string $query): ?string
    {
        $best    = null;
        $bestLen = -1;

        foreach ($visible as $groups) {
            foreach ($groups as $items) {
                foreach ($items as $item) {
                    $length = self::matchLength($item, $path, $query);

                    if ($length > $bestLen) {
                        $best    = $item['id'];
                        $bestLen = $length;
                    }
                }
            }
        }

        return $best;
    }

    /**
     * How much of the path the page accounts for, or -1 when it is not this page. A page that is a view of another
     * page's path counts one more when the query string asks for that view, so it wins over the page it views. A tab
     * of a page is never chosen here, because the server does not see the fragment: the page it belongs to is, and the
     * browser then marks the tab.
     *
     * @param array<string, mixed> $item
     */
    private static function matchLength(array $item, string $path, string $query): int
    {
        if (isset($item['fragment'])) {
            return -1;
        }

        if (in_array($path, $item['aliases'] ?? [], true)) {
            return strlen($path);
        }

        if ($item['path'] !== $path && ! str_starts_with($path, $item['path'] . '/')) {
            return -1;
        }

        if (! isset($item['query'])) {
            return strlen($item['path']);
        }

        return self::queryHolds($query, $item['query']) ? strlen($item['path']) + 1 : -1;
    }

    private static function queryHolds(string $query, string $wanted): bool
    {
        parse_str($query, $given);
        parse_str($wanted, $required);

        foreach ($required as $key => $value) {
            if (! isset($given[$key]) || (is_array($value) ? array_diff($value, (array) $given[$key]) !== [] : $given[$key] !== $value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $prefixes
     */
    private static function keepsOpen(array $prefixes, string $path): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $path): string
    {
        return trim(strtolower(parse_url($path, PHP_URL_PATH) ?: ''), '/');
    }
}
