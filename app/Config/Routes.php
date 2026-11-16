<?php

use App\Libraries\Accounting\FamilyReports\FamilyReportCatalog;
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setDefaultController('Login');
$routes->addPlaceholder('entry', '-?[0-9]{1,9}');

$reportFiles = static function (RouteCollection $routes, string $handler, string $titleKey, string $prefix = ''): void {
    foreach (['xlsx', 'pdf'] as $format) {
        $routes->get($prefix . $format, $handler, ['filter' => "reportexport:{$format}" . ($titleKey === '' ? '' : ",{$titleKey}")]);
    }
};

$routes->get('/', 'Login::index');
$routes->get('login', 'Login::index');
$routes->post('login', 'Login::index');
$routes->post('migrate', 'Login::migrate');

$routes->add('no_access/index/(:segment)', 'No_access::index/$1');
$routes->add('no_access/index/(:segment)/(:segment)', 'No_access::index/$1/$2');

$routes->add('reports/summary_(:any)/(:any)/(:any)', 'Reports::Summary_$1/$2/$3/$4');
$routes->add('reports/summary_expenses_categories', 'Reports::date_input_only');
$routes->add('reports/summary_payments', 'Reports::date_input_only');
$routes->add('reports/summary_discounts', 'Reports::summary_discounts_input');
$routes->add('reports/summary_(:any)', 'Reports::date_input');

$routes->add('reports/graphical_(:any)/(:any)/(:any)', 'Reports::Graphical_$1/$2/$3/$4');
$routes->add('reports/graphical_summary_expenses_categories', 'Reports::date_input_only');
$routes->add('reports/graphical_summary_discounts', 'Reports::summary_discounts_input');
$routes->add('reports/graphical_(:any)', 'Reports::date_input');

$routes->add('reports/inventory_(:any)/(:any)', 'Reports::Inventory_$1/$2');
$routes->add('reports/inventory_low', 'Reports::inventory_low');
$routes->add('reports/inventory_summary', 'Reports::inventory_summary_input');
$routes->add('reports/inventory_summary/(:any)/(:any)/(:any)', 'Reports::inventory_summary/$1/$2/$3');

$routes->add('reports/detailed_item_sales', 'Reports::dateInputItemSales');
$routes->add('reports/detailed_item_sales/(:segment)/(:segment)/(:segment)/(:segment)/(:segment)', 'Reports::detailedItemSales/$1/$2/$3/$4/$5');
$routes->add('reports/detailed_(:any)/(:any)/(:any)/(:any)', 'Reports::Detailed_$1/$2/$3/$4');
$routes->add('reports/detailed_sales', 'Reports::date_input_sales');
$routes->add('reports/detailed_receivings', 'Reports::date_input_recv');

$routes->add('reports/specific_(:any)/(:any)/(:any)/(:any)', 'Reports::Specific_$1/$2/$3/$4');
$routes->add('reports/specific_customers', 'Reports::specific_customer_input');
$routes->add('reports/specific_employees', 'Reports::specific_employee_input');
$routes->add('reports/specific_discounts', 'Reports::specific_discount_input');
$routes->add('reports/specific_suppliers', 'Reports::specific_supplier_input');

$routes->group('branches', static function (RouteCollection $routes): void {
    $routes->get('/', 'Branches::getIndex');
    $routes->get('search', 'Branches::getSearch');
    $routes->get('row/(:num)', 'Branches::getRow/$1');
    $routes->get('view', 'Branches::getView');
    $routes->get('view/(:entry)', 'Branches::getView/$1');
    $routes->post('save', 'Branches::postSave');
    $routes->post('save/(:entry)', 'Branches::postSave/$1');
    $routes->post('deactivate/(:num)', 'Branches::postDeactivate/$1');
    $routes->post('activate/(:num)', 'Branches::postActivate/$1');
    $routes->post('delete/(:num)', 'Branches::postDeleteBranch/$1');
});

$routes->group('roles', static function (RouteCollection $routes): void {
    $routes->get('/', 'Roles::getIndex');
    $routes->get('search', 'Roles::getSearch');
    $routes->get('row/(:num)', 'Roles::getRow/$1');
    $routes->get('view', 'Roles::getView');
    $routes->get('view/(:entry)', 'Roles::getView/$1');
    $routes->post('save', 'Roles::postSave');
    $routes->post('save/(:entry)', 'Roles::postSave/$1');
    $routes->post('delete/(:num)', 'Roles::postDeleteRole/$1');
});

$routes->group('accounting_settings', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingSetup::getIndex');
    $routes->post('save', 'AccountingSetup::postSaveSettings');
    $routes->post('save_controls', 'AccountingSetup::postSaveControls');
    $routes->post('save_journal', 'AccountingSetup::postSaveJournal');
    $routes->post('save_shipping_companies', 'AccountingSetup::postSaveShippingCompanies');
    $routes->post('save_shipping_company', 'AccountingSetup::postSaveShippingCompany');
    $routes->post('save_shipping_company/(:num)', 'AccountingSetup::postSaveShippingCompany/$1');
    $routes->post('toggle_shipping_company/(:num)', 'AccountingSetup::postToggleShippingCompany/$1');
    $routes->post('first_year', 'AccountingSetup::postFirstYear');
    $routes->get('activate', 'AccountingSetup::getActivate');
    $routes->get('activation_impact', 'AccountingSetup::getActivationImpact');
    $routes->post('activation_begin', 'AccountingSetup::postActivationBegin');
    $routes->post('activation_step', 'AccountingSetup::postActivationStep');
    $routes->post('enable', 'AccountingSetup::postEnable');
    $routes->post('disable', 'AccountingSetup::postDisable');
});

$routes->group('accounting_routing', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingRouting::getIndex');
    $routes->post('save', 'AccountingRouting::postSaveRouting');
});

