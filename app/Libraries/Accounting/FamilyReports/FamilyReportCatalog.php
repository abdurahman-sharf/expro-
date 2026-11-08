<?php

namespace App\Libraries\Accounting\FamilyReports;

/**
 * The reports of the families of purchases, suppliers, stock, expenses and income, and the books: the
 * address each is opened at, the permission that opens it besides the module of the financial
 * reports, the family it is listed under and the filters its screen offers. A filter is a day, a text,
 * a number, a flag, a choice among a few words, or a choice among records of the books.
 */
final class FamilyReportCatalog
{
    /**
     * @return array<string, array{class: class-string<FamilyReport>, permission: string, family: string, filters: list<array<string, mixed>>}>
     */
    public static function all(): array
    {
        $group = static fn (array $values): array => ['name' => 'group', 'type' => 'select', 'options' => $values];
        $view  = static fn (array $values = ['summary', 'detail']): array => ['name' => 'view', 'type' => 'select', 'options' => $values];
        $range = static fn (): array => [['name' => 'from', 'type' => 'date'], ['name' => 'to', 'type' => 'date']];
        $day   = static fn (): array => [['name' => 'to', 'type' => 'date']];
        $pick  = static fn (string $name, string $source): array => ['name' => $name, 'type' => 'choice', 'source' => $source];

        return [
            'purchases' => ['class' => PurchasesReport::class, 'permission' => 'reports_receivings', 'family' => 'purchases', 'filters' => [
                $group(PurchasesReport::GROUPS), $view(), ['name' => 'status', 'type' => 'select', 'options' => PurchasesReport::STATUSES], ...$range(), $pick('supplier_id', 'suppliers'), $pick('employee_id', 'employees'),
            ]],
            'supplier_purchases' => ['class' => SupplierPurchasesReport::class, 'permission' => 'reports_receivings', 'family' => 'purchases', 'filters' => [
                ...$range(), $pick('supplier_id', 'suppliers'), $pick('employee_id', 'employees'),
            ]],
            'product_purchases' => ['class' => ProductPurchasesReport::class, 'permission' => 'reports_receivings', 'family' => 'purchases', 'filters' => [
                $group(ProductPurchasesReport::GROUPS), $view(), ...$range(), $pick('supplier_id', 'suppliers'), $pick('employee_id', 'employees'), $pick('category', 'item_categories'),
            ]],
            'supplier_payments' => ['class' => SupplierPaymentsReport::class, 'permission' => 'reports_suppliers', 'family' => 'purchases', 'filters' => [
                $group(SupplierPaymentsReport::GROUPS), $view(), ...$range(), $pick('supplier_id', 'suppliers'), $pick('employee_id', 'employees'), $pick('payment_type', 'payment_types'),
            ]],
            'purchase_payments' => ['class' => PurchasePaymentsReport::class, 'permission' => 'reports_suppliers', 'family' => 'purchases', 'filters' => [
                ...$range(), $pick('supplier_id', 'suppliers'), $pick('employee_id', 'employees'),
            ]],
            'supplier_directory' => ['class' => SupplierDirectoryReport::class, 'permission' => 'reports_suppliers', 'family' => 'suppliers', 'filters' => [
                ['name' => 'city', 'type' => 'text'], ['name' => 'country', 'type' => 'text'],
            ]],
            'supplier_aged_ledger' => ['class' => SupplierAgedLedgerReport::class, 'permission' => 'reports_suppliers', 'family' => 'suppliers', 'filters' => [
                ...$day(), ['name' => 'period', 'type' => 'number'], $pick('supplier_id', 'suppliers'),
            ]],
            'supplier_balances' => ['class' => SupplierBalancesReport::class, 'permission' => 'reports_suppliers', 'family' => 'suppliers', 'filters' => [
                ...$range(), ['name' => 'empty', 'type' => 'flag'],
            ]],
            'stock_count_sheet' => ['class' => StockCountSheetReport::class, 'permission' => 'reports_inventory', 'family' => 'inventory', 'filters' => [
                $pick('location_id', 'locations'), $pick('category', 'item_categories'), ['name' => 'group', 'type' => 'select', 'options' => ['none', 'category']],
                ['name' => 'order', 'type' => 'select', 'options' => ['name', 'quantity_asc', 'quantity_desc']], ['name' => 'all', 'type' => 'flag'], ['name' => 'hide_zero', 'type' => 'flag'],
            ]],
            'stock_operations' => ['class' => StockOperationsReport::class, 'permission' => 'reports_inventory', 'family' => 'inventory', 'filters' => [
                ...$range(), $pick('location_id', 'locations'), $pick('category', 'item_categories'),
            ]],
            'stock_movements' => ['class' => StockMovementsReport::class, 'permission' => 'reports_inventory', 'family' => 'inventory', 'filters' => [
                $view(['plain', 'valued']), ...$range(), $pick('location_id', 'locations'), $pick('category', 'item_categories'),
            ]],
            'stock_balance' => ['class' => StockBalanceReport::class, 'permission' => 'reports_inventory', 'family' => 'inventory', 'filters' => [
                ['name' => 'search', 'type' => 'text'], $pick('location_id', 'locations'), $pick('category', 'item_categories'), ['name' => 'status', 'type' => 'select', 'options' => StockBalanceReport::STATUSES],
            ]],
            'product_balance' => ['class' => ProductBalanceReport::class, 'permission' => 'reports_inventory', 'family' => 'inventory', 'filters' => [
                ...$range(), $pick('location_id', 'locations'), $pick('category', 'item_categories'), ['name' => 'empty', 'type' => 'flag'],
            ]],
            'stock_aging' => ['class' => StockAgingReport::class, 'permission' => 'reports_inventory', 'family' => 'inventory', 'filters' => [
                ['name' => 'search', 'type' => 'text'], ...$day(), $pick('location_id', 'locations'), $pick('category', 'item_categories'), ['name' => 'split', 'type' => 'flag'],
            ]],
            'stock_turnover' => ['class' => StockTurnoverReport::class, 'permission' => 'reports_inventory', 'family' => 'inventory', 'filters' => [
                ...$range(), ['name' => 'group', 'type' => 'select', 'options' => ['item', 'category']], $pick('location_id', 'locations'), $pick('category', 'item_categories'),
            ]],
            'expenses' => ['class' => ExpensesReport::class, 'permission' => 'reports_expenses_categories', 'family' => 'cash', 'filters' => [
                $group(['category', 'user', 'vendor', 'treasury', 'day', 'week', 'month', 'year']), $view(), ...$range(), $pick('treasury_id', 'treasuries'), $pick('category_id', 'expense_categories'), $pick('employee_id', 'employees'), ['name' => 'vendor', 'type' => 'text'],
            ]],
            'income' => ['class' => IncomeReport::class, 'permission' => 'receipt_vouchers', 'family' => 'cash', 'filters' => [
                $group(['category', 'user', 'treasury', 'day', 'week', 'month', 'year']), $view(), ...$range(), $pick('treasury_id', 'treasuries'), $pick('category_id', 'revenue_categories'), $pick('employee_id', 'employees'),
            ]],
            'journal_movements' => ['class' => JournalMovementsReport::class, 'permission' => 'accounting_journals', 'family' => 'books', 'filters' => [
                $group(JournalMovementsReport::GROUPS), ...$range(), $pick('source', 'sources'), $pick('account_id', 'accounts'), $pick('cost_center_id', 'cost_centers'), ['name' => 'without_cost_center', 'type' => 'flag'],
            ]],
            'cost_centers' => ['class' => CostCenterReport::class, 'permission' => 'accounting_cost_centers', 'family' => 'books', 'filters' => [
                $group(CostCenterReport::GROUPS), ...$range(), $pick('cost_center_id', 'cost_centers'), $pick('account_id', 'accounts'), ['name' => 'without_cost_center', 'type' => 'flag'],
            ]],
            'journals' => ['class' => JournalsReport::class, 'permission' => 'accounting_journals', 'family' => 'books', 'filters' => [
                ...$range(), $pick('source', 'sources'), ['name' => 'search', 'type' => 'text'],
            ]],
            'chart' => ['class' => ChartReport::class, 'permission' => 'accounting_chart', 'family' => 'books', 'filters' => [
                ['name' => 'level', 'type' => 'select', 'options' => ['all', 'main', 'sub']], ['name' => 'nature', 'type' => 'select', 'options' => ['all', 'debit', 'credit']],
                ['name' => 'exclude', 'type' => 'select', 'options' => ['none', 'customers', 'suppliers', 'both']], ['name' => 'order', 'type' => 'select', 'options' => ['asc', 'desc']],
                ['name' => 'balances', 'type' => 'flag'], ...$day(),
            ]],
            'financial_movements' => ['class' => FinancialMovementsReport::class, 'permission' => 'accounting_treasuries', 'family' => 'books', 'filters' => [
                ...$range(),
            ]],
        ];
    }

    /**
     * @return array{class: class-string<FamilyReport>, permission: string, family: string, filters: list<array<string, mixed>>}|null
     */
    public static function find(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function titleKey(string $key): string
    {
        return match ($key) {
            'stock_count_sheet' => 'stock_count_title',
            'expenses'          => 'expenses_title',
            'income'            => 'income_title',
            'journals'          => 'journals_title',
            'chart'             => 'chart_title',
            default             => $key . '_title',
        };
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
