#!/usr/bin/env python3
"""Dev only: markdown tables of the selected pages for the tiers given as folder names (each holds http.json and cli.jsonl)."""
import json, os, sys

root = sys.argv[1]
tiers = sys.argv[2:]
sections = {
 'POS': ['sales_register_page', 'sales_manage_page', 'sales_search_30d', 'sales_search_alldates', 'sales_search_offset5000', 'sales_search_by_name', 'sales_search_due_invoices', 'sales_suspended', 'items_search_name', 'items_search_barcode', 'items_suggest', 'pos_item_search', 'customers_search_name', 'customers_suggest', 'suppliers_search', 'receivings_register_page', 'expenses_search'],
 'ledgers': ['receivables_page', 'receivables_search', 'customer_ledger_heaviest', 'receivables_aged', 'receivables_balances', 'payables_page', 'supplier_ledger', 'payables_aged', 'payables_balances', 'trial_balance', 'trial_balance_entities', 'account_ledger_cash', 'income_statement', 'balance_sheet', 'profit_loss', 'cash_flow', 'tax_report', 'inventory_valuation', 'treasury_statement_1', 'treasury_statement_2', 'journals_search_default', 'journals_search_filters'],
 'sales reports': [f'sales_report_{k}_{p}' for k in ['revenue', 'clients_sales', 'item_sales', 'profit', 'item_profit', 'payments', 'net_position', 'invoice_returns'] for p in ['all', '30d']],
 'family': ['family_purchases_all', 'family_supplier_purchases_all', 'family_product_purchases_all', 'family_purchase_payments_all', 'family_supplier_aged_ledger_all', 'family_supplier_balances_all', 'family_stock_operations_all', 'family_stock_movements_all', 'family_product_balance_all', 'family_stock_aging_all', 'family_stock_turnover_all', 'family_journal_movements_all', 'family_journal_movements_30d', 'family_journals_all', 'family_cost_centers_all'],
}
data = {}
for tier in tiers:
    path = os.path.join(root, tier, 'http.json')
    if os.path.exists(path):
        for row in json.load(open(path))['results']:
            data.setdefault(row['name'], {})[tier] = row
    for suffix in ('-b', '-suspended'):
        extra = os.path.join(root, tier + suffix, 'http.json')
        if os.path.exists(extra):
            for row in json.load(open(extra))['results']:
                data.setdefault(row['name'], {})[tier] = row

def cell(row):
    if row is None:
        return '-'
    first = str(row['status']).split('/')[0]
    if row['fatal'] or first != '200':
        return f"**{first}** {'ذاكرة' if row['fatal'] else ''} ({row['median_ms']} ms, {row['peak_mb']} MB)"
    return f"{row['median_ms']} ms / {row['peak_mb']} MB / {row['questions']} q"

for title, names in sections.items():
    print(f'\n**{title}**\n')
    print('| الصفحة | ' + ' | '.join(tiers) + ' |')
    print('|---|' + '---|' * len(tiers))
    for name in names:
        if name in data:
            print(f'| `{name}` | ' + ' | '.join(cell(data[name].get(t)) for t in tiers) + ' |')