$routes->group('accounting_receivables', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingReceivables::getIndex');
    $routes->get('search', 'AccountingReceivables::getSearch');
    $routes->get('row/(:num)', 'AccountingReceivables::getRow/$1');
    $routes->get('customer/(:num)', 'AccountingReceivables::getCustomer/$1');
    $routes->get('payments', 'AccountingReceivables::getPayments');
    $routes->get('payments/search', 'AccountingReceivables::getPaymentsSearch');
    $routes->get('credit_notes', 'AccountingReceivables::getCreditNotes');
    $routes->get('credit_notes/search', 'AccountingReceivables::getCreditNotesSearch');
    $routes->get('aged_ledger', 'AccountingReceivables::getAgedLedger');
    $routes->get('aged_ledger/csv', 'AccountingReceivables::getAgedLedgerCsv');
    $reportFiles($routes, 'AccountingReceivables::getAgedLedgerCsv', 'Accounting_receivables.aged_title', 'aged_ledger/');
    $routes->get('balances', 'AccountingReceivables::getBalances');
    $routes->get('balances/csv', 'AccountingReceivables::getBalancesCsv');
    $reportFiles($routes, 'AccountingReceivables::getBalancesCsv', 'Accounting_receivables.balances_title', 'balances/');
    $routes->get('opening_form/(:num)', 'AccountingReceivables::getOpeningForm/$1');
    $routes->post('opening/(:num)', 'AccountingReceivables::postOpening/$1');
    $routes->get('opening_edit_form/(:num)', 'AccountingReceivables::getOpeningEditForm/$1');
    $routes->post('opening_edit/(:num)', 'AccountingReceivables::postOpeningEdit/$1');
    $routes->get('opening_cancel_form/(:num)', 'AccountingReceivables::getOpeningCancelForm/$1');
    $routes->post('opening_cancel/(:num)', 'AccountingReceivables::postOpeningCancel/$1');
    $routes->get('advance_form/(:num)', 'AccountingReceivables::getAdvanceForm/$1');
    $routes->post('advance/(:num)', 'AccountingReceivables::postAdvance/$1');
    $routes->get('refund_form/(:num)', 'AccountingReceivables::getRefundForm/$1');
    $routes->post('refund/(:num)', 'AccountingReceivables::postRefund/$1');
    $routes->get('cancel_form/(:segment)/(:num)', 'AccountingReceivables::getCancelForm/$1/$2');
    $routes->post('cancel/(:segment)/(:num)', 'AccountingReceivables::postCancel/$1/$2');
});

$routes->group('accounting_payables', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingPayables::getIndex');
    $routes->get('search', 'AccountingPayables::getSearch');
    $routes->get('row/(:num)', 'AccountingPayables::getRow/$1');
    $routes->get('supplier/(:num)', 'AccountingPayables::getSupplier/$1');
    $routes->get('payments', 'AccountingPayables::getPayments');
    $routes->get('payments/search', 'AccountingPayables::getPaymentsSearch');
    $routes->get('debit_notes', 'AccountingPayables::getDebitNotes');
    $routes->get('debit_notes/search', 'AccountingPayables::getDebitNotesSearch');
    $routes->get('aged_ledger', 'AccountingPayables::getAgedLedger');
    $routes->get('aged_ledger/csv', 'AccountingPayables::getAgedLedgerCsv');
    $reportFiles($routes, 'AccountingPayables::getAgedLedgerCsv', 'Accounting_payables.aged_title', 'aged_ledger/');
    $routes->get('balances', 'AccountingPayables::getBalances');
    $routes->get('balances/csv', 'AccountingPayables::getBalancesCsv');
    $reportFiles($routes, 'AccountingPayables::getBalancesCsv', 'Accounting_payables.balances_title', 'balances/');
    $routes->get('payment_form/(:num)', 'AccountingPayables::getPaymentForm/$1');
    $routes->post('payment/(:num)', 'AccountingPayables::postPayment/$1');
    $routes->get('opening_form/(:num)', 'AccountingPayables::getOpeningForm/$1');
    $routes->post('opening/(:num)', 'AccountingPayables::postOpening/$1');
    $routes->get('cancel_form/(:segment)/(:num)', 'AccountingPayables::getCancelForm/$1/$2');
    $routes->post('cancel/(:segment)/(:num)', 'AccountingPayables::postCancel/$1/$2');
    $routes->get('refund_form/(:num)', 'AccountingPayables::getRefundForm/$1');
    $routes->post('refund/(:num)', 'AccountingPayables::postRefund/$1');
});

$routes->group('accounting_cheques', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingCheques::getIndex');
    $routes->get('search', 'AccountingCheques::getSearch');
    $routes->get('row/(:num)', 'AccountingCheques::getRow/$1');
    $routes->get('view/(:num)', 'AccountingCheques::getDetail/$1');
    $routes->get('parties/(:segment)', 'AccountingCheques::getParties/$1');
    $routes->get('form/(:segment)', 'AccountingCheques::getForm/$1');
    $routes->get('form/(:segment)/(:num)', 'AccountingCheques::getForm/$1/$2');
    $routes->post('save/(:segment)', 'AccountingCheques::postSaveCheque/$1');
    $routes->post('save/(:segment)/(:entry)', 'AccountingCheques::postSaveCheque/$1/$2');
    $routes->get('action_form/(:segment)/(:num)', 'AccountingCheques::getActionForm/$1/$2');
    $routes->post('act/(:segment)/(:num)', 'AccountingCheques::postAct/$1/$2');
    $routes->post('cancel/(:num)', 'AccountingCheques::postCancel/$1');
    $routes->post('undo/(:num)', 'AccountingCheques::postUndo/$1');
    $routes->post('delete/(:num)', 'AccountingCheques::postDeleteCheque/$1');
    $routes->get('books', 'AccountingCheques::getBooks');
    $routes->get('book_form', 'AccountingCheques::getBookForm');
    $routes->get('book_form/(:num)', 'AccountingCheques::getBookForm/$1');
    $routes->post('save_book', 'AccountingCheques::postSaveBook');
    $routes->post('save_book/(:entry)', 'AccountingCheques::postSaveBook/$1');
    $routes->post('book_active/(:num)/(:num)', 'AccountingCheques::postBookActive/$1/$2');
    $routes->post('delete_book/(:num)', 'AccountingCheques::postDeleteBook/$1');
    $routes->get('report/(:segment)', 'AccountingCheques::getReport/$1');
    $routes->get('report/(:segment)/csv', 'AccountingCheques::getReportCsv/$1');
    $reportFiles($routes, 'AccountingCheques::getReportCsv/$1', '', 'report/(:segment)/');
});

