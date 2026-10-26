<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->setDefaultController('Login');

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
    $routes->get('view/(:segment)', 'Branches::getView/$1');
    $routes->post('save', 'Branches::postSave');
    $routes->post('save/(:segment)', 'Branches::postSave/$1');
    $routes->post('deactivate/(:num)', 'Branches::postDeactivate/$1');
    $routes->post('activate/(:num)', 'Branches::postActivate/$1');
    $routes->post('delete/(:num)', 'Branches::postDeleteBranch/$1');
});

$routes->group('accounting_settings', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingSetup::getIndex');
    $routes->post('save', 'AccountingSetup::postSaveSettings');
    $routes->post('first_year', 'AccountingSetup::postFirstYear');
    $routes->post('enable', 'AccountingSetup::postEnable');
    $routes->post('disable', 'AccountingSetup::postDisable');
});

$routes->group('accounting_routing', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingRouting::getIndex');
    $routes->post('save', 'AccountingRouting::postSaveRouting');
});

$routes->group('accounting_receivables', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingReceivables::getIndex');
    $routes->get('search', 'AccountingReceivables::getSearch');
    $routes->get('row/(:num)', 'AccountingReceivables::getRow/$1');
    $routes->get('customer/(:num)', 'AccountingReceivables::getCustomer/$1');
});

$routes->group('accounting_payables', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingPayables::getIndex');
    $routes->get('search', 'AccountingPayables::getSearch');
    $routes->get('row/(:num)', 'AccountingPayables::getRow/$1');
    $routes->get('supplier/(:num)', 'AccountingPayables::getSupplier/$1');
    $routes->get('payment_form/(:num)', 'AccountingPayables::getPaymentForm/$1');
    $routes->post('payment/(:num)', 'AccountingPayables::postPayment/$1');
    $routes->get('opening_form/(:num)', 'AccountingPayables::getOpeningForm/$1');
    $routes->post('opening/(:num)', 'AccountingPayables::postOpening/$1');
    $routes->get('cancel_form/(:segment)/(:num)', 'AccountingPayables::getCancelForm/$1/$2');
    $routes->post('cancel/(:segment)/(:num)', 'AccountingPayables::postCancel/$1/$2');
});

$routes->group('accounting_treasuries', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingTreasuries::getIndex');
    $routes->get('search', 'AccountingTreasuries::getSearch');
    $routes->get('row/(:num)', 'AccountingTreasuries::getRow/$1');
    $routes->get('statement/(:num)', 'AccountingTreasuries::getStatement/$1');
    $routes->get('view', 'AccountingTreasuries::getView');
    $routes->get('view/(:segment)', 'AccountingTreasuries::getView/$1');
    $routes->post('save', 'AccountingTreasuries::postSave');
    $routes->post('save/(:segment)', 'AccountingTreasuries::postSave/$1');
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
});

$routes->group('accounting_periods', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingPeriods::getIndex');
    $routes->get('search', 'AccountingPeriods::getSearch');
    $routes->get('row/(:num)', 'AccountingPeriods::getRow/$1');
    $routes->get('view', 'AccountingPeriods::getView');
    $routes->get('view/(:segment)', 'AccountingPeriods::getView/$1');
    $routes->post('save', 'AccountingPeriods::postSave');
    $routes->post('save/(:segment)', 'AccountingPeriods::postSave/$1');
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
});

$routes->group('accounting_chart', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingChart::getIndex');
    $routes->get('tree', 'AccountingChart::getTree');
    $routes->get('panel', 'AccountingChart::getPanel');
    $routes->get('search', 'AccountingChart::getSearch');
    $routes->get('row/(:num)', 'AccountingChart::getRow/$1');
    $routes->get('view', 'AccountingChart::getView');
    $routes->get('view/(:segment)', 'AccountingChart::getView/$1');
    $routes->get('suggest_code/(:num)', 'AccountingChart::getSuggestCode/$1');
    $routes->post('save', 'AccountingChart::postSave');
    $routes->post('save/(:segment)', 'AccountingChart::postSave/$1');
    $routes->post('deactivate/(:num)', 'AccountingChart::postDeactivate/$1');
    $routes->post('activate/(:num)', 'AccountingChart::postActivate/$1');
    $routes->post('delete/(:num)', 'AccountingChart::postDeleteAccount/$1');
});

$routes->group('accounting_cost_centers', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingCostCenters::getIndex');
    $routes->get('search', 'AccountingCostCenters::getSearch');
    $routes->get('row/(:num)', 'AccountingCostCenters::getRow/$1');
    $routes->get('view', 'AccountingCostCenters::getView');
    $routes->get('view/(:segment)', 'AccountingCostCenters::getView/$1');
    $routes->post('save', 'AccountingCostCenters::postSave');
    $routes->post('save/(:segment)', 'AccountingCostCenters::postSave/$1');
    $routes->post('deactivate/(:num)', 'AccountingCostCenters::postDeactivate/$1');
    $routes->post('activate/(:num)', 'AccountingCostCenters::postActivate/$1');
    $routes->post('delete/(:num)', 'AccountingCostCenters::postDeleteCostCenter/$1');
});

$routes->group('accounting_journals', static function (RouteCollection $routes): void {
    $routes->get('/', 'AccountingJournals::getIndex');
    $routes->get('search', 'AccountingJournals::getSearch');
    $routes->get('row/(:num)', 'AccountingJournals::getRow/$1');
    $routes->get('new', 'AccountingJournals::getNew');
    $routes->get('edit/(:num)', 'AccountingJournals::getEdit/$1');
    $routes->get('view/(:num)', 'AccountingJournals::getShow/$1');
    $routes->get('reverse_form/(:num)', 'AccountingJournals::getReverseForm/$1');
    $routes->get('audit', 'AccountingJournals::getAudit');
    $routes->get('audit/search', 'AccountingJournals::getAuditSearch');
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
});
