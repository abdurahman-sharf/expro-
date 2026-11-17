<?php

namespace App\Libraries\Navigation;

/**
 * The shortcuts of the start page. Each opens a page or a dialog the sidebar or the screens already have, and is
 * offered to a person by the rule the sidebar applies to a page (SidebarMenu::opens), so a shortcut never leads where
 * the menu would not.
 */
final class QuickActions
{
    public const MAX_PINNED = 12;
    public const PAGE       = 'page';
    public const DIALOG     = 'dialog';
    public const QUOTE      = 'quote';

    /**
     * Each shortcut: its kind (a page, a dialog that opens over the start page, or the quote mode of the register),
     * the address it opens, the label, an icon and a colour for it, the rule of the page it opens (module, and
     * optionally permission, company, accounting), whether it needs the invoice mode, and its place among the twelve
     * that are pinned until the person chooses their own.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalog(): array
    {
        $action = static fn (string $id, string $kind, string $url, string $label, string $icon, string $tone, string $module, array $more = []): array => ['id' => $id, 'kind' => $kind, 'url' => $url, 'label' => $label, 'icon' => $icon, 'tone' => $tone, 'module' => $module] + $more;

        $actions = [
            $action('pos', self::PAGE, 'sales', 'Module.nav_pos', 'bi-cart3', 'blue', 'sales', ['default' => 1]),
            $action('add_purchase', self::PAGE, 'receivings', 'Common.shortcut_add_purchase', 'bi-box-arrow-in-down', 'indigo', 'receivings', ['default' => 2]),
            $action('add_item', self::DIALOG, 'items/view/-1', 'Common.shortcut_add_item', 'bi-box-seam', 'green', 'items', ['default' => 3]),
            $action('add_supplier', self::DIALOG, 'suppliers/view/-1', 'Common.shortcut_add_supplier', 'bi-truck', 'blue', 'suppliers', ['default' => 4]),
            $action('add_customer', self::DIALOG, 'customers/view/-1', 'Common.shortcut_add_customer', 'bi-person-plus', 'teal', 'customers', ['default' => 5]),
            $action('add_quote', self::QUOTE, 'sales', 'Common.shortcut_add_quote', 'bi-file-earmark-text', 'green', 'sales', ['default' => 6, 'invoice' => true]),
            $action('add_expense', self::DIALOG, 'expenses/view/-1', 'Common.shortcut_add_expense', 'bi-receipt', 'purple', 'expenses', ['default' => 7]),
            $action('add_user', self::DIALOG, 'employees/view/-1', 'Common.shortcut_add_user', 'bi-person-badge', 'orange', 'employees', ['default' => 8]),
            $action('profit_report', self::PAGE, 'accounting_statements/income_statement', 'Common.shortcut_profit_report', 'bi-graph-up-arrow', 'purple', 'accounting_reports', ['default' => 9, 'accounting' => true]),
            $action('stock_report', self::PAGE, 'reports/inventory_summary', 'Common.shortcut_stock_report', 'bi-clipboard-data', 'teal', 'reports', ['default' => 10, 'permission' => 'reports_inventory']),
            $action('add_receipt_voucher', self::DIALOG, 'receipt_vouchers/view', 'Common.shortcut_add_receipt_voucher', 'bi-cash-stack', 'green', 'receipt_vouchers', ['default' => 11, 'accounting' => true]),
            $action('add_journal', self::PAGE, 'accounting_journals/new', 'Common.shortcut_add_journal', 'bi-journal-plus', 'indigo', 'accounting_journals', ['default' => 12, 'permission' => 'acc_journals_draft', 'accounting' => true]),
            $action('sales_invoices', self::PAGE, 'sales/manage', 'Module.nav_sales_invoices', 'bi-card-list', 'blue', 'sales', ['permission' => 'reports_sales']),
            $action('drafts_quotes', self::PAGE, 'sales/documents', 'Module.nav_sales_documents', 'bi-file-earmark-ruled', 'orange', 'sales', ['permission' => 'reports_sales']),
            $action('purchase_invoices', self::PAGE, 'purchases', 'Module.nav_purchase_invoices', 'bi-journal-text', 'indigo', 'reports', ['permission' => 'reports_receivings']),
            $action('customer_receipts', self::PAGE, 'accounting_receivables/payments', 'Module.nav_receivables_payments', 'bi-cash-coin', 'green', 'accounting_receivables', ['accounting' => true]),
            $action('supplier_payments', self::PAGE, 'accounting_payables/payments', 'Module.nav_payables_payments', 'bi-wallet2', 'purple', 'accounting_payables', ['accounting' => true]),
            $action('cashups', self::PAGE, 'cashups', 'Module.cashups', 'bi-cash-coin', 'teal', 'cashups'),
            $action('chart_tree', self::PAGE, 'accounting_chart', 'Module.nav_chart_tree', 'bi-diagram-3', 'indigo', 'accounting_chart', ['accounting' => true]),
        ];

        return array_column($actions, null, 'id');
    }

    /**
     * The shortcuts a person may use, in the order of the catalog.
     *
     * @param list<string>                $allowedModules Ids of the modules the person holds a grant for, as the module list gives them.
     * @param list<string>                $held           Lower-case permission ids the person holds, their own and their roles'.
     * @param array<string, list<string>> $permissions    See SidebarMenu::holdsModule().
     *
     * @return list<array<string, mixed>>
     */
    public static function visible(array $allowedModules, array $held, array $permissions, bool $accountingOn, bool $invoiceOn): array
    {
        $visible = [];

        foreach (self::catalog() as $action) {
            if (($action['invoice'] ?? false) && ! $invoiceOn) {
                continue;
            }

            if (SidebarMenu::opens($action, $allowedModules, $held, $permissions, $accountingOn)) {
                $visible[] = $action;
            }
        }

        return $visible;
    }

    /**
     * The address a shortcut of the support kind opens.
     */
    public static function supportUrl(string $message): string
    {
        return 'https://wa.me/' . SUPPORT_WHATSAPP . '?text=' . rawurlencode($message);
    }
}