$routes->get('accounting_lookup/search', 'AccountingLookup::getSearch');
$routes->group('accounting_assets', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingAssets::getIndex');
    $routes->get('csv', 'AccountingAssets::getCsv');
    $reportFiles($routes, 'AccountingAssets::getCsv', 'Accounting_assets.title');
    $routes->get('view/(:num)', 'AccountingAssets::getDetail/$1');
    $routes->get('form', 'AccountingAssets::getForm');
    $routes->get('form/(:num)', 'AccountingAssets::getForm/$1');
    $routes->post('save', 'AccountingAssets::postSave');
    $routes->post('save/(:num)', 'AccountingAssets::postSave/$1');
    $routes->post('delete/(:num)', 'AccountingAssets::postDeleteAsset/$1');
    $routes->post('status/(:num)/(:num)', 'AccountingAssets::postStatus/$1/$2');
    $routes->get('usage_form/(:num)', 'AccountingAssets::getUsageForm/$1');
    $routes->post('usage/(:num)', 'AccountingAssets::postUsage/$1');
    $routes->get('dispose_form/(:segment)/(:num)', 'AccountingAssets::getDisposeForm/$1/$2');
    $routes->post('dispose/(:segment)/(:num)', 'AccountingAssets::postDispose/$1/$2');
    $routes->get('dispose_reverse_form/(:num)', 'AccountingAssets::getDisposeReverseForm/$1');
    $routes->post('dispose_reverse/(:num)', 'AccountingAssets::postDisposeReverse/$1');
    $routes->get('categories', 'AccountingAssets::getCategories');
    $routes->get('category_form', 'AccountingAssets::getCategoryForm');
    $routes->get('category_form/(:num)', 'AccountingAssets::getCategoryForm/$1');
    $routes->post('save_category', 'AccountingAssets::postSaveCategory');
    $routes->post('save_category/(:num)', 'AccountingAssets::postSaveCategory/$1');
    $routes->post('category_active/(:num)/(:num)', 'AccountingAssets::postCategoryActive/$1/$2');
    $routes->post('delete_category/(:num)', 'AccountingAssets::postDeleteCategory/$1');
    $routes->get('depreciation', 'AccountingAssets::getDepreciation');
    $routes->get('depreciation/approve_form', 'AccountingAssets::getApproveForm');
    $routes->post('depreciation/approve', 'AccountingAssets::postApprove');
    $routes->get('depreciation/reverse_form/(:num)', 'AccountingAssets::getReverseForm/$1');
    $routes->post('depreciation/reverse/(:num)', 'AccountingAssets::postReverse/$1');
});

$routes->group('accounting_debit_notes', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingDebitNotes::getIndex');
    $routes->get('form', 'AccountingDebitNotes::getForm');
    $routes->get('customers', 'AccountingDebitNotes::getCustomers');
    $routes->post('save', 'AccountingDebitNotes::postSaveNote');
    $routes->get('reverse_form/(:num)', 'AccountingDebitNotes::getReverseForm/$1');
    $routes->post('reverse/(:num)', 'AccountingDebitNotes::postReverse/$1');
});

$routes->group('stock_units', static function (RouteCollection $routes): void {
    $routes->get('/', 'StockUnits::getIndex');
    $routes->get('templateForm/(:segment)', 'StockUnits::getTemplateForm/$1');
    $routes->post('saveTemplate/(:segment)', 'StockUnits::postSaveTemplate/$1');
    $routes->post('setActive/(:num)', 'StockUnits::postSetActive/$1');
    $routes->post('settings', 'StockUnits::postSettings');
    $routes->post('saveItem/(:num)', 'StockUnits::postSaveItem/$1');
    $routes->post('addBarcode/(:num)', 'StockUnits::postAddBarcode/$1');
    $routes->post('removeBarcode/(:num)', 'StockUnits::postRemoveBarcode/$1');
});

$routes->group('stock_documents', static function (RouteCollection $routes): void {
    $routes->get('/', 'StockPermits::getIndex');
    $routes->get('permits', 'StockPermits::getIndex');
    $routes->get('permits/form/(:segment)', 'StockPermits::getForm/$1');
    $routes->get('permits/edit/(:num)', 'StockPermits::getEditForm/$1');
    $routes->get('permits/view/(:num)', 'StockPermits::getDocument/$1');
    $routes->get('permits/cancel_form/(:num)', 'StockPermits::getCancelForm/$1');
    $routes->get('items', 'StockPermits::getItems');
    $routes->get('parties', 'StockPermits::getParties');
    $routes->post('permits/save', 'StockPermits::postSavePermit');
    $routes->post('permits/update/(:num)', 'StockPermits::postUpdate/$1');
    $routes->post('permits/cancel/(:num)', 'StockPermits::postCancel/$1');
    $routes->post('permits/settings', 'StockPermits::postSettings');

    $routes->get('stocktakes', 'Stocktakes::getIndex');
    $routes->get('stocktakes/form', 'Stocktakes::getForm');
    $routes->get('stocktakes/view/(:num)', 'Stocktakes::getDocument/$1');
    $routes->get('stocktakes/sheet/(:num)', 'Stocktakes::getSheet/$1');
    $routes->get('stocktakes/cancel_form/(:num)', 'Stocktakes::getCancelForm/$1');
    $routes->post('stocktakes/save', 'Stocktakes::postSaveStocktake');
    $routes->post('stocktakes/count/(:num)', 'Stocktakes::postCount/$1');
    $routes->post('stocktakes/add_items/(:num)', 'Stocktakes::postAddItems/$1');
    $routes->post('stocktakes/remove_item/(:num)', 'Stocktakes::postRemoveItem/$1');
    $routes->post('stocktakes/approve/(:num)', 'Stocktakes::postApprove/$1');
    $routes->post('stocktakes/cancel/(:num)', 'Stocktakes::postCancel/$1');

    $routes->get('requests', 'StockRequests::getIndex');
    $routes->get('requests/form', 'StockRequests::getForm');
    $routes->get('requests/view/(:num)', 'StockRequests::getDocument/$1');
    $routes->get('requests/cancel_form/(:num)', 'StockRequests::getCancelForm/$1');
    $routes->post('requests/save', 'StockRequests::postSaveRequest');
    $routes->post('requests/approve/(:num)', 'StockRequests::postApprove/$1');
    $routes->post('requests/fulfil/(:num)', 'StockRequests::postFulfil/$1');
    $routes->post('requests/cancel/(:num)', 'StockRequests::postCancel/$1');
});

$routes->group('customer_orders', static function (RouteCollection $routes): void {
    $routes->get('/', 'Customer_orders::getIndex');
    $routes->get('add', 'Customer_orders::getForm');
    $routes->get('edit/(:num)', 'Customer_orders::getForm/$1');
    $routes->get('view/(:num)', 'Customer_orders::getShow/$1');
    $routes->get('customers', 'Customer_orders::getCustomers');
    $routes->get('items', 'Customer_orders::getItems');
    $routes->post('save', 'Customer_orders::postSave');
    $routes->post('save/(:num)', 'Customer_orders::postSave/$1');
    $routes->post('status/(:num)', 'Customer_orders::postStatus/$1');
    $routes->post('cancel/(:num)', 'Customer_orders::postCancel/$1');
    $routes->post('convert/(:num)', 'Customer_orders::postConvert/$1');
    $routes->post('settings', 'Customer_orders::postSettings');
});

foreach (['purchase_requests' => 'Purchase_requests', 'quotation_requests' => 'Quotation_requests', 'supplier_quotations' => 'Supplier_quotations', 'purchase_orders' => 'Purchase_orders'] as $purchaseCyclePath => $purchaseCycleController) {
    $routes->group($purchaseCyclePath, static function (RouteCollection $routes) use ($purchaseCycleController): void {
        $routes->get('/', $purchaseCycleController . '::getIndex');
        $routes->get('add', $purchaseCycleController . '::getForm');
        $routes->get('edit/(:num)', $purchaseCycleController . '::getForm/$1');
        $routes->get('view/(:num)', $purchaseCycleController . '::getShow/$1');
        $routes->get('suppliers', $purchaseCycleController . '::getSuppliers');
        $routes->get('items', $purchaseCycleController . '::getItems');
        $routes->post('save', $purchaseCycleController . '::postSave');
        $routes->post('save/(:num)', $purchaseCycleController . '::postSave/$1');
        $routes->post('status/(:num)', $purchaseCycleController . '::postStatus/$1');
        $routes->post('cancel/(:num)', $purchaseCycleController . '::postCancel/$1');
        $routes->post('convert/(:num)', $purchaseCycleController . '::postConvert/$1');
        $routes->post('settings', $purchaseCycleController . '::postSettings');
    });
}

$routes->group('recurring_invoices', static function (RouteCollection $routes): void {
    $routes->get('/', 'Recurring_invoices::getIndex');
    $routes->get('add', 'Recurring_invoices::getForm');
    $routes->get('edit/(:num)', 'Recurring_invoices::getForm/$1');
    $routes->get('view/(:num)', 'Recurring_invoices::getShow/$1');
    $routes->get('customers', 'Recurring_invoices::getCustomers');
    $routes->get('items', 'Recurring_invoices::getItems');
    $routes->post('save', 'Recurring_invoices::postSave');
    $routes->post('save/(:num)', 'Recurring_invoices::postSave/$1');
    $routes->post('status/(:num)', 'Recurring_invoices::postStatus/$1');
    $routes->post('retry/(:num)/(:num)', 'Recurring_invoices::postRetry/$1/$2');
});

$routes->group('recurring_expenses', static function (RouteCollection $routes): void {
    $routes->get('/', 'Recurring_expenses::getIndex');
    $routes->get('add', 'Recurring_expenses::getForm');
    $routes->get('edit/(:num)', 'Recurring_expenses::getForm/$1');
    $routes->get('view/(:num)', 'Recurring_expenses::getShow/$1');
    $routes->get('customers', 'Recurring_expenses::getCustomers');
    $routes->get('items', 'Recurring_expenses::getItems');
    $routes->post('save', 'Recurring_expenses::postSave');
    $routes->post('save/(:num)', 'Recurring_expenses::postSave/$1');
    $routes->post('status/(:num)', 'Recurring_expenses::postStatus/$1');
    $routes->post('retry/(:num)/(:num)', 'Recurring_expenses::postRetry/$1/$2');
});

$routes->group('receipt_vouchers', static function (RouteCollection $routes): void {
    $routes->get('/', 'Receipt_vouchers::getIndex');
    $routes->get('search', 'Receipt_vouchers::getSearch');
    $routes->get('row/(:num)', 'Receipt_vouchers::getRow/$1');
    $routes->get('view', 'Receipt_vouchers::getView');
    $routes->get('view/(:num)', 'Receipt_vouchers::getView/$1');
    $routes->get('print/(:num)', 'Receipt_vouchers::getPrint/$1');
    $routes->get('pdf/(:num)', 'Receipt_vouchers::getPdf/$1');
    $routes->post('save', 'Receipt_vouchers::postSave');
    $routes->post('save/(:entry)', 'Receipt_vouchers::postSave/$1');
    $routes->post('delete', 'Receipt_vouchers::postDelete');
});

$routes->group('revenue_categories', static function (RouteCollection $routes): void {
    $routes->get('/', 'Revenue_categories::getIndex');
    $routes->get('search', 'Revenue_categories::getSearch');
    $routes->get('row/(:num)', 'Revenue_categories::getRow/$1');
    $routes->get('view', 'Revenue_categories::getView');
    $routes->get('view/(:num)', 'Revenue_categories::getView/$1');
    $routes->post('save', 'Revenue_categories::postSave');
    $routes->post('save/(:entry)', 'Revenue_categories::postSave/$1');
    $routes->post('delete', 'Revenue_categories::postDelete');
});

$routes->group('tax_definitions', static function (RouteCollection $routes): void {
    $routes->get('/', 'Tax_definitions::getIndex');
    $routes->get('search', 'Tax_definitions::getSearch');
    $routes->get('row/(:num)', 'Tax_definitions::getRow/$1');
    $routes->get('view', 'Tax_definitions::getView');
    $routes->get('view/(:num)', 'Tax_definitions::getView/$1');
    $routes->post('save', 'Tax_definitions::postSave');
    $routes->post('save/(:entry)', 'Tax_definitions::postSave/$1');
    $routes->post('delete', 'Tax_definitions::postDelete');
});

$routes->group('custom_fields', static function (RouteCollection $routes): void {
    $routes->get('/', 'Custom_fields::getIndex');
    $routes->get('search', 'Custom_fields::getSearch');
    $routes->get('row/(:num)', 'Custom_fields::getRow/$1');
    $routes->get('view', 'Custom_fields::getView');
    $routes->get('view/(:num)', 'Custom_fields::getView/$1');
    $routes->post('save', 'Custom_fields::postSave');
    $routes->post('save/(:entry)', 'Custom_fields::postSave/$1');
    $routes->post('status/(:num)/(:num)', 'Custom_fields::postStatus/$1/$2');
});

$routes->group('integrations', static function (RouteCollection $routes): void {
    $routes->get('/', 'Integrations::getIndex');
    $routes->post('options', 'Integrations::postOptions');
    $routes->get('payment_methods', 'Integrations::getPaymentMethods');
    $routes->post('payment_methods', 'Integrations::postPaymentMethods');
    $routes->get('translations', 'Integrations::getTranslations');
    $routes->post('translations/save', 'Integrations::postTranslationSave');
    $routes->post('translations/delete/(:num)', 'Integrations::postTranslationDelete/$1');
    $routes->get('rules', 'Integrations::getRules');
    $routes->post('rules/save', 'Integrations::postRuleSave');
    $routes->post('rules/save/(:num)', 'Integrations::postRuleSave/$1');
    $routes->post('rules/active/(:num)/(:num)', 'Integrations::postRuleActive/$1/$2');
    $routes->post('rules/delete/(:num)', 'Integrations::postRuleDelete/$1');
});

$routes->group('api_keys', static function (RouteCollection $routes): void {
    $routes->get('/', 'Api_keys::getIndex');
    $routes->post('create', 'Api_keys::postCreate');
    $routes->post('revoke/(:num)', 'Api_keys::postRevoke/$1');
});

$routes->group('notifications', static function (RouteCollection $routes): void {
    $routes->get('/', 'Notifications::getIndex');
    $routes->post('read/(:num)', 'Notifications::postRead/$1');
    $routes->post('read_all', 'Notifications::postReadAll');
});

$routes->group('api/v1', static function (RouteCollection $routes): void {
    $routes->get('ping', 'Api\V1\Resources::ping');
    $routes->get('customers/balances', 'Api\V1\Resources::customerBalances');
    $routes->get('documents/sales', 'Api\V1\Resources::salesDocuments');
});

$routes->group('price_lists', static function (RouteCollection $routes): void {
    $routes->get('/', 'Price_lists::getIndex');
    $routes->get('search', 'Price_lists::getSearch');
    $routes->get('row/(:num)', 'Price_lists::getRow/$1');
    $routes->get('view', 'Price_lists::getView');
    $routes->get('view/(:num)', 'Price_lists::getView/$1');
    $routes->post('save', 'Price_lists::postSave');
    $routes->post('save/(:entry)', 'Price_lists::postSave/$1');
    $routes->post('status/(:num)/(:num)', 'Price_lists::postStatus/$1/$2');
    $routes->post('delete', 'Price_lists::postDelete');
});

foreach (['customer_classifications' => 'Customer_classifications', 'item_brands' => 'Item_brands'] as $lookupPath => $lookupController) {
    $routes->group($lookupPath, static function (RouteCollection $routes) use ($lookupController): void {
        $routes->get('/', $lookupController . '::getIndex');
        $routes->get('search', $lookupController . '::getSearch');
        $routes->get('row/(:num)', $lookupController . '::getRow/$1');
        $routes->get('view', $lookupController . '::getView');
        $routes->get('view/(:num)', $lookupController . '::getView/$1');
        $routes->post('save', $lookupController . '::postSave');
        $routes->post('save/(:entry)', $lookupController . '::postSave/$1');
        $routes->post('status/(:num)/(:num)', $lookupController . '::postStatus/$1/$2');
        $routes->post('delete', $lookupController . '::postDelete');
    });
}

$routes->group('accounting_reports', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingReports::getIndex');
    $routes->get('overview', 'AccountingOverview::getIndex');
    $routes->get('trial_balance', 'AccountingReports::getIndex');
    $routes->get('csv', 'AccountingReports::getCsv');
    $reportFiles($routes, 'AccountingReports::getCsv', 'Accounting_reports.trial_balance');
    $routes->get('ledger', 'AccountingReports::getLedger');
    $routes->get('ledger/csv', 'AccountingReports::getLedgerCsv');
    $reportFiles($routes, 'AccountingReports::getLedgerCsv', 'Accounting_reports.ledger', 'ledger/');
    $routes->get('inventory_valuation', 'AccountingInventoryReports::getIndex');
    $routes->get('inventory_valuation/csv', 'AccountingInventoryReports::getCsv');
    $reportFiles($routes, 'AccountingInventoryReports::getCsv', 'Accounting_inventory_reports.title', 'inventory_valuation/');
    $routes->get('tax', 'AccountingTaxReports::getIndex');
    $routes->get('tax/csv', 'AccountingTaxReports::getCsv');
    $reportFiles($routes, 'AccountingTaxReports::getCsv', 'Accounting_tax_reports.title', 'tax/');
    $routes->get('revenue', 'AccountingSalesReports::getRevenue');
    $routes->get('revenue/csv', 'AccountingSalesReports::getRevenueCsv');
    $reportFiles($routes, 'AccountingSalesReports::getRevenueCsv', 'Accounting_operational_reports.revenue_title', 'revenue/');
    $routes->get('clients_sales', 'AccountingSalesReports::getClients');
    $routes->get('clients_sales/csv', 'AccountingSalesReports::getClientsCsv');
    $reportFiles($routes, 'AccountingSalesReports::getClientsCsv', 'Accounting_operational_reports.clients_title', 'clients_sales/');
    $routes->get('item_sales', 'AccountingItemReports::getIndex');
    $routes->get('item_sales/csv', 'AccountingItemReports::getCsv');
    $reportFiles($routes, 'AccountingItemReports::getCsv', 'Accounting_operational_reports.items_title', 'item_sales/');
    $routes->get('profit', 'AccountingProfitReports::getIndex');
    $routes->get('profit/csv', 'AccountingProfitReports::getCsv');
    $reportFiles($routes, 'AccountingProfitReports::getCsv', 'Accounting_operational_reports.profit_title', 'profit/');
    $routes->get('item_profit', 'AccountingProfitReports::getItems');
    $routes->get('item_profit/csv', 'AccountingProfitReports::getItemsCsv');
    $reportFiles($routes, 'AccountingProfitReports::getItemsCsv', 'Accounting_operational_reports.item_profit_title', 'item_profit/');
    $routes->get('payments', 'AccountingPaymentReports::getIndex');
    $routes->get('payments/csv', 'AccountingPaymentReports::getCsv');
    $reportFiles($routes, 'AccountingPaymentReports::getCsv', 'Accounting_operational_reports.payments_title', 'payments/');
    $routes->get('advance_payments', 'AccountingPaymentReports::getAdvances');
    $routes->get('advance_payments/csv', 'AccountingPaymentReports::getAdvancesCsv');
    $reportFiles($routes, 'AccountingPaymentReports::getAdvancesCsv', 'Accounting_operational_reports.advances_title', 'advance_payments/');
    $routes->get('net_position', 'AccountingNetPosition::getIndex');
    $routes->get('net_position/csv', 'AccountingNetPosition::getCsv');
    $reportFiles($routes, 'AccountingNetPosition::getCsv', 'Accounting_operational_reports.net_title', 'net_position/');
    $routes->get('invoice_returns', 'AccountingNetPosition::getCross');
    $routes->get('invoice_returns/csv', 'AccountingNetPosition::getCrossCsv');
    $reportFiles($routes, 'AccountingNetPosition::getCrossCsv', 'Accounting_operational_reports.cross_title', 'invoice_returns/');

    foreach (FamilyReportCatalog::keys() as $familyKey) {
        $routes->get($familyKey, 'AccountingFamilyReports::getShow/' . $familyKey);
        $routes->get($familyKey . '/csv', 'AccountingFamilyReports::getCsv/' . $familyKey);
        $reportFiles($routes, 'AccountingFamilyReports::getCsv/' . $familyKey, 'Accounting_family_reports.' . FamilyReportCatalog::titleKey($familyKey), $familyKey . '/');
    }
});

$routes->group('accounting_statements', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingStatements::getIncomeStatement');
    $routes->get('income_statement', 'AccountingStatements::getIncomeStatement');
    $routes->get('income_statement/csv', 'AccountingStatements::getIncomeStatementCsv');
    $reportFiles($routes, 'AccountingStatements::getIncomeStatementCsv', 'Accounting_statements.income_statement', 'income_statement/');
    $routes->get('balance_sheet', 'AccountingStatements::getBalanceSheet');
    $routes->get('balance_sheet/csv', 'AccountingStatements::getBalanceSheetCsv');
    $reportFiles($routes, 'AccountingStatements::getBalanceSheetCsv', 'Accounting_statements.balance_sheet', 'balance_sheet/');
    $routes->get('profit_loss', 'AccountingProfitLoss::getIndex');
    $routes->get('profit_loss/csv', 'AccountingProfitLoss::getCsv');
    $reportFiles($routes, 'AccountingProfitLoss::getCsv', 'Accounting_profit_loss.title', 'profit_loss/');
    $routes->get('cash_flow', 'AccountingCashFlow::getIndex');
    $routes->get('cash_flow/csv', 'AccountingCashFlow::getCsv');
    $reportFiles($routes, 'AccountingCashFlow::getCsv', 'Accounting_cash_flow.title', 'cash_flow/');
    $routes->get('cash_flow/settings', 'AccountingCashFlow::getSettings');
    $routes->post('cash_flow/settings/save', 'AccountingCashFlow::postSettingsSave');
});

$routes->group('accounting_treasuries', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingTreasuries::getIndex');
    $routes->get('search', 'AccountingTreasuries::getSearch');
    $routes->get('row/(:num)', 'AccountingTreasuries::getRow/$1');
    $routes->get('statement/(:num)', 'AccountingTreasuries::getStatement/$1');
    $routes->get('statement/(:num)/csv', 'AccountingTreasuries::getStatementCsv/$1');
    $reportFiles($routes, 'AccountingTreasuries::getStatementCsv/$1', 'Accounting_treasuries.statement', 'statement/(:num)/');
    $routes->get('banks', 'AccountingTreasuries::getBanks');
    $routes->get('view', 'AccountingTreasuries::getView');
    $routes->get('view/(:entry)', 'AccountingTreasuries::getView/$1');
    $routes->post('save', 'AccountingTreasuries::postSave');
    $routes->post('save/(:entry)', 'AccountingTreasuries::postSave/$1');
    $routes->post('deactivate/(:num)', 'AccountingTreasuries::postDeactivate/$1');
    $routes->post('activate/(:num)', 'AccountingTreasuries::postActivate/$1');
    $routes->post('delete/(:num)', 'AccountingTreasuries::postDeleteTreasury/$1');

    $routes->get('transfers', 'AccountingTreasuries::getTransfers');
    $routes->get('transfers/search', 'AccountingTreasuries::getTransfersSearch');
    $routes->get('transfers/row/(:num)', 'AccountingTreasuries::getTransferRow/$1');
    $routes->get('transfer_form', 'AccountingTreasuries::getTransferForm');
    $routes->post('transfer', 'AccountingTreasuries::postTransfer');
    $routes->get('cancel_transfer_form/(:num)', 'AccountingTreasuries::getCancelTransferForm/$1');
    $routes->post('cancel_transfer/(:num)', 'AccountingTreasuries::postCancelTransfer/$1');

    $routes->get('defaults', 'AccountingTreasuries::getDefaults');
    $routes->post('default/(:num)', 'AccountingTreasuries::postDefault/$1');

    $routes->get('bank', 'AccountingBankReconciliation::getIndex');
    $routes->get('bank/sample', 'AccountingBankReconciliation::getSample');
    $routes->get('bank/(:num)', 'AccountingBankReconciliation::getAccount/$1');
    $routes->get('bank/(:num)/import', 'AccountingBankReconciliation::getImport/$1');
    $routes->post('bank/(:num)/import', 'AccountingBankReconciliation::postImport/$1');
    $routes->get('bank/(:num)/lines', 'AccountingBankReconciliation::getLines/$1');
    $routes->get('bank/(:num)/matches', 'AccountingBankReconciliation::getMatches/$1');
    $routes->get('bank/(:num)/match/(:num)', 'AccountingBankReconciliation::getMatch/$1/$2');
    $routes->post('bank/(:num)/match', 'AccountingBankReconciliation::postMatch/$1');
    $routes->post('bank/(:num)/unmatch/(:num)', 'AccountingBankReconciliation::postUnmatch/$1/$2');
    $routes->get('bank/(:num)/suggestions', 'AccountingBankReconciliation::getSuggestions/$1');
    $routes->get('bank/(:num)/report', 'AccountingBankReconciliation::getReport/$1');
    $routes->get('bank/(:num)/report/csv', 'AccountingBankReconciliation::getReportCsv/$1');
    $reportFiles($routes, 'AccountingBankReconciliation::getReportCsv/$1', '', 'bank/(:num)/report/');
    $routes->post('bank/(:num)/close', 'AccountingBankReconciliation::postClose/$1');
    $routes->post('bank/reopen/(:num)', 'AccountingBankReconciliation::postReopen/$1');
    $routes->post('bank/statement_delete/(:num)', 'AccountingBankReconciliation::postDeleteStatement/$1');
    $routes->post('bank/window', 'AccountingBankReconciliation::postWindow');

    $routes->get('inter_branch', 'AccountingInterBranch::getIndex');
    $routes->get('inter_branch/pairs', 'AccountingInterBranch::getPairs');
    $routes->get('inter_branch/csv', 'AccountingInterBranch::getCsv');
    $reportFiles($routes, 'AccountingInterBranch::getCsv', 'Accounting_treasuries.inter_branch', 'inter_branch/');
    $routes->get('inter_branch/settlements/search', 'AccountingInterBranch::getSettlementsSearch');
    $routes->get('inter_branch/settlements/row/(:num)', 'AccountingInterBranch::getSettlementsRow/$1');
    $routes->get('inter_branch/settle_form', 'AccountingInterBranch::getSettleForm');
    $routes->post('inter_branch/settle', 'AccountingInterBranch::postSettle');
    $routes->get('inter_branch/cancel_form/(:num)', 'AccountingInterBranch::getCancelForm/$1');
    $routes->post('inter_branch/cancel/(:num)', 'AccountingInterBranch::postCancel/$1');
});

$routes->group('accounting_periods', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingPeriods::getIndex');
    $routes->get('search', 'AccountingPeriods::getSearch');
    $routes->get('row/(:num)', 'AccountingPeriods::getRow/$1');
    $routes->get('view', 'AccountingPeriods::getView');
    $routes->get('view/(:entry)', 'AccountingPeriods::getView/$1');
    $routes->post('save', 'AccountingPeriods::postSave');
    $routes->post('save/(:entry)', 'AccountingPeriods::postSave/$1');
    $routes->post('delete/(:num)', 'AccountingPeriods::postDeleteYear/$1');

    $routes->get('closings', 'AccountingClosings::getIndex');
    $routes->get('closings/search', 'AccountingClosings::getSearch');
    $routes->get('closings/close_form', 'AccountingClosings::getCloseForm');
    $routes->get('closings/preview', 'AccountingClosings::getPreview');
    $routes->get('closings/reopen_form', 'AccountingClosings::getReopenForm');
    $routes->post('closings/close', 'AccountingClosings::postClose');
    $routes->post('closings/reopen', 'AccountingClosings::postReopen');

    $routes->get('opening', 'AccountingOpening::getIndex');
    $routes->get('opening/reverse_form', 'AccountingOpening::getReverseForm');
    $routes->post('opening/save_draft', 'AccountingOpening::postSaveDraft');
    $routes->post('opening/post', 'AccountingOpening::postPost');
    $routes->post('opening/discard', 'AccountingOpening::postDiscard');
    $routes->post('opening/reverse', 'AccountingOpening::postReverse');
    $routes->get('opening/stock/differences_form', 'AccountingOpening::getStockDifferencesForm');
    $routes->get('opening/stock/reverse_form', 'AccountingOpening::getStockReverseForm');
    $routes->post('opening/stock/post', 'AccountingOpening::postStockPost');
    $routes->post('opening/stock/reverse', 'AccountingOpening::postStockReverse');
    $routes->get('opening/stock/reprice_form', 'AccountingOpening::getStockRepriceForm');
    $routes->post('opening/stock/reprice', 'AccountingOpening::postStockReprice');
    $routes->get('opening_import', 'AccountingOpeningImport::getIndex');
    $routes->post('opening_import/upload', 'AccountingOpeningImport::postUpload');
    $routes->get('opening_import/sample/(:segment)', 'AccountingOpeningImport::getSample/$1');
    $routes->get('opening_import/preview/(:segment)/(:segment)', 'AccountingOpeningImport::getPreview/$1/$2');
    $routes->get('opening_import/export/(:segment)/(:segment)', 'AccountingOpeningImport::getExport/$1/$2');
    $routes->get('opening_import/approve_form/(:segment)/(:segment)', 'AccountingOpeningImport::getApproveForm/$1/$2');
    $routes->post('opening_import/approve', 'AccountingOpeningImport::postApprove');

    $routes->get('year_end', 'AccountingYearEnd::getIndex');
    $routes->get('year_end/csv', 'AccountingYearEnd::getCsv');
    $reportFiles($routes, 'AccountingYearEnd::getCsv', 'Accounting_periods.year_end_title', 'year_end/');
    $routes->get('year_end/approve_form', 'AccountingYearEnd::getApproveForm');
    $routes->post('year_end/approve', 'AccountingYearEnd::postApprove');
    $routes->get('year_end/reverse_form/(:num)', 'AccountingYearEnd::getReverseForm/$1');
    $routes->post('year_end/reverse/(:num)', 'AccountingYearEnd::postReverse/$1');

    $routes->get('backfill', 'AccountingBackfill::getIndex');
    $routes->get('backfill/new_form', 'AccountingBackfill::getNewForm');
    $routes->post('backfill/create', 'AccountingBackfill::postCreate');
    $routes->get('backfill/view/(:num)', 'AccountingBackfill::getBatch/$1');
    $routes->post('backfill/preview/(:num)', 'AccountingBackfill::postPreview/$1');
    $routes->get('backfill/approve_form/(:num)', 'AccountingBackfill::getApproveForm/$1');
    $routes->post('backfill/approve/(:num)', 'AccountingBackfill::postApprove/$1');
    $routes->get('backfill/cancel_form/(:num)', 'AccountingBackfill::getCancelForm/$1');
    $routes->post('backfill/cancel/(:num)', 'AccountingBackfill::postCancel/$1');
    $routes->post('backfill/create_years/(:num)', 'AccountingBackfill::postCreateYears/$1');
    $routes->get('backfill/refused/(:num)', 'AccountingBackfill::getRefused/$1');
});

$routes->group('accounting_chart', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingChart::getIndex');
    $routes->get('node/(:num)', 'AccountingChart::getNode/$1');
    $routes->get('branch/(:num)', 'AccountingChart::getBranch/$1');
    $routes->get('find', 'AccountingChart::getFind');
    $routes->get('search', 'AccountingChart::getSearch');
    $routes->get('row/(:num)', 'AccountingChart::getRow/$1');
    $routes->get('view', 'AccountingChart::getView');
    $routes->get('view/(:entry)', 'AccountingChart::getView/$1');
    $routes->get('suggest_code/(:num)', 'AccountingChart::getSuggestCode/$1');
    $routes->post('save', 'AccountingChart::postSave');
    $routes->post('save/(:entry)', 'AccountingChart::postSave/$1');
    $routes->post('deactivate/(:num)', 'AccountingChart::postDeactivate/$1');
    $routes->post('activate/(:num)', 'AccountingChart::postActivate/$1');
    $routes->post('delete/(:num)', 'AccountingChart::postDeleteAccount/$1');
});

$routes->group('accounting_ai', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingAi::getIndex');
    $routes->get('proposal/(:num)', 'AccountingAi::getProposal/$1');
    $routes->get('approve_form/(:num)', 'AccountingAi::getApproveForm/$1');
    $routes->post('approve/(:num)', 'AccountingAi::postApprove/$1');
    $routes->get('reject_form/(:num)', 'AccountingAi::getRejectForm/$1');
    $routes->post('reject/(:num)', 'AccountingAi::postReject/$1');
    $routes->post('propose', 'AccountingAi::postPropose');
    $routes->post('scan', 'AccountingAi::postScan');
    $routes->get('findings', 'AccountingAi::getFindings');
    $routes->get('findings/csv', 'AccountingAi::getFindingsCsv');
    $reportFiles($routes, 'AccountingAi::getFindingsCsv', 'Accounting_ai.findings_title', 'findings/');
    $routes->get('decide_form/(:num)', 'AccountingAi::getDecideForm/$1');
    $routes->post('decide/(:num)', 'AccountingAi::postDecide/$1');
    $routes->get('settings', 'AccountingAi::getSettings');
    $routes->post('settings', 'AccountingAi::postSettings');
    $routes->post('disable', 'AccountingAi::postDisable');
});

$routes->group('accounting_cost_centers', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingCostCenters::getIndex');
    $routes->get('search', 'AccountingCostCenters::getSearch');
    $routes->get('row/(:num)', 'AccountingCostCenters::getRow/$1');
    $routes->get('view', 'AccountingCostCenters::getView');
    $routes->get('view/(:entry)', 'AccountingCostCenters::getView/$1');
    $routes->post('save', 'AccountingCostCenters::postSave');
    $routes->post('save/(:entry)', 'AccountingCostCenters::postSave/$1');
    $routes->get('defaults', 'AccountingCostCenters::getDefaults');
    $routes->get('defaults/(:num)', 'AccountingCostCenters::getDefaults/$1');
    $routes->post('save_defaults/(:num)', 'AccountingCostCenters::postSaveDefaults/$1');
    $routes->post('deactivate/(:num)', 'AccountingCostCenters::postDeactivate/$1');
    $routes->post('activate/(:num)', 'AccountingCostCenters::postActivate/$1');
    $routes->post('delete/(:num)', 'AccountingCostCenters::postDeleteCostCenter/$1');
});

$routes->group('accounting_currencies', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingCurrencies::getIndex');
    $routes->get('search', 'AccountingCurrencies::getSearch');
    $routes->get('row/(:segment)', 'AccountingCurrencies::getRow/$1');
    $routes->get('form', 'AccountingCurrencies::getForm');
    $routes->get('form/(:segment)', 'AccountingCurrencies::getForm/$1');
    $routes->get('rates/(:segment)', 'AccountingCurrencies::getRates/$1');
    $routes->get('rates/(:segment)/search', 'AccountingCurrencies::getRatesSearch/$1');
    $routes->get('rate_form/(:segment)', 'AccountingCurrencies::getRateForm/$1');
    $routes->get('rate_form/(:segment)/(:num)', 'AccountingCurrencies::getRateForm/$1/$2');
    $routes->post('store', 'AccountingCurrencies::postStore');
    $routes->post('store/(:segment)', 'AccountingCurrencies::postStore/$1');
    $routes->post('activate/(:segment)', 'AccountingCurrencies::postActivate/$1');
    $routes->post('deactivate/(:segment)', 'AccountingCurrencies::postDeactivate/$1');
    $routes->post('rate_store/(:segment)', 'AccountingCurrencies::postRateStore/$1');
    $routes->post('rate_store/(:segment)/(:num)', 'AccountingCurrencies::postRateStore/$1/$2');
    $routes->post('rate_delete/(:num)', 'AccountingCurrencies::postRateDelete/$1');
});

$routes->group('accounting_einvoicing', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingEInvoicing::getIndex');
    $routes->get('xml/(:num)', 'AccountingEInvoicing::getXml/$1');
    $routes->post('save_settings', 'AccountingEInvoicing::postSaveSettings');
    $routes->post('issue/(:num)', 'AccountingEInvoicing::postIssue/$1');
    $routes->post('issue_debit_note/(:num)', 'AccountingEInvoicing::postIssueDebitNote/$1');
});

$routes->group('accounting_journals', static function (RouteCollection $routes) use ($reportFiles): void {
    $routes->get('/', 'AccountingJournals::getIndex');
    $routes->get('search', 'AccountingJournals::getSearch');
    $routes->get('row/(:num)', 'AccountingJournals::getRow/$1');
    $routes->get('new', 'AccountingJournals::getNew');
    $routes->get('edit/(:num)', 'AccountingJournals::getEdit/$1');
    $routes->get('view/(:num)', 'AccountingJournals::getShow/$1');
    $routes->get('print/(:num)', 'AccountingJournals::getPrint/$1');
    $routes->get('pdf/(:num)', 'AccountingJournals::getPdf/$1');
    $routes->get('reverse_form/(:num)', 'AccountingJournals::getReverseForm/$1');
    $routes->get('edit_details/(:num)', 'AccountingJournals::getEditDetails/$1');
    $routes->get('attachment/(:num)', 'AccountingJournals::getAttachment/$1');
    $routes->get('audit', 'AccountingJournals::getAudit');
    $routes->get('audit/search', 'AccountingJournals::getAuditSearch');
    $routes->get('audit/csv', 'AccountingJournals::getAuditCsv');
    $reportFiles($routes, 'AccountingJournals::getAuditCsv', 'Accounting_journals.audit', 'audit/');
    $routes->get('failures', 'AccountingJournals::getFailures');
    $routes->get('failures/search', 'AccountingJournals::getFailuresSearch');
    $routes->post('save_draft', 'AccountingJournals::postSaveDraft');
    $routes->post('save_draft/(:num)', 'AccountingJournals::postSaveDraft/$1');
    $routes->post('post', 'AccountingJournals::postPost');
    $routes->post('post/(:num)', 'AccountingJournals::postPost/$1');
    $routes->post('post_drafts', 'AccountingJournals::postPostDrafts');
    $routes->post('delete_drafts', 'AccountingJournals::postDeleteDrafts');
    $routes->post('delete_draft/(:num)', 'AccountingJournals::postDeleteDraft/$1');
    $routes->post('reverse/(:num)', 'AccountingJournals::postReverse/$1');
    $routes->post('save_details/(:num)', 'AccountingJournals::postSaveDetails/$1');
    $routes->post('attach/(:num)', 'AccountingJournals::postAttach/$1');
    $routes->post('delete_attachment/(:num)', 'AccountingJournals::postDeleteAttachment/$1');
});

$routes->group('portal', static function (RouteCollection $routes): void {
    $routes->get('/', 'Portal::getIndex');
    $routes->get('login', 'Portal::getLogin');
    $routes->post('login', 'Portal::postLogin');
    $routes->post('logout', 'Portal::postLogout');
    $routes->get('password', 'Portal::getPassword');
    $routes->post('password', 'Portal::postPassword');
    $routes->get('statement', 'Portal::getStatement');
    $routes->get('invoices', 'Portal::getInvoices');
    $routes->get('credit_notes', 'Portal::getCreditNotes');
    $routes->get('payments', 'Portal::getPayments');
    $routes->get('invoice/(:num)', 'Portal::getInvoice/$1');
    $routes->get('invoice/(:num)/pdf', 'Portal::getInvoicePdf/$1');
    $routes->get('credit_note/(:num)', 'Portal::getCreditNote/$1');
    $routes->get('credit_note/(:num)/pdf', 'Portal::getCreditNotePdf/$1');
    $routes->get('payment/(:num)', 'Portal::getPayment/$1');
    $routes->get('payment/(:num)/pdf', 'Portal::getPaymentPdf/$1');
});

$routes->group('customer_portal', static function (RouteCollection $routes): void {
    $routes->post('create/(:num)', 'Customer_portal::postCreate/$1');
    $routes->post('reset/(:num)', 'Customer_portal::postReset/$1');
    $routes->post('status/(:num)', 'Customer_portal::postStatus/$1');
    $routes->post('enabled', 'Customer_portal::postEnabled');
});
