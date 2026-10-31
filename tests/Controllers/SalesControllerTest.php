<?php

namespace Tests\Controllers;

use App\Controllers\Sales;
use CodeIgniter\Database\Config;
use ReflectionMethod;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Config\Services;
use App\Libraries\Accounting\InvoiceTiming;
use App\Libraries\Accounting\QuoteStatus;
use App\Libraries\Accounting\SaleDocumentList;
use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use App\Models\Employee;
use CodeIgniter\Test\TestResponse;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\SaleFixtureTrait;

/**
 * Regression tests for the Sales per-endpoint access-control bypass.
 *
 * A cashier holding only the base "sales" grant (no "reports_sales") must
 * not be able to reach the per-sale endpoints that getManage() gates
 * behind reports_sales: getSearch, getRow, getEdit, postSave, getReceipt,
 * getInvoice, getSendPdf, getSendReceipt.
 *
 * Also covers: getSearch() (the AJAX endpoint that
 * supplies every row of the Sales Takings list) was the one sibling that
 * returned the full ledger and was missing the reports_sales check.
 */
class SalesControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;
    use EmployeeFixtureTrait;
    use SaleAccountingFixtureTrait;
    use SaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $seedOnce    = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private static bool $doneBootstrap = false;

    protected function setUp(): void
    {
        if (self::$doneBootstrap === false) {
            Config::seeder($this->DBGroup)->call('App\Database\Seeds\TestDatabaseBootstrapSeeder');
            Config::connect($this->DBGroup)->close();

            self::$doneBootstrap = true;
        }

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        $db = Database::connect();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

        parent::tearDown();
    }

    /**
     * Cashier with bare "sales" + "sales_stock" grants. "sales_stock" is
     * required alongside "sales": Employee::has_module_grant('sales', ...)
     * treats the bare "sales" grant as insufficient once any sales_*
     * submodule permission exists in the permissions table (see
     * has_subpermissions()). Deliberately NOT "reports_sales" — that is the
     * grant this test exercises the gate on.
     */
    protected function createCashierEmployee(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'NoReports',
            email:      'cashier.' . uniqid() . '@test.com',
            username:   'cashier_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
            ],
        );
    }

    protected function createReportsSalesEmployee(): int
    {
        return $this->createEmployee(
            first_name: 'Supervisor',
            last_name:  'WithReports',
            email:      'supervisor.' . uniqid() . '@test.com',
            username:   'supervisor_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
                ['permission_id' => 'reports_sales', 'menu_group' => 'home'],
            ],
        );
    }

    protected function createCashierWithoutChangePriceGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'NoChangePrice',
            email:      'cashier-nochangeprice.' . uniqid() . '@test.com',
            username:   'cashier_nochangeprice_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
            ],
        );
    }

    protected function createCashierWithChangePriceGrant(): int
    {
        return $this->createEmployee(
            first_name: 'Cashier',
            last_name:  'ChangePrice',
            email:      'cashier-changeprice.' . uniqid() . '@test.com',
            username:   'cashier_changeprice_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => BranchGuard::ALL_BRANCHES, 'menu_group' => '--'],
                ['permission_id' => 'sales_change_price', 'menu_group' => 'home'],
            ],
        );
    }

    protected function loginAs(int $personId): void
    {
        $this->withSession([
            'person_id'  => $personId,
            'menu_group' => 'home',
        ]);
    }

    /**
     * Seeds a single cart line directly in the session, mirroring the shape
     * Sale_lib::add_item() produces, so tests can target postEditItem's
     * authorization branch without exercising the full add-item flow.
     */
    protected function seedCartLine(int $line, string $price, int $itemId): void
    {
        $this->withSession(array_merge($this->session, [
            'sales_cart' => [
                $line => [
                    'item_id'               => $itemId,
                    'item_location'         => 1,
                    'stock_name'            => 'Test Location',
                    'line'                  => $line,
                    'name'                  => 'Test Item',
                    'item_number'           => 'TEST-1',
                    'attribute_values'      => null,
                    'attribute_dtvalues'    => null,
                    'description'           => 'Test Item',
                    'serialnumber'          => '',
                    'allow_alt_description' => false,
                    'is_serialized'         => false,
                    'quantity'              => '1',
                    'discount'              => '0',
                    'discount_type'         => 0,
                    'in_stock'              => '10',
                    'price'                 => $price,
                    'cost_price'            => '1.00',
                    'total'                 => $price,
                    'discounted_total'      => $price,
                    'print_option'          => PRINT_YES,
                    'stock_type'            => HAS_NO_STOCK,
                    'item_type'             => ITEM,
                    'hsn_code'              => null,
                    'tax_category_id'       => null,
                ],
            ],
        ]));
    }

    public function testCashierWithoutReportsSalesCannotGetRow(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/row/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotGetSearch(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/search');

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.not_authorized'), $result['message']);
    }

    public function testCashierWithoutReportsSalesCannotGetEdit(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/edit/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotGetReceipt(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/receipt/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotGetInvoice(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/invoice/' . $saleId);

        $response->assertRedirect();
        $this->assertStringContainsString('no_access', $response->getRedirectUrl());
    }

    public function testCashierWithoutReportsSalesCannotSendPdf(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/sendpdf/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotSendReceipt(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get('/sales/sendreceipt/' . $saleId);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
    }

    public function testCashierWithoutReportsSalesCannotPostSave(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->post('/sales/save/' . $saleId, [
            'date'              => date('m/d/Y H:i:s'),
            'customer_id'       => '',
            'employee_id'       => $cashierId,
            'comment'           => 'tampered',
            'invoice_number'    => '',
            'number_of_payments'=> 0,
            'payment_type_new'  => '--',
            'payment_amount_new'=> ''
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.not_authorized'), $result['message']);
    }

    public function testEmployeeWithReportsSalesCanGetSearch(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/search');

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertArrayNotHasKey('success', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('rows', $result);
        $this->assertArrayHasKey('payment_summary', $result);
    }

    public function testEmployeeWithReportsSalesCanGetRow(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/row/' . $saleId);

        $response->assertStatus(200);
        $result = json_decode($response->getJSON(), true);
        $this->assertArrayNotHasKey('success', $result);
    }

    public function testEmployeeWithReportsSalesCanGetEdit(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get('/sales/edit/' . $saleId);

        $response->assertStatus(200);
    }

    public function testCashierWithoutGrantCannotChangePrice(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '0.01',
            'quantity'     => '1',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.not_authorized'));

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('5.00', $cart[1]['price']);
    }

    public function testCashierWithoutGrantCanEditQuantityAtSamePrice(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '5.00',
            'quantity'     => '3',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('3', $cart[1]['quantity']);
        $this->assertEquals('5.00', $cart[1]['price']);
    }

    public function testCashierWithGrantCanChangePrice(): void
    {
        $cashierId = $this->createCashierWithChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->post('/sales/editItem/1', [
            'description'  => 'Test Item',
            'serialnumber' => '',
            'price'        => '0.01',
            'quantity'     => '1',
            'discount'     => '0',
            'location'     => '1',
        ]);

        $response->assertStatus(200);

        $session = Services::session();
        $cart = $session->get('sales_cart');
        $this->assertEquals('0.01', $cart[1]['price']);
    }

    public function testPostAddPaymentRejectsNegativeAmountTendered(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem();
        $this->seedCartLine(1, '1.00', $itemId);

        $forgedPaymentType = lang('Sales.giftcard') . ':AUDIT-100';

        $response = $this->post('/sales/addPayment', [
            'payment_type'    => $forgedPaymentType,
            'amount_tendered' => '-500',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.must_enter_numeric'));

        // ... and the negative payment must NOT have been added to the cart.
        $session  = Services::session();
        $payments = $session->get('sales_payments');
        $this->assertArrayNotHasKey($forgedPaymentType, (array) $payments);
    }

    public function testPostAddPaymentRejectsMalformedAmountTendered(): void
    {
        $cashierId = $this->createCashierWithoutChangePriceGrant();
        $this->loginAs($cashierId);
        $itemId = $this->createTestItem();
        $this->seedCartLine(1, '1.00', $itemId);

        $response = $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => 'ABC123',
        ]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.must_enter_numeric'));

        $session  = Services::session();
        $payments = $session->get('sales_payments');
        $this->assertArrayNotHasKey(lang('Sales.cash'), (array) $payments);
    }

    public function testCashierWithoutReportsSalesCannotUnsuspend(): void
    {
        $victimId = $this->createReportsSalesEmployee();
        $saleId = $this->createSuspendedSale($victimId);

        $cashierId = $this->createCashierEmployee();
        $this->loginAs($cashierId);

        $response = $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $response->assertStatus(403);
        $result = json_decode($response->getJSON(), true);
        $this->assertFalse($result['success']);

        $session = Services::session();
        $this->assertNotEquals($saleId, $session->get('sale_id'));
    }

    public function testEmployeeWithReportsSalesCannotUnsuspendCompletedSale(): void
    {
        $victimId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($victimId);

        $supervisorId = $this->createReportsSalesEmployee();
        $this->loginAs($supervisorId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $session = Services::session();
        $this->assertNotEquals($saleId, $session->get('sale_id'));
    }

    public function testEmployeeWithReportsSalesCanUnsuspendSale(): void
    {
        $ownerId = $this->createReportsSalesEmployee();
        $saleId = $this->createSuspendedSale($ownerId);

        $supervisorId = $this->createReportsSalesEmployee();
        $this->loginAs($supervisorId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => $saleId,
        ]);

        $session = Services::session();
        $this->assertEquals($saleId, $session->get('sale_id'));
    }

    protected function createGiftcard(float $value): int
    {
        $giftcardNumber = random_int(1000000, 9999999);

        Database::connect()->table('giftcards')->insert([
            'giftcard_number' => $giftcardNumber,
            'value'           => $value,
            'deleted'         => 0,
            'person_id'       => null,
        ]);

        return $giftcardNumber;
    }

    protected function getGiftcardValue(int $giftcardNumber): float
    {
        $row = Database::connect()->table('giftcards')
            ->where('giftcard_number', $giftcardNumber)
            ->get()
            ->getRow();

        return (float) $row->value;
    }

    public function testCashierCannotForgeGiftcardPaymentTypeWithNegativeAmount(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $giftcardNumber = $this->createGiftcard(100.00);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.giftcard') . ':' . $giftcardNumber,
            'amount_tendered' => '-500',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
        $this->assertEqualsWithDelta(100.00, $this->getGiftcardValue($giftcardNumber), 0.001);
    }

    public function testCashierCanUseLegitimateGiftcardPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $giftcardNumber = $this->createGiftcard(100.00);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.giftcard'),
            'amount_tendered' => (string) $giftcardNumber,
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.giftcard') . ':' . $giftcardNumber, $payments);
    }

    public function testCashierCannotSubmitNegativeAmountForReferenceCodePayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.debit'),
            'amount_tendered' => '-25',
            'reference_code'  => 'ABC123',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
    }

    public function testCashierCanUseLegitimateReferenceCodePayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.debit'),
            'amount_tendered' => '25.00',
            'reference_code'  => 'ABC123',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.debit'), $payments);
        $this->assertSame('ABC123', $payments[lang('Sales.debit')]['reference_code']);
    }

    public function testCashierCannotSubmitNegativeAmountForCashPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => '-10',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertEmpty($payments);
    }

    public function testCashierCanUseLegitimateCashPayment(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => '10.00',
        ]);

        $payments = Services::session()->get('sales_payments');
        $this->assertNotEmpty($payments);
        $this->assertArrayHasKey(lang('Sales.cash'), $payments);
    }

    public function testPostCompleteRejectsSaleWithInsufficientPayments(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore, $salesCountAfter);
    }

    public function testPostCompleteAllowsZeroPaymentQuoteCompletion(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_quote']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore + 1, $salesCountAfter);
        $response->assertDontSee(lang('Sales.amount_due_not_covered'));
    }

    public function testPostCompleteAllowsZeroPaymentInvoiceCompletion(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_invoice']));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $salesCountAfter = Database::connect()->table('sales')->countAllResults();
        $this->assertSame($salesCountBefore + 1, $salesCountAfter);
        $response->assertDontSee(lang('Sales.amount_due_not_covered'));
    }

    public function testRegisterEscapesMaliciousTaxName(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem();

        Database::connect()->table('items_taxes')->insert([
            'item_id' => $itemId,
            'name'    => '<svg onload=alert(1)>',
            'percent' => 5,
        ]);

        $this->loginAs($cashierId);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $body = $response->getBody();
        $this->assertStringNotContainsString('<svg onload', $body);
        $this->assertStringContainsString('&lt;svg', $body);
    }

    public function testGetPdf_ReturnsPdfDocumentForEmployeeWithReportsSales(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get("/sales/pdf/{$saleId}/invoice");

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->response()->getBody());
    }

    public function testGetPdf_RejectsCashierWithoutReportsSales(): void
    {
        $cashierId = $this->createCashierEmployee();
        $saleId = $this->createSale($cashierId);
        $this->loginAs($cashierId);

        $response = $this->get("/sales/pdf/{$saleId}/invoice");

        $response->assertStatus(403);
    }

    public function testGetPdf_RejectsUnknownDocumentType(): void
    {
        $supervisorId = $this->createReportsSalesEmployee();
        $saleId = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->get("/sales/pdf/{$saleId}/secret");

        $response->assertStatus(404);
    }

    public function testGetIndex_OffersTopSellersAsTiles(): void
    {
        $cashierId = $this->createCashierEmployee();
        $this->createSale($cashierId);
        cache()->delete('sales_quick_items_' . DEFAULT_QUICK_ITEMS);
        $this->loginAs($cashierId);

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $response->assertSeeElement('.pos-top-tile');
    }

    public function testGetIndex_RendersPosLayoutWithTotalBar(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '5.00', $itemId);

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $response->assertSeeElement('#pos');
        $response->assertSeeElement('#pos_total_bar');
        $response->assertSeeElement('#payment_card');
    }

    public function testGetIndex_RequiresCoveringPaymentBeforeOfferingCompleteSale(): void
    {
        $cashierId = $this->createCashierEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($cashierId);
        $this->seedCartLine(1, '5.00', $itemId);

        $this->assertStringNotContainsString('id="finish_sale_button"', $this->get('/sales')->getBody());

        $this->post('/sales/addPayment', [
            'payment_type'    => lang('Sales.cash'),
            'amount_tendered' => '10.00',
        ]);

        $this->withSession(array_merge($this->session, ['sales_payments' => Services::session()->get('sales_payments')]));

        $this->assertStringContainsString('id="finish_sale_button"', $this->get('/sales')->getBody());
    }

    public function testPostUnsuspendRejectsNonSuspendedSaleWithoutClearingActiveCart(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $notSuspendedSaleId = $this->createSale($employeeId);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '1.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => (string) $notSuspendedSaleId,
        ]);

        $cart = Services::session()->get('sales_cart');
        $this->assertNotEmpty($cart);
        $this->assertArrayHasKey(1, $cart);
        $this->assertSame($itemId, $cart[1]['item_id']);
        $this->assertSame(NEW_ENTRY, Services::session()->get('sale_id'));
    }

    public function testPostUnsuspendReplacesCartForValidSuspendedSale(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $suspendedSaleId = $this->createSuspendedSale($employeeId);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '1.00', $itemId);

        $this->post('/sales/unsuspend', [
            'suspended_sale_id' => (string) $suspendedSaleId,
        ]);

        $this->assertSame($suspendedSaleId, Services::session()->get('sale_id'));
        $cart = Services::session()->get('sales_cart');
        $this->assertNotEmpty($cart);
        $this->assertNotSame($itemId, array_values($cart)[0]['item_id']);
    }

    public function testPostUnsuspend_BringsBackTheTreasuryOfThePaymentsOfTheSuspendedSale(): void
    {
        $employeeId      = $this->createReportsSalesEmployee();
        $drawer          = $this->treasuryOfBranch('Suspended drawer');
        $suspendedSaleId = $this->createSuspendedSale($employeeId);
        $this->accountingDb()->table('sales_payments')->insert([
            'sale_id'         => $suspendedSaleId,
            'payment_type'    => 'Cash',
            'payment_amount'  => '5.00',
            'cash_refund'     => 0,
            'cash_adjustment' => 0,
            'employee_id'     => $employeeId,
            'treasury_id'     => $drawer,
        ]);
        $this->loginAs($employeeId);

        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $suspendedSaleId]);

        $this->assertSame($drawer, (int) Services::session()->get('sales_payments')['Cash']['treasury_id']);
    }

    public function testPostSuspend_KeepsTheRefundInvoiceOfASuspendedReturnWhenItIsTakenBack(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return', 'sale_type' => SALE_TYPE_RETURN, 'sales_return_document' => 'refund_invoice']));

        $this->post('/sales/suspend');

        $suspended = Database::connect()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow();

        $this->assertSame((string) SUSPENDED, (string) $suspended->sale_status);
        $this->assertSame('refund_invoice', $suspended->return_document);
        $this->assertNull(Services::session()->get('sales_return_document'));

        $this->loginAs($employeeId);
        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $suspended->sale_id]);

        $this->assertSame('refund_invoice', Services::session()->get('sales_return_document'));
        $this->assertSame('return', Services::session()->get('sales_mode'));
    }

    public function testPostSuspend_KeepsASuspendedReturnACreditNoteWhenNoDocumentWasChosen(): void
    {
        $employeeId = $this->createReportsSalesEmployee();
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return', 'sale_type' => SALE_TYPE_RETURN]));

        $this->post('/sales/suspend');

        $suspended = Database::connect()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow();

        $this->assertSame((string) SUSPENDED, (string) $suspended->sale_status);
        $this->assertNull($suspended->return_document);

        $this->loginAs($employeeId);
        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $suspended->sale_id]);

        $this->assertNull(Services::session()->get('sales_return_document'));
    }

    public function testPostComplete_IssuesAReturnThatWasSuspendedAsARefundInvoiceWithANumberOfItsOwnSequence(): void
    {
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5);
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5);

        $employeeId = $this->createReportsSalesEmployee();
        $itemId     = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($employeeId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return', 'sale_type' => SALE_TYPE_RETURN, 'sales_return_document' => 'refund_invoice']));
        $this->post('/sales/suspend');

        $suspendedId = (int) Database::connect()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow()->sale_id;

        $this->loginAs($employeeId);
        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $suspendedId]);
        $this->withSession(array_merge($this->session, Services::session()->get()));

        $response = $this->post('/sales/complete');

        $response->assertSee('RI-00001');
        $response->assertDontSee('id="credit_note_number"');
        $this->assertSame('refund_invoice', Database::connect()->table('sales')->where('sale_id', $suspendedId)->get()->getRow()->return_document);
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::RefundInvoice)->nextNumber);
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::CreditNote)->nextNumber);
    }

    /**
     * A cashier who also holds the report and delete grants but works only in the given branch.
     */
    protected function createBranchLimitedSupervisor(int $branchId, array $extraGrants = []): int
    {
        $personId = $this->createEmployee(
            first_name: 'Branch',
            last_name:  'Supervisor',
            email:      'branch.' . uniqid() . '@test.com',
            username:   'branch_' . uniqid(),
            grants: [
                ['permission_id' => 'sales', 'menu_group' => 'home'],
                ['permission_id' => 'sales_stock', 'menu_group' => 'home'],
                ['permission_id' => 'sales_delete', 'menu_group' => 'home'],
                ['permission_id' => 'reports_sales', 'menu_group' => 'home'],
                ...array_map(static fn (string $permissionId): array => ['permission_id' => $permissionId, 'menu_group' => 'home'], $extraGrants),
            ],
        );

        Database::connect()->table('employee_branches')->insert(['person_id' => $personId, 'branch_id' => $branchId, 'is_default' => 1]);

        return $personId;
    }

    /**
     * A stock location in the branch that the employee may sell from.
     */
    protected function grantStockLocationIn(int $branchId, int $personId): int
    {
        $db   = Database::connect();
        $name = 'depot' . uniqid();

        $db->table('stock_locations')->insert(['location_name' => $name, 'deleted' => 0, 'branch_id' => $branchId]);
        $locationId = (int) $db->insertID();

        foreach (['items', 'sales'] as $module) {
            $db->table('permissions')->insert(['permission_id' => "{$module}_{$name}", 'module_id' => $module, 'location_id' => $locationId]);
            $db->table('grants')->insert(['permission_id' => "{$module}_{$name}", 'person_id' => $personId, 'menu_group' => 'home']);
        }

        return $locationId;
    }

    protected function createSecondBranch(): int
    {
        $db = Database::connect();
        $db->table('branches')->insert(['company_id' => 1, 'code' => 'T' . random_int(100, 999), 'name' => 'Test branch', 'active' => 1]);

        return (int) $db->insertID();
    }

    public function testGetReceipt_HidesASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $supervisorId = $this->createBranchLimitedSupervisor($this->createSecondBranch());
        $saleId       = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $this->expectException(PageNotFoundException::class);

        $this->get('/sales/receipt/' . $saleId);
    }

    public function testGetReceipt_ShowsASaleOfTheBranchTheEmployeeWorksIn(): void
    {
        $branchId     = $this->createSecondBranch();
        $supervisorId = $this->createBranchLimitedSupervisor($branchId);
        $saleId       = $this->createSale($supervisorId);
        Database::connect()->table('sales')->where('sale_id', $saleId)->update(['branch_id' => $branchId]);
        $this->loginAs($supervisorId);

        $this->get('/sales/receipt/' . $saleId)->assertStatus(200);
    }

    public function testGetEdit_HidesASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $supervisorId = $this->createBranchLimitedSupervisor($this->createSecondBranch());
        $saleId       = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $this->expectException(PageNotFoundException::class);

        $this->get('/sales/edit/' . $saleId);
    }

    public function testPostSave_RefusesASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $supervisorId = $this->createBranchLimitedSupervisor($this->createSecondBranch());
        $saleId       = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->post('/sales/save/' . $saleId, ['date' => date('d/m/Y H:i:s'), 'comment' => 'changed']);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.branch_not_allowed'), json_decode($response->getJSON(), true)['message']);
        $this->assertSame('test sale', Database::connect()->table('sales')->where('sale_id', $saleId)->get()->getRow()->comment);
    }

    public function testPostDelete_RefusesASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $supervisorId = $this->createBranchLimitedSupervisor($this->createSecondBranch());
        $saleId       = $this->createSale($supervisorId);
        $this->loginAs($supervisorId);

        $response = $this->post('/sales/delete', ['ids' => [(string) $saleId]]);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.branch_not_allowed'), json_decode($response->getJSON(), true)['message']);
        $this->assertSame(COMPLETED, (int) Database::connect()->table('sales')->where('sale_id', $saleId)->get()->getRow()->sale_status);
    }

    public function testGetIndex_SendsAnEmployeeWhoseBranchesHaveNoStockLocationToTheNoAccessPage(): void
    {
        $supervisorId = $this->createBranchLimitedSupervisor($this->createSecondBranch());
        $this->loginAs($supervisorId);

        $response = $this->get('/sales');

        $response->assertStatus(302);
        $response->assertRedirectTo('no_access/sales/sales_stock');
    }

    public function testPostComplete_RefusesACartThatSellsFromAnotherBranch(): void
    {
        $branchId     = $this->createSecondBranch();
        $supervisorId = $this->createBranchLimitedSupervisor($branchId);
        $this->grantStockLocationIn($branchId, $supervisorId);
        $itemId = $this->createTestItem(HAS_NO_STOCK);
        $this->loginAs($supervisorId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $salesCountBefore = Database::connect()->table('sales')->countAllResults();

        $response = $this->post('/sales/complete');

        $this->assertSame($salesCountBefore, Database::connect()->table('sales')->countAllResults());
        $response->assertSee(lang('Sales.branch_not_allowed'));
    }

    // ---------------------------------------------------------------------------------------
    // Sales that are in the books: who may touch them and what they are told when the books refuse
    // ---------------------------------------------------------------------------------------

    /**
     * @return array{saleId: int, cream: int, perfume: int}
     */
    protected function postedInvoice(bool $delivered = true, int $type = SALE_TYPE_INVOICE): array
    {
        $this->startSelling();

        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], $type, -1, $delivered);

        return ['saleId' => $saleId, 'cream' => $cycle['cream'], 'perfume' => $cycle['perfume']];
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonOf($response): array
    {
        return json_decode($response->getJSON(), true);
    }

    public function testPostConfirmDelivery_DeliversTheUndeliveredInvoiceOfTheEmployeesBranch(): void
    {
        $invoice      = $this->postedInvoice(false);
        $stock        = $this->stock($invoice['cream']);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->loginAs($supervisorId);

        $response = $this->post('/sales/confirmDelivery/' . $invoice['saleId']);

        $this->assertTrue($this->jsonOf($response)['success']);
        $this->assertSame('1', (string) $this->saleRow($invoice['saleId'])->delivered);
        $this->assertEqualsWithDelta($stock - 4, $this->stock($invoice['cream']), 0.0001);
        $this->assertCount(1, $this->standing($invoice['saleId'], 'cogs'));
    }

    public function testPostConfirmDelivery_RefusesASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $invoice      = $this->postedInvoice(false);
        $stock        = $this->stock($invoice['cream']);
        $supervisorId = $this->createBranchLimitedSupervisor($this->createSecondBranch());
        $this->loginAs($supervisorId);

        $response = $this->post('/sales/confirmDelivery/' . $invoice['saleId']);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.branch_not_allowed'), $this->jsonOf($response)['message']);
        $this->assertSame('0', (string) $this->saleRow($invoice['saleId'])->delivered);
        $this->assertEqualsWithDelta($stock, $this->stock($invoice['cream']), 0.0001);
        $this->assertSame([], $this->standing($invoice['saleId'], 'cogs'));
    }

    public function testPostConfirmDelivery_SaysWhyTheBooksRefusedAndDeliversNothing(): void
    {
        $invoice      = $this->postedInvoice(false);
        $stock        = $this->stock($invoice['cream']);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->closeThrough(date('Y-m-d'));
        $this->loginAs($supervisorId);

        $result = $this->jsonOf($this->post('/sales/confirmDelivery/' . $invoice['saleId']));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString(date('Y-m-d'), $result['message']);
        $this->assertNotSame(lang('Sales.transaction_failed'), $result['message']);
        $this->assertSame('0', (string) $this->saleRow($invoice['saleId'])->delivered);
        $this->assertEqualsWithDelta($stock, $this->stock($invoice['cream']), 0.0001);
    }

    public function testPostConfirmDelivery_RefusesWhatIsNotAnIssuedInvoice(): void
    {
        $this->startSelling();
        $item = $this->item('20.00');
        $pos  = $this->save([$this->line($item, 0, '1', '50', '20.00')], [], [$this->payment(lang('Sales.cash'), '50.00')], SALE_TYPE_POS);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/confirmDelivery/' . $pos));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.delivery_not_possible'), $result['message']);
    }

    public function testPostDelete_CancelsASaleOfTheEmployeesBranchAndReversesItsJournals(): void
    {
        $invoice      = $this->postedInvoice(true, SALE_TYPE_POS);
        $stock        = $this->stock($invoice['cream']);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->loginAs($supervisorId);

        $result = $this->jsonOf($this->post('/sales/delete', ['ids' => [(string) $invoice['saleId']]]));

        $this->assertTrue($result['success']);
        $this->assertSame([], $this->standing($invoice['saleId']));
        $this->assertEqualsWithDelta($stock + 4, $this->stock($invoice['cream']), 0.0001);
        $this->assertSame('0.00', $this->balance($this->customerAccount()));
    }

    public function testPostDelete_RefusesASaleOfAnotherBranchAndLeavesItsJournalsAndStock(): void
    {
        $invoice      = $this->postedInvoice();
        $stock        = $this->stock($invoice['cream']);
        $supervisorId = $this->createBranchLimitedSupervisor($this->createSecondBranch());
        $this->loginAs($supervisorId);

        $response = $this->post('/sales/delete', ['ids' => [(string) $invoice['saleId']]]);

        $response->assertStatus(403);
        $this->assertCount(2, $this->standing($invoice['saleId']));
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($invoice['saleId'])->sale_status);
        $this->assertEqualsWithDelta($stock, $this->stock($invoice['cream']), 0.0001);
    }

    /**
     * A point of sale sale paid in cash into a treasury that only an employee other than the
     * one given may withdraw from.
     *
     * @return array{saleId: int, drawer: int}
     */
    private function saleIntoTreasuryClosedToWithdrawals(): array
    {
        $this->startSelling();

        $drawer  = (new TreasuryService())->create(['name' => 'Closed drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->createCashierEmployee()]]);
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00') + ['treasury_id' => $drawer]], SALE_TYPE_POS);

        return ['saleId' => $saleId, 'drawer' => $drawer];
    }

    public function testPostDelete_RefusesToCancelASaleWhosePaymentWentIntoATreasuryTheEmployeeMayNotWithdrawFrom(): void
    {
        $sale = $this->saleIntoTreasuryClosedToWithdrawals();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/delete', ['ids' => [(string) $sale['saleId']]]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Closed drawer', $result['message']);
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($sale['saleId'])->sale_status);
        $this->assertSame('50.0000', (new TreasuryService())->position($sale['drawer'])->balance);
    }

    public function testPostCancel_RefusesToCancelACompletedSaleWhosePaymentWentIntoATreasuryTheEmployeeMayNotWithdrawFrom(): void
    {
        $sale = $this->saleIntoTreasuryClosedToWithdrawals();
        $this->loginAs($this->createBranchLimitedSupervisor(1));
        $this->withSession(array_merge($this->session, ['sale_id' => $sale['saleId'], 'sale_type' => SALE_TYPE_POS]));

        $this->post('/sales/cancel');

        $this->assertSame((string) COMPLETED, (string) $this->saleRow($sale['saleId'])->sale_status);
        $this->assertSame('50.0000', (new TreasuryService())->position($sale['drawer'])->balance);
    }

    public function testPostCancel_CancelsACompletedSaleWhosePaymentWentIntoATreasuryTheEmployeeMayWithdrawFrom(): void
    {
        $this->startSelling();

        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $drawer       = (new TreasuryService())->create(['name' => 'Open drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$supervisorId]]);
        $service      = $this->item('0', false);
        $saleId       = $this->save([$this->line($service, 0, '1', '50')], [], [$this->payment(lang('Sales.cash'), '50.00') + ['treasury_id' => $drawer]], SALE_TYPE_POS);

        $this->loginAs($supervisorId);
        $this->withSession(array_merge($this->session, ['sale_id' => $saleId, 'sale_type' => SALE_TYPE_POS]));
        $this->post('/sales/cancel');

        $this->assertSame((string) CANCELED, (string) $this->saleRow($saleId)->sale_status);
        $this->assertSame('0.0000', (new TreasuryService())->position($drawer)->balance);
    }

    public function testPostDelete_SaysAnIssuedInvoiceIsCorrectedByACreditNoteAndCancelsNothing(): void
    {
        $invoice      = $this->postedInvoice();
        $stock        = $this->stock($invoice['cream']);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->loginAs($supervisorId);

        $result = $this->jsonOf($this->post('/sales/delete', ['ids' => [(string) $invoice['saleId']]]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_issued_document_locked'), $result['message']);
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($invoice['saleId'])->sale_status);
        $this->assertEqualsWithDelta($stock, $this->stock($invoice['cream']), 0.0001);
        $this->assertCount(2, $this->standing($invoice['saleId']));
    }

    public function testPostDelete_SaysWhyTheBooksRefusedTheCancellationAndCancelsNothing(): void
    {
        $invoice      = $this->postedInvoice(true, SALE_TYPE_POS);
        $stock        = $this->stock($invoice['cream']);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->closeThrough('2035-12-31');
        $this->loginAs($supervisorId);

        $result = $this->jsonOf($this->post('/sales/delete', ['ids' => [(string) $invoice['saleId']]]));

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('2035-12-31', $result['message']);
        $this->assertSame((string) COMPLETED, (string) $this->saleRow($invoice['saleId'])->sale_status);
        $this->assertEqualsWithDelta($stock, $this->stock($invoice['cream']), 0.0001);
        $this->assertCount(2, $this->standing($invoice['saleId']));
    }

    public function testGetEdit_ShowsWhatIsOwedAndTheStatusOfEachPaymentOfAnIssuedInvoice(): void
    {
        $invoice = $this->postedInvoice();
        $this->addPayment($invoice['saleId'], '300.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertStatus(200);
        $response->assertSee('id="receivable_figures"');
        $response->assertSee('id="receivable_status"');
        $response->assertSee(lang('Sales.invoice_partial'));
        $response->assertSee('name="payment_status_0"');

        foreach (['completed', 'review', 'failed', 'draft', 'incomplete'] as $status) {
            $response->assertSee('value="' . $status . '"');
            $response->assertSee(lang('Sales.payment_status_' . $status));
        }
    }

    public function testGetEdit_OffersACreditNoteInsteadOfCancellingAnIssuedInvoice(): void
    {
        $invoice = $this->postedInvoice();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertSee('id="issued_lock"');
        $response->assertSee('id="create_credit_note"');
        $response->assertSee(lang('Sales.credit_note_help_issued'));
        $response->assertSee("$('button#delete').hide()");
    }

    public function testGetEdit_KeepsCancellationForAPointOfSaleSale(): void
    {
        $sale = $this->postedInvoice(true, SALE_TYPE_POS);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $sale['saleId']);

        $response->assertDontSee('id="issued_lock"');
        $response->assertDontSee('id="create_credit_note"');
        $response->assertDontSee("$('button#delete').hide()");
    }

    public function testGetEdit_SaysToConfirmTheDeliveryBeforeACreditNoteWhenTheGoodsDidNotLeave(): void
    {
        $invoice = $this->postedInvoice(false);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertSee('id="issued_lock"');
        $response->assertSee(lang('Sales.credit_note_after_delivery'));
        $response->assertDontSee('id="create_credit_note"');
    }

    public function testGetEdit_OffersTheNewPaymentRowOnAnInvoicePaidInFull(): void
    {
        $service = $this->item('0', false);
        $this->startSelling();
        $saleId = $this->save([$this->line($service, 0, '1', '100')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/edit/' . $saleId)->assertSee('name="payment_amount_new"');
    }

    public function testGetEdit_LeavesTheAccountingBlocksOutWhileAccountingIsOff(): void
    {
        $invoice = $this->postedInvoice();
        $this->setConfigValue('accounting_enabled', '0');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertStatus(200);
        $response->assertDontSee('id="receivable_figures"');
        $response->assertDontSee('id="issued_lock"');
        $response->assertDontSee('name="payment_status_0"');
    }

    public function testPostCreditNote_PreparesTheRegisterForWhatHasNotComeBackOfTheInvoice(): void
    {
        $invoice = $this->postedInvoice();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $first = $this->jsonOf($this->post('/sales/creditNote/' . $invoice['saleId']));

        $this->assertTrue($first['success']);
        $this->assertSame((string) $invoice['saleId'], (string) Services::session()->get('sales_return_origin'));
        $this->assertSame('return', Services::session()->get('sales_mode'));
        $this->assertSame((string) $this->customerId, (string) Services::session()->get('sales_customer'));

        $quantities = array_map(static fn (array $line): string => (string) $line['quantity'], array_values(Services::session()->get('sales_cart')));

        $this->assertSame(['-4', '-2'], array_map(static fn (string $quantity): string => rtrim(rtrim($quantity, '0'), '.'), $quantities));
    }

    public function testPostCreditNote_TakesOnlyWhatHasNotComeBackYet(): void
    {
        $invoice = $this->postedInvoice();
        $this->save([$this->line($invoice['perfume'], 0, '-1', '220', '120.00')], [$this->tax($invoice['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $invoice['saleId']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/creditNote/' . $invoice['saleId']));

        $this->assertTrue($result['success']);

        $quantities = [];

        foreach (Services::session()->get('sales_cart') as $line) {
            $quantities[(int) $line['item_id']] = rtrim(rtrim((string) $line['quantity'], '0'), '.');
        }

        $this->assertSame([$invoice['cream'] => '-4', $invoice['perfume'] => '-1'], $quantities);
    }

    public function testPostCreditNote_SaysWhenEverythingOnTheInvoiceHasComeBack(): void
    {
        $invoice = $this->postedInvoice();
        $this->save([$this->line($invoice['cream'], 0, '-4', '75', '40.00', '10'), $this->line($invoice['perfume'], 1, '-2', '220', '120.00')], [$this->tax($invoice['cream'], 0, '-40.50'), $this->tax($invoice['perfume'], 1, '-66.00')], [], SALE_TYPE_RETURN, -1, true, $invoice['saleId']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/creditNote/' . $invoice['saleId']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.credit_note_nothing_left'), $result['message']);
    }

    public function testPostCreditNote_RefusesAnInvoiceOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $invoice = $this->postedInvoice();
        $this->loginAs($this->createBranchLimitedSupervisor($this->createSecondBranch()));

        $this->post('/sales/creditNote/' . $invoice['saleId'])->assertStatus(403);
    }

    public function testPostCreditNote_RefusesWhatIsNotAnInvoice(): void
    {
        $sale = $this->postedInvoice(true, SALE_TYPE_POS);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/creditNote/' . $sale['saleId']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.credit_note_invoice_only'), $result['message']);
    }

    public function testPostCreditNote_RefusesAnInvoiceWhoseGoodsDidNotLeave(): void
    {
        $invoice = $this->postedInvoice(false);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/creditNote/' . $invoice['saleId']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.return_origin_undelivered'), $result['message']);
    }

    public function testPostRefundInvoice_PreparesTheRegisterForARefundInvoiceOfTheInvoice(): void
    {
        $invoice = $this->postedInvoice();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/refundInvoice/' . $invoice['saleId']));

        $this->assertTrue($result['success']);
        $this->assertSame('refund_invoice', Services::session()->get('sales_return_document'));
        $this->assertSame('return', Services::session()->get('sales_mode'));
        $this->assertSame((string) $invoice['saleId'], (string) Services::session()->get('sales_return_origin'));
        $this->assertSame((string) $this->customerId, (string) Services::session()->get('sales_customer'));
    }

    public function testPostCreditNote_LeavesTheReturnToBeIssuedAsACreditNoteEvenAfterARefundInvoiceWasAsked(): void
    {
        $invoice = $this->postedInvoice();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->assertTrue($this->jsonOf($this->post('/sales/refundInvoice/' . $invoice['saleId']))['success']);
        $this->assertTrue($this->jsonOf($this->post('/sales/creditNote/' . $invoice['saleId']))['success']);

        $this->assertNull(Services::session()->get('sales_return_document'));
    }

    public function testPostRefundInvoice_TakesOnlyWhatHasNotComeBackYetOfTheInvoice(): void
    {
        $invoice = $this->postedInvoice();
        $this->save([$this->line($invoice['perfume'], 0, '-1', '220', '120.00')], [$this->tax($invoice['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $invoice['saleId']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->assertTrue($this->jsonOf($this->post('/sales/refundInvoice/' . $invoice['saleId']))['success']);

        $quantities = [];

        foreach (Services::session()->get('sales_cart') as $line) {
            $quantities[(int) $line['item_id']] = rtrim(rtrim((string) $line['quantity'], '0'), '.');
        }

        $this->assertSame([$invoice['cream'] => '-4', $invoice['perfume'] => '-1'], $quantities);
    }

    public function testPostRefundInvoice_RefusesAnInvoiceOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $invoice = $this->postedInvoice();
        $this->loginAs($this->createBranchLimitedSupervisor($this->createSecondBranch()));

        $this->post('/sales/refundInvoice/' . $invoice['saleId'])->assertStatus(403);
        $this->assertNull(Services::session()->get('sales_return_document'));
    }

    public function testPostRefundInvoice_RefusesWhatIsNotAnInvoiceAndAnInvoiceWhoseGoodsDidNotLeave(): void
    {
        $sale = $this->postedInvoice(true, SALE_TYPE_POS);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/refundInvoice/' . $sale['saleId']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.credit_note_invoice_only'), $result['message']);

        $undelivered = $this->save([$this->line($this->item('20.00'), 0, '1', '50', '20.00')], [], [], SALE_TYPE_INVOICE, -1, false);
        $result      = $this->jsonOf($this->post('/sales/refundInvoice/' . $undelivered));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.return_origin_undelivered'), $result['message']);
        $this->assertNull(Services::session()->get('sales_return_document'));
    }

    public function testPostRefundInvoice_SaysWhenEverythingOnTheInvoiceHasComeBack(): void
    {
        $invoice = $this->postedInvoice();
        $this->save([$this->line($invoice['cream'], 0, '-4', '75', '40.00', '10'), $this->line($invoice['perfume'], 1, '-2', '220', '120.00')], [$this->tax($invoice['cream'], 0, '-40.50'), $this->tax($invoice['perfume'], 1, '-66.00')], [], SALE_TYPE_RETURN, -1, true, $invoice['saleId']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/refundInvoice/' . $invoice['saleId']));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales.credit_note_nothing_left'), $result['message']);
    }

    public function testPostSetReturnDocument_ChangesTheDocumentTheReturnIsIssuedAs(): void
    {
        $this->loginAs($this->createCashierEmployee());
        $this->withSession(array_merge($this->session, ['sales_mode' => 'return']));

        $this->post('/sales/setReturnDocument', ['return_document' => 'refund_invoice']);

        $this->assertSame('refund_invoice', Services::session()->get('sales_return_document'));

        $this->post('/sales/setReturnDocument', ['return_document' => 'credit_note']);

        $this->assertNull(Services::session()->get('sales_return_document'));

        $this->post('/sales/setReturnDocument', ['return_document' => 'refund_invoice']);
        $this->post('/sales/setReturnDocument', ['return_document' => 'something else']);

        $this->assertNull(Services::session()->get('sales_return_document'));
    }

    public function testPostChangeMode_ForgetsTheRefundInvoiceWhenTheModeChangesAndKeepsItWhenItDoesNot(): void
    {
        $this->loginAs($this->createCashierEmployee());
        $this->withSession(array_merge($this->session, ['sales_mode' => 'return', 'sales_return_document' => 'refund_invoice']));

        $this->post('/sales/changeMode', ['mode' => 'return']);

        $this->assertSame('refund_invoice', Services::session()->get('sales_return_document'));

        $this->post('/sales/changeMode', ['mode' => 'sale']);

        $this->assertNull(Services::session()->get('sales_return_document'));
    }

    public function testGetIndex_OffersTheChoiceOfTheReturnDocumentInReturnModeOnly(): void
    {
        $this->loginAs($this->createCashierEmployee());
        $this->withSession(array_merge($this->session, ['sales_mode' => 'return', 'sales_return_document' => 'refund_invoice']));

        $response = $this->get('/sales');

        $response->assertSee('id="return_document"');
        $this->assertMatchesRegularExpression('/<option value="refund_invoice"\s+selected[^>]*>' . preg_quote(lang('Numbering.type_refund_invoice'), '/') . '<\/option>/', $response->getBody());

        $this->withSession(array_merge($this->session, ['sales_mode' => 'sale']));

        $this->get('/sales')->assertDontSee('id="return_document"');
    }

    public function testPostComplete_SavesTheReturnAsARefundInvoiceAndShowsItsNumberOnTheReceipt(): void
    {
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5);
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5);

        $response = $this->completeAs('return', ['sales_return_document' => 'refund_invoice']);

        $response->assertSee('id="refund_invoice_number"');
        $response->assertSee('RI-00001');
        $response->assertDontSee('id="credit_note_number"');
        $this->assertSame('refund_invoice', Database::connect()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow()->return_document);
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::RefundInvoice)->nextNumber);
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::CreditNote)->nextNumber);
    }

    public function testPostComplete_ShowsARefundInvoiceByItsReceiptNumberWhileItsSequenceIsOff(): void
    {
        $response = $this->completeAs('return', ['sales_return_document' => 'refund_invoice']);

        $saleId = (int) Database::connect()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow()->sale_id;

        $response->assertSee('id="refund_invoice_number"');
        $response->assertSee(lang('Numbering.type_refund_invoice') . ': POS ' . $saleId);
        $this->assertSame(0, Database::connect()->table('document_numbers')->where('source_id', $saleId)->countAllResults());
    }

    public function testPostComplete_SavesAReturnAsACreditNoteWhenNoDocumentWasChosen(): void
    {
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5);

        $response = $this->completeAs('return');

        $response->assertDontSee('id="refund_invoice_number"');
        $this->assertNull(Database::connect()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow()->return_document);
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::RefundInvoice)->nextNumber);
    }

    public function testGetReceipt_ShowsTheNumberOfARefundInvoiceAndNotOnACreditNote(): void
    {
        $invoice = $this->postedInvoice();
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5);
        $refund = $this->refundInvoiceOf($invoice['saleId'], '10.00');
        $note   = $this->creditNoteOf($invoice['saleId'], '10.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/receipt/' . $refund)->assertSee('RI-00001');
        $this->get('/sales/receipt/' . $refund)->assertDontSee('id="credit_note_number"');
        $this->get('/sales/receipt/' . $note)->assertDontSee('id="refund_invoice_number"');
        $this->get('/sales/receipt/' . $invoice['saleId'])->assertDontSee('id="refund_invoice_number"');
    }

    #[DataProvider('receiptTemplateProvider')]
    public function testGetReceipt_NamesARefundInvoiceByItsNumberOnEveryReceiptTemplate(string $template): void
    {
        $invoice = $this->postedInvoice();
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5);
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5);
        $refund = $this->refundInvoiceOf($invoice['saleId'], '10.00');
        $note   = $this->creditNoteOf($invoice['saleId'], '10.00');
        $this->setConfigValue('receipt_template', $template);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        try {
            $page = $this->get('/sales/receipt/' . $refund);

            $page->assertStatus(200);
            $page->assertSee(lang('Numbering.type_refund_invoice'));
            $page->assertSee('RI-00001');
            $page->assertDontSee(lang('Numbering.type_credit_note'));
            $page->assertDontSee('CN-00001');

            $creditNote = $this->get('/sales/receipt/' . $note);

            $creditNote->assertSee(lang('Numbering.type_credit_note'));
            $creditNote->assertSee('CN-00001');
            $creditNote->assertDontSee(lang('Numbering.type_refund_invoice'));
            $creditNote->assertDontSee('RI-00001');
        } finally {
            $this->setConfigValue('receipt_template', 'receipt_default');
        }
    }

    public function testLoadSaleData_NamesARefundInvoiceOnTheEmailReceipt(): void
    {
        $invoice = $this->postedInvoice();
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5);
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5);
        $refund = $this->refundInvoiceOf($invoice['saleId'], '10.00');
        $note   = $this->creditNoteOf($invoice['saleId'], '10.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/receipt/' . $refund);

        $controller = new Sales();
        $controller->initController(service('request'), service('response'), service('logger'));

        $load   = new ReflectionMethod($controller, '_load_sale_data');
        $email  = view('sales/receipt_email', $load->invoke($controller, $refund) + ['pdf' => false]);
        $credit = view('sales/receipt_email', $load->invoke($controller, $note) + ['pdf' => false]);

        $this->assertStringContainsString(lang('Numbering.type_refund_invoice') . ': <span dir="ltr">RI-00001</span>', $email);
        $this->assertStringNotContainsString(lang('Numbering.type_credit_note'), $email);
        $this->assertStringContainsString(lang('Numbering.type_credit_note') . ': <span dir="ltr">CN-00001</span>', $credit);
        $this->assertStringNotContainsString(lang('Numbering.type_refund_invoice'), $credit);
    }

    public function testGetEdit_OffersTheRefundInvoiceWhereTheCreditNoteIsOfferedAndShowsItsNumber(): void
    {
        $invoice = $this->postedInvoice();
        $this->switchOn(DocumentType::RefundInvoice, 'RI-', 5);
        $refund = $this->refundInvoiceOf($invoice['saleId'], '10.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertSee('id="create_refund_invoice"');
        $response->assertSee(lang('Sales.refund_invoice_create'));

        $edit = $this->get('/sales/edit/' . $refund);

        $edit->assertSee('id="refund_invoice_number"');
        $edit->assertSee('RI-00001');
        $edit->assertDontSee('id="credit_note_number"');
        $edit->assertDontSee('id="create_refund_invoice"');
    }

    public function testGetEdit_OffersNoRefundInvoiceForAPointOfSaleSaleOrAnUndeliveredInvoice(): void
    {
        $sale = $this->postedInvoice(true, SALE_TYPE_POS);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/edit/' . $sale['saleId'])->assertDontSee('id="create_refund_invoice"');

        $undelivered = $this->save([$this->line($this->item('20.00'), 0, '1', '50', '20.00')], [], [], SALE_TYPE_INVOICE, -1, false);

        $this->get('/sales/edit/' . $undelivered)->assertDontSee('id="create_refund_invoice"');
    }

    public function testPostSave_KeepsAPaymentBeyondTheInvoiceWholeAsCreditOfTheCustomer(): void
    {
        $service = $this->item('0', false);
        $this->startSelling();
        $saleId = $this->save([$this->line($service, 0, '1', '230')]);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $saleId, [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'paid',
            'number_of_payments' => '0',
            'payment_type_new'   => lang('Sales.cash'),
            'payment_amount_new' => '300.00',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('0.00', bcadd($this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->cash_refund, '0', 2));
        $this->assertSame('-70.00', $this->balance($this->customerAccount()));
        $this->assertSame('70.00', bcadd($this->position()->credit(), '0', 2));
    }

    public function testPostSave_GivesTheChangeBackForASaleWithoutACustomer(): void
    {
        $service = $this->item('0', false);
        $this->startSelling();
        $saleId = $this->save([$this->line($service, 0, '1', '230')], [], [$this->payment(lang('Sales.cash'), '230.00')], SALE_TYPE_POS, null);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->table('accounting_journals')->where('source_type', 'sale_payment')->update(['status' => 'reversed']);

        $result = $this->jsonOf($this->post('/sales/save/' . $saleId, [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => '',
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'paid',
            'number_of_payments' => '0',
            'payment_type_new'   => lang('Sales.cash'),
            'payment_amount_new' => '300.00',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('70.00', bcadd($this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->cash_refund, '0', 2));
    }

    public function testPostSave_SetsTheStatusOfAPaymentFromTheForm(): void
    {
        $service = $this->item('0', false);
        $this->startSelling();
        $saleId = $this->save([$this->line($service, 0, '1', '100')], [], [$this->payment(lang('Sales.cash'), '100.00')]);
        $row    = $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $saleId, [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'on hold',
            'number_of_payments' => '1',
            'payment_id_0'       => (string) $row->payment_id,
            'payment_type_0'     => lang('Sales.cash'),
            'payment_amount_0'   => '100.00',
            'refund_type_0'      => lang('Sales.cash'),
            'refund_amount_0'    => '0.00',
            'payment_status_0'   => 'review',
            'payment_type_new'   => '--',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('review', $this->accountingDb()->table('sales_payments')->where('payment_id', $row->payment_id)->get()->getRow()->payment_status);
        $this->assertSame('100.00', $this->balance($this->customerAccount()));
        $this->assertSame('100.00', bcadd($this->position()->open(), '0', 2));
    }

    public function testPostSave_PostsThePaymentAddedFromTheForm(): void
    {
        $invoice      = $this->postedInvoice();
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->loginAs($supervisorId);

        $result = $this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], [
            'date'              => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'       => (string) $this->customerId,
            'employee_id'       => (string) $this->employeeId,
            'comment'           => 'paid',
            'number_of_payments' => '0',
            'payment_type_new'  => lang('Sales.cash'),
            'payment_amount_new' => '100.00',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame('716.50', $this->balance($this->customerAccount()));
        $this->assertSame('100.00', $this->balance($this->accountId('cash')));
    }

    protected function treasuryOfBranch(string $name, int $branchId = 1): int
    {
        return (new TreasuryService())->create(['name' => $name, 'kind' => 'cash', 'branch_id' => $branchId]);
    }

    /**
     * The payments are named in English because the form is read in the language of the request
     * and the English name of a type is known in every language.
     *
     * @return array<string, string>
     */
    protected function savedPayment(object $row, array $extra = []): array
    {
        return [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'treasury',
            'number_of_payments' => '1',
            'payment_id_0'       => (string) $row->payment_id,
            'payment_type_0'     => 'Cash',
            'payment_amount_0'   => to_currency_no_money($row->payment_amount),
            'refund_type_0'      => 'Cash',
            'refund_amount_0'    => '0.00',
            'payment_type_new'   => '--',
            ...$extra,
        ];
    }

    public function testGetEdit_OffersTheTreasuriesOfTheBranchWhenThereIsAChoice(): void
    {
        $invoice = $this->postedInvoice();
        $this->addPayment($invoice['saleId'], '300.00', 'Cash');
        $this->treasuryOfBranch('Second drawer');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertStatus(200);
        $response->assertSee('name="treasury_id_0"');
        $response->assertSee('name="treasury_id_new"');
        $response->assertSee('Second drawer');
        $response->assertSee(lang('Sales.treasury_default'));
    }

    public function testGetEdit_LeavesOutTheTreasuriesTheEditorMayNotDepositIntoButKeepsTheOneAPaymentAlreadyCarries(): void
    {
        $invoice = $this->postedInvoice();
        $this->addPayment($invoice['saleId'], '300.00', 'Cash');
        $this->treasuryOfBranch('Open drawer');

        $locked = (new TreasuryService())->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeId]]);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertStatus(200);
        $response->assertSee('Open drawer');
        $response->assertDontSee('Locked drawer');

        $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice['saleId'])->update(['treasury_id' => $locked]);

        $this->get('/sales/edit/' . $invoice['saleId'])->assertSee('Locked drawer');
    }

    public function testGetEdit_OffersNoTreasuryWhileTheBranchHasOnlyTheMainOne(): void
    {
        $invoice = $this->postedInvoice();
        $this->addPayment($invoice['saleId'], '300.00', 'Cash');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertStatus(200);
        $response->assertDontSee('name="treasury_id_0"');
        $response->assertDontSee('name="treasury_id_new"');
    }

    public function testGetEdit_OffersNoTreasuryWhileAccountingIsOff(): void
    {
        $invoice = $this->postedInvoice();
        $this->addPayment($invoice['saleId'], '300.00', 'Cash');
        $this->treasuryOfBranch('Second drawer');
        $this->setConfigValue('accounting_enabled', '0');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertStatus(200);
        $response->assertDontSee('name="treasury_id_0"');
        $response->assertDontSee('name="treasury_id_new"');
    }

    public function testGetEdit_LeavesTheTreasuryOutOfAPaymentThatSettlesNothingInCash(): void
    {
        $invoice = $this->postedInvoice();
        $this->addPayment($invoice['saleId'], '300.00', 'Due');
        $this->treasuryOfBranch('Second drawer');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertDontSee('name="treasury_id_0"');
        $response->assertSee('name="treasury_id_new"');
    }

    public function testGetEdit_KeepsTheRetiredTreasuryAPaymentAlreadyCarries(): void
    {
        $invoice = $this->postedInvoice();
        $retired = $this->treasuryOfBranch('Retired drawer');
        $this->treasuryOfBranch('Open drawer');
        $paymentId = $this->addPayment($invoice['saleId'], '300.00', 'Cash');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['treasury_id' => $retired]);
        (new TreasuryService())->deactivate($retired);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertSee('Retired drawer');
        $this->assertMatchesRegularExpression('/<option value="' . $retired . '"\s+selected>Retired drawer<\/option>/', $response->getBody());
        $this->assertSame(1, substr_count($response->getBody(), 'Retired drawer'), 'The new payment cannot be put into a retired treasury');
    }

    public function testGetEdit_KeepsTheTreasuryOfAnotherBranchAPaymentAlreadyCarriesSoTheFormSendsItBack(): void
    {
        $invoice = $this->postedInvoice();
        $this->treasuryOfBranch('Open drawer');
        $elsewhere = $this->treasuryOfBranch('Main of another branch', $this->createSecondBranch());
        $paymentId = $this->addPayment($invoice['saleId'], '300.00', 'Cash');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['treasury_id' => $elsewhere]);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $this->assertMatchesRegularExpression('/<option value="' . $elsewhere . '"\s+selected>Main of another branch \([^)]+\)<\/option>/', $response->getBody(), 'It is named with the code of its branch');
    }

    public function testPostSave_PutsTheNewPaymentIntoTheTreasuryTheFormNames(): void
    {
        $invoice = $this->postedInvoice();
        $drawer  = $this->treasuryOfBranch('Second drawer');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'paid',
            'number_of_payments' => '0',
            'payment_type_new'   => 'Cash',
            'payment_amount_new' => '100.00',
            'treasury_id_new'    => (string) $drawer,
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice['saleId'])->get()->getRow()->treasury_id);
        $this->assertSame('100.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
    }

    public function testPostSave_MovesAPaymentToTheTreasuryTheFormNames(): void
    {
        $invoice   = $this->postedInvoice();
        $drawer    = $this->treasuryOfBranch('Second drawer');
        $paymentId = $this->addPayment($invoice['saleId'], '100.00', 'Cash');
        $row       = $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], $this->savedPayment($row, ['treasury_id_0' => (string) $drawer])));

        $this->assertTrue($result['success']);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id);
        $this->assertSame('100.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('0.00', $this->balance($this->accountId('cash')));
    }

    public function testPostSave_KeepsTheTreasuryOfAPaymentTheFormDoesNotName(): void
    {
        $invoice   = $this->postedInvoice();
        $drawer    = $this->treasuryOfBranch('Second drawer');
        $paymentId = $this->addPayment($invoice['saleId'], '100.00', 'Cash');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['treasury_id' => $drawer]);
        $row = $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], $this->savedPayment($row)));

        $this->assertTrue($result['success']);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->get()->getRow()->treasury_id);
    }

    public function testPostSave_RefusesATreasuryOfAnotherBranchAndChangesNothing(): void
    {
        $invoice   = $this->postedInvoice();
        $elsewhere = $this->treasuryOfBranch('Far drawer', $this->createSecondBranch());
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'paid',
            'number_of_payments' => '0',
            'payment_type_new'   => 'Cash',
            'payment_amount_new' => '100.00',
            'treasury_id_new'    => (string) $elsewhere,
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_treasury_not_found'), $result['message']);
        $this->assertSame(0, $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice['saleId'])->countAllResults());
        $this->assertSame('0.0000', (new TreasuryService())->position($elsewhere)->balance);
    }

    /**
     * A supervisor who works in the first branch and in the one given.
     *
     * @param list<string> $extraGrants
     */
    protected function supervisorInBoth(int $branchId, array $extraGrants = []): int
    {
        $personId = $this->createBranchLimitedSupervisor(1, $extraGrants);

        $this->accountingDb()->table('employee_branches')->insert(['person_id' => $personId, 'branch_id' => $branchId, 'is_default' => 0]);

        return $personId;
    }

    public function testGetEdit_OffersTheTreasuriesOfTheOtherBranchesToAnEmployeeWhoWorksInBothWithTheirCode(): void
    {
        $invoice = $this->postedInvoice();
        $branch  = $this->createSecondBranch();
        $this->treasuryOfBranch('Open drawer');
        $far = $this->treasuryOfBranch('Far drawer', $branch);

        $this->loginAs($this->supervisorInBoth($branch));

        $this->assertMatchesRegularExpression('/<option value="' . $far . '">Far drawer \([^)]+\)<\/option>/', $this->get('/sales/edit/' . $invoice['saleId'])->getBody());

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->assertStringNotContainsString('Far drawer', $this->get('/sales/edit/' . $invoice['saleId'])->getBody());
    }

    public function testGetEdit_NamesTheJournalOfAPaymentIntoATreasuryOfAnotherBranch(): void
    {
        $invoice = $this->postedInvoice();
        $branch  = $this->createSecondBranch();
        $far     = $this->treasuryOfBranch('Far drawer', $branch);

        $this->loginAs($this->supervisorInBoth($branch, ['accounting_journals']));
        $this->assertTrue($this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'paid',
            'number_of_payments' => '0',
            'payment_type_new'   => 'Cash',
            'payment_amount_new' => '100.00',
            'treasury_id_new'    => (string) $far,
        ]))['success']);

        $body = $this->get('/sales/edit/' . $invoice['saleId'])->getBody();

        $this->assertMatchesRegularExpression('/<li>\s*<a [^>]*>[^<]*<\/a>\s*' . preg_quote(lang('Sales.entry_payment_branch'), '/') . '\s*<\/li>/', $body);
        $this->assertStringNotContainsString('Sales.entry_', $body, 'Every journal has a name of its own');
    }

    public function testGetEdit_EscapesTheNamesOfTheTreasuriesItOffersInTheDropdownsOfTheNewAndTheExistingPayments(): void
    {
        $invoice = $this->postedInvoice();
        $branch  = $this->createSecondBranch();
        $this->treasuryOfBranch('Open <b>drawer</b>');
        $far       = $this->treasuryOfBranch('Far <img src=x onerror=alert(1)>', $branch);
        $paymentId = $this->addPayment($invoice['saleId'], '300.00', 'Cash');
        $this->accountingDb()->table('sales_payments')->where('payment_id', $paymentId)->update(['treasury_id' => $far]);

        $this->loginAs($this->supervisorInBoth($branch));

        $body = $this->get('/sales/edit/' . $invoice['saleId'])->getBody();

        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringNotContainsString('<b>drawer</b>', $body);
        $this->assertSame(2, substr_count($body, 'Far &lt;img src=x onerror=alert(1)&gt; ('), 'The dropdown of the payment and the one of a new payment');
        $this->assertSame(2, substr_count($body, 'Open &lt;b&gt;drawer&lt;/b&gt;</option>'));
    }

    public function testGetIndex_EscapesTheNamesOfTheTreasuriesItOffersAtTheRegister(): void
    {
        $this->registerWithACart();
        $this->treasuryOfBranch('Register <img src=x onerror=alert(1)>');

        $body = $this->get('/sales')->getBody();

        $this->assertStringNotContainsString('<img src=x', $body);
        $this->assertStringContainsString('Register &lt;img src=x onerror=alert(1)&gt;</option>', $body);
    }

    public function testPostSave_PutsTheNewPaymentIntoATreasuryOfAnotherBranchForAnEmployeeWhoWorksInBoth(): void
    {
        $invoice = $this->postedInvoice();
        $branch  = $this->createSecondBranch();
        $far     = $this->treasuryOfBranch('Far drawer', $branch);

        $this->loginAs($this->supervisorInBoth($branch));

        $result = $this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'paid',
            'number_of_payments' => '0',
            'payment_type_new'   => 'Cash',
            'payment_amount_new' => '100.00',
            'treasury_id_new'    => (string) $far,
        ]));

        $payment = $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice['saleId'])->get()->getRow();

        $this->assertTrue($result['success']);
        $this->assertSame([$far, $branch], [(int) $payment->treasury_id, (int) $payment->treasury_branch_id]);
        $this->assertSame('100.0000', (new TreasuryService())->position($far)->balance);
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['sale_payment', 'sale_payment_branch'])->where('source_id', (int) $payment->payment_id)->countAllResults());
    }

    public function testPostSave_RefusesToChangeTheCustomerOfAPostedSaleAndSaysWhy(): void
    {
        $invoice      = $this->postedInvoice();
        $other        = $this->insertCustomer('Hind', 'Saad');
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->loginAs($supervisorId);

        $result = $this->jsonOf($this->post('/sales/save/' . $invoice['saleId'], [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $other,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'changed',
            'number_of_payments' => '0',
            'payment_type_new'   => PAYMENT_TYPE_UNASSIGNED,
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_sale_locked'), $result['message']);
        $this->assertSame((string) $this->customerId, (string) $this->saleRow($invoice['saleId'])->customer_id);
        $this->assertSame('test', $this->saleRow($invoice['saleId'])->comment);
    }

    public function testGetEdit_ListsTheJournalsOfTheSaleForWhoeverMayOpenThem(): void
    {
        $invoice = $this->postedInvoice();
        $with    = $this->createBranchLimitedSupervisor(1, ['accounting_journals']);
        $without = $this->createBranchLimitedSupervisor(1);

        $this->loginAs($with);
        $page = $this->get('/sales/edit/' . $invoice['saleId']);

        foreach ($this->journals($invoice['saleId']) as $journal) {
            $page->assertSee('accounting_journals/view/' . $journal['id']);
        }

        $this->loginAs($without);
        $page = $this->get('/sales/edit/' . $invoice['saleId']);

        $page->assertDontSee('accounting_journals/view/');
    }

    public function testGetEdit_OffersTheDeliveryOfAnInvoiceThatWasNotDelivered(): void
    {
        $undelivered = $this->postedInvoice(false);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/edit/' . $undelivered['saleId'])->assertSee('confirm_delivery');
    }

    public function testPostSetReturnOrigin_RefusesASaleOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $invoice      = $this->postedInvoice();
        $branchId     = $this->createSecondBranch();
        $supervisorId = $this->createBranchLimitedSupervisor($branchId);
        $this->grantStockLocationIn($branchId, $supervisorId);
        $this->loginAs($supervisorId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return']));

        $response = $this->post('/sales/setReturnOrigin', ['return_origin' => (string) $invoice['saleId']]);

        $response->assertSee(lang('Sales.branch_not_allowed'));
        $this->assertNull(Services::session()->get('sales_return_origin'));
    }

    public function testPostSetReturnOrigin_RefusesASaleThatIsNotCompleteOrWasNotDelivered(): void
    {
        $this->startSelling();
        $item      = $this->item('20.00');
        $draft     = $this->save([$this->line($item, 0, '1', '50', '20.00')], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);
        $undelivered = $this->save([$this->line($item, 0, '1', '50', '20.00')], [], [], SALE_TYPE_INVOICE, -1, false);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->grantStockLocationIn(1, $supervisorId);
        $this->loginAs($supervisorId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return']));

        $this->post('/sales/setReturnOrigin', ['return_origin' => (string) $draft])->assertSee(lang('Sales.return_origin_invalid'));
        $this->post('/sales/setReturnOrigin', ['return_origin' => '999999'])->assertSee(lang('Sales.return_origin_invalid'));
        $this->post('/sales/setReturnOrigin', ['return_origin' => (string) $undelivered])->assertSee(lang('Sales.return_origin_undelivered'));

        $this->assertNull(Services::session()->get('sales_return_origin'));
    }

    public function testPostSetReturnOrigin_NamesTheSaleAndSelectsItsCustomer(): void
    {
        $invoice      = $this->postedInvoice();
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->grantStockLocationIn(1, $supervisorId);
        $this->loginAs($supervisorId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return']));

        $this->post('/sales/setReturnOrigin', ['return_origin' => 'POS ' . $invoice['saleId']])->assertStatus(200);

        $this->assertSame($invoice['saleId'], Services::session()->get('sales_return_origin'));
        $this->assertSame($this->customerId, (int) Services::session()->get('sales_customer'));
    }

    public function testPostComplete_SaysWhyTheBooksRefusedTheSaleAndKeepsNone(): void
    {
        $this->startSelling();
        $item         = $this->item('0', false);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->grantStockLocationIn(1, $supervisorId);
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'main_per_entity']);
        $this->loginAs($supervisorId);
        $this->seedCartLine(1, '100.00', $item);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_customer' => $this->customerId, 'sales_mode' => 'sale_invoice', 'sales_payments' => []]));

        $sales = $this->countSales();

        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Accounting.posting_routing_type_unsupported'));
        $this->assertSame($sales, $this->countSales());
    }

    /**
     * A supervisor of the first branch with a cart of one line of 100 at the register.
     */
    protected function registerWithACart(): int
    {
        $this->startSelling();
        $item         = $this->item('0', false);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->grantStockLocationIn(1, $supervisorId);
        $this->loginAs($supervisorId);
        $this->seedCartLine(1, '100.00', $item);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_payments' => []]));

        return $supervisorId;
    }

    /**
     * What the cart holds after a request, handed to the next one the way the browser does.
     */
    protected function carryPaymentsToTheNextRequest(): void
    {
        $this->withSession(array_merge($this->session, ['sales_payments' => Services::session()->get('sales_payments')]));
    }

    public function testGetIndex_OffersTheTreasuriesOfTheBranchAtTheRegisterWhenThereIsAChoice(): void
    {
        $this->registerWithACart();
        $this->treasuryOfBranch('Register drawer');

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $response->assertSee('name="treasury_id"');
        $response->assertSee('Register drawer');
        $response->assertSee('id="treasury_choice"');
    }

    public function testGetIndex_OffersNoTreasuryAtTheRegisterWhileTheBranchHasOnlyTheMainOne(): void
    {
        $this->registerWithACart();

        $response = $this->get('/sales');

        $response->assertStatus(200);
        $response->assertDontSee('name="treasury_id"');
    }

    public function testPostAddPayment_KeepsTheTreasuryTheCashierChose(): void
    {
        $this->registerWithACart();
        $drawer = $this->treasuryOfBranch('Register drawer');

        $response = $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '40.00', 'treasury_id' => (string) $drawer]);

        $payments = Services::session()->get('sales_payments');

        $this->assertSame($drawer, (int) $payments['Cash']['treasury_id']);
        $response->assertSee('(Register drawer)');
    }

    public function testPostAddPayment_AddsToThePaymentOfTheSameTypeAndTreasuryButRefusesAnotherTreasury(): void
    {
        $this->registerWithACart();
        $drawer = $this->treasuryOfBranch('Register drawer');
        $other  = $this->treasuryOfBranch('Other drawer');

        $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '40.00', 'treasury_id' => (string) $drawer]);
        $this->carryPaymentsToTheNextRequest();
        $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '10.00', 'treasury_id' => (string) $drawer]);

        $this->assertSame('50.00', bcadd((string) Services::session()->get('sales_payments')['Cash']['payment_amount'], '0', 2));

        $this->carryPaymentsToTheNextRequest();

        $response = $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '5.00', 'treasury_id' => (string) $other]);

        $response->assertSee(lang('Sales.treasury_payment_conflict'));

        $payments = Services::session()->get('sales_payments');

        $this->assertSame('50.00', bcadd((string) $payments['Cash']['payment_amount'], '0', 2));
        $this->assertSame($drawer, (int) $payments['Cash']['treasury_id']);
    }

    public function testPostAddPayment_RefusesATreasuryOfAnotherBranchAndAddsNothing(): void
    {
        $this->registerWithACart();
        $elsewhere = $this->treasuryOfBranch('Far drawer', $this->createSecondBranch());

        $response = $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '40.00', 'treasury_id' => (string) $elsewhere]);

        $response->assertSee(lang('Accounting.error_treasury_not_found'));
        $this->assertEmpty(Services::session()->get('sales_payments'));
    }

    public function testPostAddPayment_PutsNoTreasuryOnAPaymentThatSettlesNothingInCash(): void
    {
        $this->registerWithACart();
        $drawer = $this->treasuryOfBranch('Register drawer');

        $this->post('/sales/addPayment', ['payment_type' => 'Due', 'amount_tendered' => '40.00', 'treasury_id' => (string) $drawer]);

        $this->assertSame(0, (int) Services::session()->get('sales_payments')['Due']['treasury_id']);
    }

    public function testPostComplete_PostsThePaymentToTheTreasuryChosenAtTheRegister(): void
    {
        $this->registerWithACart();
        $drawer = $this->treasuryOfBranch('Register drawer');

        $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '100.00', 'treasury_id' => (string) $drawer]);
        $this->carryPaymentsToTheNextRequest();

        $sales = $this->countSales();

        $this->post('/sales/complete');

        $this->assertSame($sales + 1, $this->countSales());

        $payment = $this->accountingDb()->table('sales_payments')->orderBy('payment_id', 'DESC')->get()->getRow();

        $this->assertSame($drawer, (int) $payment->treasury_id);
        $this->assertSame('100.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame('0.00', $this->balance($this->accountId('cash')), 'The main treasury received nothing');
    }

    // ---------------------------------------------------------------------------------------
    // The discount on the whole of a sale: who may give it, what the register and the documents show
    // ---------------------------------------------------------------------------------------

    /**
     * A supervisor of the first branch with a cart of one line of 100 at the register, with or without
     * the permission to give a discount on the whole of the sale.
     */
    protected function registerWithTheDiscountGrant(bool $granted = true): int
    {
        $this->startSelling();
        $item         = $this->item('0', false);
        $supervisorId = $this->createBranchLimitedSupervisor(1, $granted ? ['sales_invoice_discount'] : []);
        $this->grantStockLocationIn(1, $supervisorId);
        $this->loginAs($supervisorId);
        $this->seedCartLine(1, '100.00', $item);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_payments' => []]));

        return $supervisorId;
    }

    protected function carryTheDiscountToTheNextRequest(): void
    {
        $this->withSession(array_merge($this->session, ['sales_invoice_discount' => Services::session()->get('sales_invoice_discount'), 'sales_payments' => Services::session()->get('sales_payments')]));
    }

    public function testPostSetInvoiceDiscount_RefusesAnEmployeeWithoutThePermissionAndSetsNothing(): void
    {
        $this->registerWithTheDiscountGrant(false);

        $response = $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => (string) PERCENT]);

        $response->assertSee(lang('Sales.not_authorized'));
        $this->assertNull(Services::session()->get('sales_invoice_discount'));
    }

    public function testGetIndex_OffersTheDiscountOnTheWholeOfTheSaleOnlyToAnEmployeeWhoMayGiveIt(): void
    {
        $this->registerWithTheDiscountGrant();

        $this->get('/sales')->assertSee('id="invoice_discount_form"');

        $this->registerWithTheDiscountGrant(false);

        $this->get('/sales')->assertDontSee('id="invoice_discount_form"');
    }

    public function testGetIndex_NamesEveryControlOfTheDiscountFormAndTiesItsHelpToThem(): void
    {
        $this->registerWithTheDiscountGrant();

        $page = $this->get('/sales');

        $body = $page->getBody();

        $this->assertStringContainsString('<label for="invoice_discount" class="pos-label">', $body);
        $this->assertStringContainsString('id="invoice_discount_help"', $body);
        $this->assertSame(2, substr_count($body, 'aria-describedby="invoice_discount_help"'));
        $this->assertMatchesRegularExpression('/<select name="invoice_discount_type"[^>]*aria-label="' . preg_quote(lang('Sales.invoice_discount'), '/') . '"/', $body);
    }

    public function testGetIndex_OffersNoDiscountOnTheWholeOfTheSaleWhileTheRegisterTakesAReturn(): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_mode' => 'return']));

        $this->get('/sales')->assertDontSee('id="invoice_discount_form"');
    }

    public function testPostSetInvoiceDiscount_TakesAPercentageOffTheTotalOfTheRegister(): void
    {
        $this->registerWithTheDiscountGrant();

        $response = $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => (string) PERCENT]);

        $response->assertStatus(200);
        $response->assertSee('id="invoice_discount_total"');
        $response->assertSee(to_currency('90'));
        $this->assertSame(['type' => PERCENT, 'value' => '10'], Services::session()->get('sales_invoice_discount'));
    }

    public function testPostSetInvoiceDiscount_TakesAnAmountOffTheTotalOfTheRegister(): void
    {
        $this->registerWithTheDiscountGrant();

        $response = $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => '12,50', 'invoice_discount_type' => (string) FIXED]);

        $response->assertSee(to_currency('87.5'));
        $this->assertSame(['type' => FIXED, 'value' => '12.5'], Services::session()->get('sales_invoice_discount'));
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function refusedInvoiceDiscountProvider(): array
    {
        return [
            'more than the cart comes to' => [(string) FIXED, '100.01', 'Sales.invoice_discount_exceeds_total'],
            'above a hundred percent'     => [(string) PERCENT, '100.5', 'Sales.discount_percent_exceeds_100'],
            'a negative amount'           => [(string) FIXED, '-5', 'Sales.negative_discount_invalid'],
            'text instead of a number'    => [(string) FIXED, 'ten', 'Sales.invoice_discount_invalid'],
            'a kind nobody knows'         => ['7', '5', 'Sales.invoice_discount_invalid'],
        ];
    }

    #[DataProvider('refusedInvoiceDiscountProvider')]
    public function testPostSetInvoiceDiscount_RefusesWhatCannotBeTakenOffAndKeepsTheOneThereWas(string $type, string $value, string $message): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_invoice_discount' => ['type' => PERCENT, 'value' => '5']]));

        $response = $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => $value, 'invoice_discount_type' => $type]);

        $response->assertSee(lang($message));
        $this->assertSame(['type' => PERCENT, 'value' => '5'], Services::session()->get('sales_invoice_discount'));
    }

    public function testPostSetInvoiceDiscount_TakesTheDiscountOffWhenGivenNothingOrZero(): void
    {
        $this->registerWithTheDiscountGrant();

        foreach (['', '0', '0.00'] as $nothing) {
            $this->withSession(array_merge($this->session, ['sales_invoice_discount' => ['type' => PERCENT, 'value' => '5']]));
            $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => $nothing, 'invoice_discount_type' => (string) PERCENT]);

            $this->assertNull(Services::session()->get('sales_invoice_discount'), "'{$nothing}'");
        }
    }

    public function testPostSetInvoiceDiscount_RefusesWhileTheRegisterTakesAReturn(): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_mode' => 'return']));

        $response = $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => (string) PERCENT]);

        $response->assertSee(lang('Sales.invoice_discount_not_on_return'));
        $this->assertNull(Services::session()->get('sales_invoice_discount'));
    }

    public function testPostChangeMode_ForgetsTheDiscountWhenTheModeChangesAndKeepsItWhenItDoesNot(): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_mode' => 'sale', 'sales_invoice_discount' => ['type' => PERCENT, 'value' => '10']]));

        $this->post('/sales/changeMode', ['mode' => 'sale']);

        $this->assertSame(['type' => PERCENT, 'value' => '10'], Services::session()->get('sales_invoice_discount'));

        $this->post('/sales/changeMode', ['mode' => 'return']);

        $this->assertNull(Services::session()->get('sales_invoice_discount'));
    }

    public function testPostComplete_SavesTheDiscountWithTheSalePostsItNetAndPrintsItOnTheReceipt(): void
    {
        $this->registerWithTheDiscountGrant();

        $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => '10', 'invoice_discount_type' => (string) PERCENT]);
        $this->carryTheDiscountToTheNextRequest();
        $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '90.00']);
        $this->carryTheDiscountToTheNextRequest();

        $sales = $this->countSales();

        $response = $this->post('/sales/complete');

        $this->assertSame($sales + 1, $this->countSales());

        $sale = $this->accountingDb()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow();

        $this->assertSame('10.0000', $sale->invoice_discount);
        $this->assertSame((string) PERCENT, (string) $sale->invoice_discount_type);
        $this->assertSame('10.0000', $this->accountingDb()->table('sales_items')->where('sale_id', $sale->sale_id)->get()->getRow()->invoice_discount_share);
        $this->assertSame('-90.00', $this->balance($this->accountId('sales_revenue')), 'the revenue is posted net of the discount');
        $response->assertSee(lang('Sales.invoice_discount') . ' (' . to_decimals('10') . '%)');
        $this->assertNull(Services::session()->get('sales_invoice_discount'), 'the register forgets the discount of a sale that was made');
    }

    public function testPostComplete_RefusesADiscountThatNoLongerFitsTheCartAndSavesNothing(): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_mode' => 'sale_invoice', 'sales_invoice_discount' => ['type' => FIXED, 'value' => '150']]));

        $sales = $this->countSales();

        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Sales.invoice_discount_exceeds_total'));
        $this->assertSame($sales, $this->countSales());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function documentOfARegisterProvider(): array
    {
        return [
            'a sale at the register'  => ['sale', '/sales/complete'],
            'a quote'                 => ['sale_quote', '/sales/complete'],
            'a work order'            => ['sale_work_order', '/sales/complete'],
            'a suspended sale'        => ['sale', '/sales/suspend'],
        ];
    }

    #[DataProvider('documentOfARegisterProvider')]
    public function testPostComplete_RefusesADiscountThatNoLongerFitsTheCartInEveryDocumentAndKeepsTheCart(string $mode, string $uri): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_mode' => $mode]));
        $this->post('/sales/addPayment', ['payment_type' => 'Cash', 'amount_tendered' => '100.00']);
        $this->withSession(array_merge($this->session, ['sales_invoice_discount' => ['type' => FIXED, 'value' => '150'], 'sales_payments' => Services::session()->get('sales_payments')]));

        $sales = $this->countSales();

        $response = $this->post($uri);

        $response->assertSee(lang('Sales.invoice_discount_exceeds_total'));
        $this->assertSame($sales, $this->countSales());
        $this->assertCount(1, Services::session()->get('sales_cart'), 'the cart of the cashier is still there');
        $this->assertSame(['type' => FIXED, 'value' => '150'], Services::session()->get('sales_invoice_discount'));
    }

    #[DataProvider('documentOfARegisterProvider')]
    public function testPostComplete_RefusesAnEmployeeWithoutThePermissionADocumentThatCarriesADiscountAndSavesNothing(string $mode, string $uri): void
    {
        $this->registerWithTheDiscountGrant(false);
        $this->withSession(array_merge($this->session, ['sales_mode' => $mode, 'sales_invoice_discount' => ['type' => PERCENT, 'value' => '10']]));

        $sales = $this->countSales();

        $response = $this->post($uri);

        $response->assertSee(lang('Sales.not_authorized'));
        $this->assertSame($sales, $this->countSales());
        $this->assertCount(1, Services::session()->get('sales_cart'));
    }

    public function testPostComplete_RefusesAnEmployeeWithoutThePermissionTheQuoteAnotherSavedWithADiscountAndLeavesItSuspended(): void
    {
        $this->registerWithTheDiscountGrant(false);
        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '100')], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, NEW_ENTRY, ['type' => PERCENT, 'value' => '10']);

        $this->withSession(array_merge($this->session, ['sales_cart' => [], 'sale_id' => NEW_ENTRY]));
        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $saleId]);
        $this->withSession(array_merge($this->session, array_intersect_key(Services::session()->get(), array_flip(['sales_cart', 'sales_mode', 'sale_id', 'sales_customer', 'sales_invoice_discount', 'sales_type', 'sales_payments', 'sales_quote_number']))));

        $this->assertSame(['type' => PERCENT, 'value' => '10'], Services::session()->get('sales_invoice_discount'));

        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Sales.not_authorized'));
        $this->assertSame((string) SUSPENDED, (string) $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow()->sale_status);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => 'invoice_discount_given'])->countAllResults());
    }

    public function testPostSetInvoiceDiscount_RefusesAValueThatIsNotAFieldOfText(): void
    {
        $this->registerWithTheDiscountGrant();

        $response = $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => ['5'], 'invoice_discount_type' => (string) PERCENT]);

        $response->assertStatus(200);
        $response->assertSee(lang('Sales.invoice_discount_invalid'));
        $this->assertNull(Services::session()->get('sales_invoice_discount'));

        $response = $this->post('/sales/setInvoiceDiscount', ['invoice_discount' => '5', 'invoice_discount_type' => ['1']]);

        $response->assertSee(lang('Sales.invoice_discount_invalid'));
        $this->assertNull(Services::session()->get('sales_invoice_discount'));
    }

    public function testGetIndex_WarnsThatTheDiscountNoLongerFitsTheCart(): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_invoice_discount' => ['type' => FIXED, 'value' => '150']]));

        $this->get('/sales')->assertSee(lang('Sales.invoice_discount_exceeds_total'));

        $this->withSession(array_merge($this->session, ['sales_invoice_discount' => ['type' => FIXED, 'value' => '50']]));

        $this->get('/sales')->assertDontSee(lang('Sales.invoice_discount_exceeds_total'));
    }

    public function testPostSuspend_KeepsTheDiscountWithTheSuspendedSale(): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_invoice_discount' => ['type' => FIXED, 'value' => '12.5']]));

        $this->post('/sales/suspend');

        $sale = $this->accountingDb()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow();

        $this->assertSame((string) SUSPENDED, (string) $sale->sale_status);
        $this->assertSame('12.5000', $sale->invoice_discount);
    }

    public function testPostSuspend_DoesNotCarryTheDiscountOfTheRegisterIntoASuspendedReturn(): void
    {
        $this->registerWithTheDiscountGrant();
        $this->withSession(array_merge($this->session, ['sales_mode' => 'return', 'sale_type' => SALE_TYPE_RETURN, 'sales_invoice_discount' => ['type' => PERCENT, 'value' => '10']]));

        $sales = $this->countSales();

        $this->post('/sales/suspend');

        $sale = $this->accountingDb()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow();

        $this->assertSame($sales + 1, $this->countSales());
        $this->assertSame((string) SALE_TYPE_RETURN, (string) $sale->sale_type);
        $this->assertNull($sale->invoice_discount_type);
    }

    public function testPostUnsuspend_GivesTheRegisterBackTheDiscountOfTheSuspendedSale(): void
    {
        $supervisorId = $this->registerWithTheDiscountGrant();
        $item         = $this->item('0', false);
        $saleId       = $this->save([$this->line($item, 0, '1', '100')], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED, NEW_ENTRY, ['type' => PERCENT, 'value' => '10']);

        $this->withSession(array_merge($this->session, ['sales_cart' => [], 'sale_id' => NEW_ENTRY]));
        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $saleId]);

        $this->assertSame(['type' => PERCENT, 'value' => '10'], Services::session()->get('sales_invoice_discount'));
        $this->assertNotNull($supervisorId);
    }

    public function testGetInvoice_ShowsTheDiscountOnTheWholeOfTheInvoiceBetweenTheSubtotalAndTheTax(): void
    {
        $this->startSelling();
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $page = $this->get('/sales/invoice/' . $invoiceId);

        $page->assertStatus(200);
        $page->assertSee('doc-invoice-discount');
        $page->assertSee(lang('Sales.invoice_discount') . ' (' . to_decimals('10') . '%)');
        $page->assertSee(to_currency('710'));
        $page->assertSee(to_currency('-71'));
        $page->assertSee(to_currency('734.85'));
    }

    public function testGetReceipt_ShowsTheDiscountOnTheWholeOfTheSaleAsItWasSaved(): void
    {
        $this->startSelling();
        $cycle  = $this->discountedReferenceInvoice(false);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $page = $this->get('/sales/receipt/' . $saleId);

        $page->assertStatus(200);
        $page->assertSee(lang('Sales.invoice_discount'));
        $page->assertSee(to_currency('-20'));
        $page->assertSee(to_currency('793.5'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function receiptTemplateProvider(): array
    {
        return ['the default receipt' => ['receipt_default'], 'the short receipt' => ['receipt_short'], 'the thermal receipt of 80 mm' => ['receipt_thermal_80'], 'the thermal receipt of 58 mm' => ['receipt_thermal_58']];
    }

    #[DataProvider('receiptTemplateProvider')]
    public function testGetReceipt_ShowsTheDiscountOnTheWholeOfTheSaleOnEveryReceiptTemplate(string $template): void
    {
        $this->startSelling();
        $this->setConfigValue('receipt_template', $template);

        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        try {
            $page = $this->get('/sales/receipt/' . $saleId);

            $page->assertStatus(200);
            $page->assertSee(lang('Sales.invoice_discount') . ' (' . to_decimals('10') . '%)');
            $page->assertSee(to_currency('-71'));
        } finally {
            $this->setConfigValue('receipt_template', 'receipt_default');
        }
    }

    public function testReceiptEmail_ShowsTheDiscountOnTheWholeOfTheSaleAsItWasSaved(): void
    {
        $this->startSelling();

        $cycle  = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_POS, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/receipt/' . $saleId);

        $controller = new Sales();
        $controller->initController(service('request'), service('response'), service('logger'));

        $load = new ReflectionMethod($controller, '_load_sale_data');
        $data = $load->invoke($controller, $saleId);
        $html = view('sales/receipt_email', $data + ['pdf' => false]);

        $this->assertStringContainsString(lang('Sales.invoice_discount') . ' (' . to_decimals('10') . '%)', $html);
        $this->assertStringContainsString(to_currency('-71'), $html);
    }

    public function testGetInvoice_ShowsASaleWithoutADiscountWithoutItsRow(): void
    {
        $invoice = $this->postedInvoice();
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $page = $this->get('/sales/invoice/' . $invoice['saleId']);

        $page->assertStatus(200);
        $page->assertDontSee('doc-invoice-discount');
        $page->assertSee(to_currency('816.5'));
    }

    public function testPostCreditNote_PreparesTheRegisterWithTheShareOfTheDiscountOfTheInvoice(): void
    {
        $this->startSelling();
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->assertTrue($this->jsonOf($this->post('/sales/creditNote/' . $invoiceId))['success']);
        $this->withSession(array_merge($this->session, array_intersect_key(Services::session()->get(), array_flip(['sales_cart', 'sales_mode', 'sales_return_origin', 'sales_customer', 'sale_id', 'sales_type']))));

        $page = $this->get('/sales');

        $page->assertSee('id="invoice_discount_total"');
        $page->assertSee(to_currency('71'));
    }

    protected function config(): array
    {
        return config(\Config\OSPOS::class)->settings;
    }

    private function freshPrefix(): string
    {
        return 'T' . random_int(100000, 999999) . '-';
    }

    private function switchOn(DocumentType $type, string $prefix, int $padding = 3): void
    {
        (new DocumentNumbering())->configure($type, ['enabled' => true, 'prefix' => $prefix, 'padding' => $padding, 'next_number' => 1]);
    }

    /**
     * Completes the sale of one cart line in the mode given, as the register does, and returns the response.
     */
    private function completeAs(string $mode, array $session = []): TestResponse
    {
        $cashierId = $this->createCashierEmployee();
        $itemId    = $this->createTestItem(HAS_NO_STOCK);

        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => $mode], $session));

        return $this->post('/sales/complete');
    }

    public function testPostComplete_GivesTheInvoiceTheNumberOfItsSequenceAndShowsItOnTheInvoice(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 4);

        $response = $this->completeAs('sale_invoice');

        $response->assertSee($prefix . '0001');
        $this->assertSame($prefix . '0001', Database::connect()->table('sales')->where('invoice_number', $prefix . '0001')->get()->getRow()->invoice_number);
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::SalesInvoice)->nextNumber);
    }

    public function testPostComplete_KeepsTheFormatOfTheInvoiceSettingsWhileTheSequenceIsOff(): void
    {
        $format = 'LGC' . random_int(100000, 999999);
        $before = $this->config()['sales_invoice_format'];
        config(OSPOS::class)->settings['sales_invoice_format'] = $format;

        try {
            $response = $this->completeAs('sale_invoice');
        } finally {
            config(OSPOS::class)->settings['sales_invoice_format'] = $before;
        }

        $response->assertSee($format);
        $this->assertSame(1, Database::connect()->table('sales')->where('invoice_number', $format)->countAllResults());
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function sequenceStateProvider(): array
    {
        return ['the sequence is off' => [false], 'the sequence is on' => [true]];
    }

    #[DataProvider('sequenceStateProvider')]
    public function testPostComplete_RefusesAnInvoiceNumberTypedByHandThatAnotherInvoiceHas(bool $sequenceOn): void
    {
        $number = 'DUP' . random_int(100000, 999999);

        if ($sequenceOn) {
            $this->switchOn(DocumentType::SalesInvoice, $this->freshPrefix(), 4);
        }

        $this->completeAs('sale_invoice', ['sales_invoice_number' => $number]);
        $sales = Database::connect()->table('sales')->countAllResults();

        $response = $this->completeAs('sale_invoice', ['sales_invoice_number' => $number]);

        $response->assertSee(lang('Sales.invoice_number_duplicate', [$number]));
        $this->assertSame($sales, Database::connect()->table('sales')->countAllResults());
        $this->assertSame(1, Database::connect()->table('sales')->where('invoice_number', $number)->countAllResults());
    }

    #[DataProvider('sequenceStateProvider')]
    public function testPostComplete_RefusesAQuoteNumberTypedByHandThatAnotherQuoteHas(bool $sequenceOn): void
    {
        $number = 'QDUP' . random_int(100000, 999999);

        if ($sequenceOn) {
            $this->switchOn(DocumentType::SalesQuote, $this->freshPrefix(), 4);
        }

        $this->completeAs('sale_quote', ['sales_quote_number' => $number]);
        $sales = Database::connect()->table('sales')->countAllResults();

        $response = $this->completeAs('sale_quote', ['sales_quote_number' => $number]);

        $response->assertSee(lang('Sales.quote_number_duplicate'));
        $this->assertSame($sales, Database::connect()->table('sales')->countAllResults());
        $this->assertSame(1, Database::connect()->table('sales')->where('quote_number', $number)->countAllResults());
    }

    public function testPostComplete_LeavesNoNumberBehindWhenTheBooksRefuseTheNumberedSale(): void
    {
        $this->startSelling();
        $item         = $this->item('0', false);
        $supervisorId = $this->createBranchLimitedSupervisor(1);
        $this->grantStockLocationIn(1, $supervisorId);
        $this->switchOn(DocumentType::SalesInvoice, $this->freshPrefix(), 4);
        $this->closeThrough(date('Y-m-d'));
        $this->loginAs($supervisorId);
        $this->seedCartLine(1, '100.00', $item);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_customer' => $this->customerId, 'sales_mode' => 'sale_invoice', 'sales_payments' => []]));

        $sales = $this->countSales();

        $response = $this->post('/sales/complete');

        $response->assertSee(date('Y-m-d'));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::SalesInvoice)->nextNumber);
    }

    public function testPostComplete_GivesTheQuoteTheNumberOfItsSequence(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesQuote, $prefix, 4);

        $response = $this->completeAs('sale_quote');

        $response->assertSee($prefix . '0001');
        $this->assertSame(1, Database::connect()->table('sales')->where('quote_number', $prefix . '0001')->countAllResults());
    }

    public function testPostComplete_KeepsTheFormatOfTheInvoiceSettingsForAQuoteWhileTheSequenceIsOff(): void
    {
        $format = 'QLG' . random_int(100000, 999999);
        $before = $this->config()['sales_quote_format'];
        config(OSPOS::class)->settings['sales_quote_format'] = $format;

        try {
            $response = $this->completeAs('sale_quote');
        } finally {
            config(OSPOS::class)->settings['sales_quote_format'] = $before;
        }

        $response->assertSee($format);
        $this->assertSame(1, Database::connect()->table('sales')->where('quote_number', $format)->countAllResults());
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
    }

    public function testPostComplete_ShowsTheCreditNoteNumberOnTheReceiptOfAReturn(): void
    {
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5);
        $cashierId = $this->createCashierEmployee();
        $itemId    = $this->createTestItem(HAS_NO_STOCK);

        $this->loginAs($cashierId);
        $this->seedCartLine(1, '10.00', $itemId);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'return']));

        $response = $this->post('/sales/complete');

        $response->assertSee('id="credit_note_number"');
        $response->assertSee('CN-00001');
    }

    public function testGetIndex_OffersTheNextNumberOfTheInvoiceSequenceAsThePlaceholderOfTheInvoiceField(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 4);
        $cashierId = $this->createCashierEmployee();
        $itemId    = $this->createTestItem(HAS_NO_STOCK);

        $this->loginAs($cashierId);
        $this->seedCartLine(1, '100.00', $itemId);
        $this->withSession(array_merge($this->session, ['sales_mode' => 'sale_invoice']));

        $response = $this->get('/sales');

        $response->assertSee('placeholder="' . $prefix . '0001"');
        $response->assertDontSee('value="' . $prefix . '0001"');
    }

    public function testGetIndex_FillsTheInvoiceFieldWithTheFormatOfTheSettingsWhileTheSequenceIsOff(): void
    {
        $format = 'LGC' . random_int(100000, 999999);
        $before = $this->config()['sales_invoice_format'];
        config(OSPOS::class)->settings['sales_invoice_format'] = $format;
        $cashierId = $this->createCashierEmployee();
        $itemId    = $this->createTestItem(HAS_NO_STOCK);

        try {
            $this->loginAs($cashierId);
            $this->seedCartLine(1, '100.00', $itemId);
            $this->withSession(array_merge($this->session, ['sales_mode' => 'sale_invoice']));

            $response = $this->get('/sales');
        } finally {
            config(OSPOS::class)->settings['sales_invoice_format'] = $before;
        }

        $response->assertSee('value="' . $format . '"');
        $response->assertDontSee('placeholder="' . $format . '"');
    }

    public function testPostSetInvoiceNumber_KeepsANumberWithLettersAndSeparatorsAsTyped(): void
    {
        $this->loginAs($this->createCashierEmployee());

        $this->post('/sales/setInvoiceNumber', ['sales_invoice_number' => '2026/INV-0007'])->assertStatus(200);

        $this->assertSame('2026/INV-0007', Services::session()->get('sales_invoice_number'));
    }

    public function testPostSetInvoiceNumber_KeepsTheLeadingZerosOfANumberTypedByHand(): void
    {
        $this->loginAs($this->createCashierEmployee());

        $this->post('/sales/setInvoiceNumber', ['sales_invoice_number' => '0007'])->assertStatus(200);

        $this->assertSame('0007', Services::session()->get('sales_invoice_number'));
    }

    public function testPostSetInvoiceNumber_DropsTheCharactersANumberCannotHave(): void
    {
        $this->loginAs($this->createCashierEmployee());

        $this->post('/sales/setInvoiceNumber', ['sales_invoice_number' => ' INV 7 <>"\'&; '])->assertStatus(200);

        $this->assertSame('INV7', Services::session()->get('sales_invoice_number'));
    }

    public function testPostSetInvoiceNumber_ForgetsTheNumberWhenTheFieldIsEmptied(): void
    {
        $this->loginAs($this->createCashierEmployee());
        $this->withSession(array_merge($this->session, ['sales_invoice_number' => 'INV-0001']));

        $response = $this->post('/sales/setInvoiceNumber', ['sales_invoice_number' => '']);

        $response->assertStatus(200);
        $this->assertNull(Services::session()->get('sales_invoice_number'));
    }

    public function testPostComplete_GivesTheSequenceNumberToAnInvoiceWhoseFieldWasEmptiedAfterTyping(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 4);
        $this->loginAs($this->createCashierEmployee());
        $this->withSession(array_merge($this->session, ['sales_invoice_number' => $prefix . '0001']));
        $this->post('/sales/setInvoiceNumber', ['sales_invoice_number' => ''])->assertStatus(200);

        $response = $this->completeAs('sale_invoice', ['sales_invoice_number' => Services::session()->get('sales_invoice_number')]);

        $response->assertSee($prefix . '0001');
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::SalesInvoice)->nextNumber);
        $this->assertSame(1, Database::connect()->table('document_numbers')->where('doc_type', DocumentType::SalesInvoice->value)->countAllResults());
    }

    public function testPostComplete_KeepsTheNumberTypedByHandThroughTheRegisterFieldWhileTheSequenceIsOn(): void
    {
        $prefix = $this->freshPrefix();
        $number = 'HAND/' . random_int(100000, 999999);
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 4);
        $this->loginAs($this->createCashierEmployee());
        $this->post('/sales/setInvoiceNumber', ['sales_invoice_number' => $number])->assertStatus(200);

        $response = $this->completeAs('sale_invoice', ['sales_invoice_number' => Services::session()->get('sales_invoice_number')]);

        $response->assertSee($number);
        $this->assertSame(1, Database::connect()->table('sales')->where('invoice_number', $number)->countAllResults());
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::SalesInvoice)->nextNumber);
    }

    public function testGetReceipt_ShowsTheCreditNoteNumberOfAReturnAndNothingForAnotherSale(): void
    {
        $invoice = $this->postedInvoice();
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5);
        $note = $this->creditNoteOf($invoice['saleId'], '10.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/receipt/' . $note)->assertSee('CN-00001');
        $this->get('/sales/receipt/' . $invoice['saleId'])->assertDontSee('id="credit_note_number"');
    }

    public function testGetReceipt_ShowsNoCreditNoteLineWhileTheSequenceIsOff(): void
    {
        $invoice = $this->postedInvoice();
        $note    = $this->creditNoteOf($invoice['saleId'], '10.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/receipt/' . $note)->assertDontSee('id="credit_note_number"');
    }

    public function testGetEdit_ShowsTheNumberOfTheCreditNoteAndOfEachPayment(): void
    {
        $invoice = $this->postedInvoice();
        $this->switchOn(DocumentType::CustomerPayment, 'PAY-', 4);
        $this->switchOn(DocumentType::CreditNote, 'CN-', 5);
        $this->addPayment($invoice['saleId'], '300.00');
        $note = $this->creditNoteOf($invoice['saleId'], '10.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertSee('PAY-0001');
        $response->assertDontSee('id="credit_note_number"');
        $this->get('/sales/edit/' . $note)->assertSee('CN-00001');
    }

    public function testGetEdit_ShowsThePaymentsByTheirIdWhileTheSequenceIsOff(): void
    {
        $invoice   = $this->postedInvoice();
        $paymentId = $this->addPayment($invoice['saleId'], '300.00');
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->get('/sales/edit/' . $invoice['saleId']);

        $response->assertSee(lang('Sales.payment_status') . ' #' . $paymentId);
        $response->assertDontSee('PAY-');
    }

    public function testPostSave_KeepsTheInvoiceNumberOfTheSequenceWhenTheFormLeavesItBlank(): void
    {
        $prefix = $this->freshPrefix();
        $this->switchOn(DocumentType::SalesInvoice, $prefix, 4);
        $this->startSelling();
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100')]);
        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $saleId, [
            'date'               => date($this->config()['dateformat'] . ' ' . $this->config()['timeformat']),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'edited',
            'invoice_number'     => '',
            'number_of_payments' => '0',
            'payment_type_new'   => '--',
            'payment_amount_new' => '0',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame($prefix . '0001', Database::connect()->table('sales')->where('sale_id', $saleId)->get()->getRow()->invoice_number);
    }

    // ---------------------------------------------------------------------------------------
    // The date typed on an invoice, its payment terms and its due date (WP13)
    // ---------------------------------------------------------------------------------------

    /**
     * A supervisor of the first branch with a cart of one line of 100 at the register in the mode
     * of an invoice, with or without the permission to type the date of an invoice.
     */
    protected function registerWithTheDateGrant(bool $granted = true): int
    {
        $this->startSelling();
        $item         = $this->item('0', false);
        $supervisorId = $this->createBranchLimitedSupervisor(1, $granted ? ['sales_invoice_date'] : []);
        $this->grantStockLocationIn(1, $supervisorId);
        $this->loginAs($supervisorId);
        $this->seedCartLine(1, '100.00', $item);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_invoice', 'sales_customer' => $this->customerId, 'sales_payments' => []]));

        return $supervisorId;
    }

    /**
     * The sale the register saved last, which the test then removes with the others.
     */
    protected function lastSale(): object
    {
        $sale = $this->accountingDb()->table('sales')->orderBy('sale_id', 'DESC')->get()->getRow();

        $this->saleIds[] = (int) $sale->sale_id;

        return $sale;
    }

    protected function carryTheTimingToTheNextRequest(): void
    {
        $this->withSession(array_merge($this->session, array_intersect_key(Services::session()->get(), array_flip(['sales_invoice_date', 'sales_payment_terms', 'sales_cart', 'sales_mode', 'sale_id', 'sales_customer', 'sales_payments', 'sale_type']))));
    }

    public function testPostSetInvoiceDate_RefusesAnEmployeeWithoutThePermissionAndSetsNothing(): void
    {
        $this->registerWithTheDateGrant(false);

        $response = $this->post('/sales/setInvoiceDate', ['sales_invoice_date' => $this->daysAgo(3)]);

        $response->assertStatus(403);
        $this->assertFalse($this->jsonOf($response)['success']);
        $this->assertNull(Services::session()->get('sales_invoice_date'));
    }

    public function testPostSetInvoiceDate_RefusesWhileAccountingIsOffAndSetsNothing(): void
    {
        $this->registerWithTheDateGrant();
        $this->setConfigValue('accounting_enabled', '0');

        $response = $this->post('/sales/setInvoiceDate', ['sales_invoice_date' => $this->daysAgo(3)]);

        $response->assertStatus(403);
        $this->assertNull(Services::session()->get('sales_invoice_date'));
    }

    public function testPostSetInvoiceDate_SetsADayUpToTodayAndAnswersTheDueDateOfTheTermsOnTheRegister(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_payment_terms' => '10']));

        $answer = $this->jsonOf($this->post('/sales/setInvoiceDate', ['sales_invoice_date' => $this->daysAgo(3)]));

        $this->assertTrue($answer['success']);
        $this->assertSame($this->daysAgo(3), Services::session()->get('sales_invoice_date'));
        $this->assertSame(date('Y-m-d', strtotime('+7 days')), $answer['due_date']);
        $this->assertSame(to_date(strtotime('+7 days')), $answer['due_date_label']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedRegisterDayProvider(): array
    {
        return [
            'tomorrow'          => [date('Y-m-d', strtotime('+1 day'))],
            'not a date'        => ['soon'],
            'before the books'  => ['2019-12-31'],
        ];
    }

    #[DataProvider('refusedRegisterDayProvider')]
    public function testPostSetInvoiceDate_RefusesADayThatIsNotUpToTodayOrIsBeforeTheBooksAndKeepsWhatWasSet(string $typed): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_invoice_date' => $this->daysAgo(2)]));

        $answer = $this->jsonOf($this->post('/sales/setInvoiceDate', ['sales_invoice_date' => $typed]));

        $this->assertFalse($answer['success']);
        $this->assertNotSame('', $answer['message']);
        $this->assertSame($this->daysAgo(2), Services::session()->get('sales_invoice_date'));
    }

    public function testPostSetInvoiceDate_RefusesAListOfDaysAndKeepsWhatWasSet(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_invoice_date' => $this->daysAgo(2)]));

        $answer = $this->jsonOf($this->post('/sales/setInvoiceDate', ['sales_invoice_date' => [$this->daysAgo(1)]]));

        $this->assertFalse($answer['success']);
        $this->assertNotSame('', $answer['message']);
        $this->assertSame($this->daysAgo(2), Services::session()->get('sales_invoice_date'));
    }

    public function testPostSetInvoiceDate_TakesBackTheDayWhenItIsSentEmpty(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_invoice_date' => $this->daysAgo(2)]));

        $answer = $this->jsonOf($this->post('/sales/setInvoiceDate', ['sales_invoice_date' => '']));

        $this->assertTrue($answer['success']);
        $this->assertNull(Services::session()->get('sales_invoice_date'));
    }

    public function testPostSetPaymentTerms_SetsTheTermsForAnyEmployeeAndAnswersTheDueDate(): void
    {
        $this->registerWithTheDateGrant(false);

        $answer = $this->jsonOf($this->post('/sales/setPaymentTerms', ['sales_payment_terms' => '30']));

        $this->assertTrue($answer['success']);
        $this->assertSame('30', Services::session()->get('sales_payment_terms'));
        $this->assertSame(date('Y-m-d', strtotime('+30 days')), $answer['due_date']);
    }

    public function testPostSetPaymentTerms_RefusesWhatIsNotAWholeNumberOfDaysAndKeepsWhatWasSet(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_payment_terms' => '5']));

        foreach (['-1', '2.5', 'soon', '3651'] as $typed) {
            $answer = $this->jsonOf($this->post('/sales/setPaymentTerms', ['sales_payment_terms' => $typed]));

            $this->assertFalse($answer['success'], $typed);
            $this->assertSame('5', Services::session()->get('sales_payment_terms'), $typed);
        }
    }

    public function testPostSetPaymentTerms_RefusesAListOfTermsAndKeepsWhatWasSet(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_payment_terms' => '5']));

        $answer = $this->jsonOf($this->post('/sales/setPaymentTerms', ['sales_payment_terms' => ['30']]));

        $this->assertFalse($answer['success']);
        $this->assertNotSame('', $answer['message']);
        $this->assertSame('5', Services::session()->get('sales_payment_terms'));
    }

    public function testPostSetPaymentTerms_TakesBackTheTermsWhenTheyAreSentEmptyOrZeroAndRefusesWhileAccountingIsOff(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_payment_terms' => '5']));

        $this->assertTrue($this->jsonOf($this->post('/sales/setPaymentTerms', ['sales_payment_terms' => '0']))['success']);
        $this->assertNull(Services::session()->get('sales_payment_terms'));

        $this->withSession(array_merge($this->session, ['sales_payment_terms' => '5']));
        $this->setConfigValue('accounting_enabled', '0');

        $this->post('/sales/setPaymentTerms', ['sales_payment_terms' => '9'])->assertStatus(403);
        $this->assertSame('5', Services::session()->get('sales_payment_terms'));
    }

    public function testGetIndex_OffersTheDateOnlyToAnEmployeeWhoMayTypeItAndTheTermsToEveryoneInTheModeOfAnInvoice(): void
    {
        $this->registerWithTheDateGrant();

        $page = $this->get('/sales');

        $page->assertSee('id="sales_invoice_date"');
        $page->assertSee('id="sales_payment_terms"');
        $page->assertSee('id="sales_due_date"');

        $this->registerWithTheDateGrant(false);

        $page = $this->get('/sales');

        $page->assertDontSee('id="sales_invoice_date"');
        $page->assertSee('id="sales_payment_terms"');
    }

    public function testGetIndex_OffersNeitherTheDateNorTheTermsOutsideTheModeOfAnInvoiceOrWhileAccountingIsOff(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_mode' => 'sale']));

        $this->get('/sales')->assertDontSee('id="sales_payment_terms"');

        $this->withSession(array_merge($this->session, ['sales_mode' => 'sale_invoice']));
        $this->setConfigValue('accounting_enabled', '0');

        $page = $this->get('/sales');

        $page->assertDontSee('id="sales_invoice_date"');
        $page->assertDontSee('id="sales_payment_terms"');
    }

    public function testPostComplete_DatesTheInvoiceByTheDayAndTheTermsOfTheRegisterAndPrintsTheDueDate(): void
    {
        $this->registerWithTheDateGrant();

        $this->post('/sales/setInvoiceDate', ['sales_invoice_date' => $this->daysAgo(6)]);
        $this->carryTheTimingToTheNextRequest();
        $this->post('/sales/setPaymentTerms', ['sales_payment_terms' => '10']);
        $this->carryTheTimingToTheNextRequest();

        $sales = $this->countSales();

        $response = $this->post('/sales/complete');

        $this->assertSame($sales + 1, $this->countSales());

        $sale = $this->lastSale();

        $this->assertSame($this->daysAgo(6), $sale->invoice_date);
        $this->assertSame(10, (int) $sale->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+4 days')), $sale->due_date);
        $this->assertSame([$this->daysAgo(6)], array_values(array_unique(array_column($this->standing((int) $sale->sale_id), 'date'))));
        $response->assertSee(lang('Sales.due_date'));
        $response->assertSee(to_date(strtotime('+4 days')));
        $response->assertSee(to_date(strtotime('-6 days')));
        $this->assertNull(Services::session()->get('sales_invoice_date'), 'the register forgets the day of an invoice that was made');
        $this->assertNull(Services::session()->get('sales_payment_terms'));
    }

    public function testPostComplete_PrintsNoDueDateOnAnInvoiceWithoutTerms(): void
    {
        $this->registerWithTheDateGrant();

        $response = $this->post('/sales/complete');

        $sale = $this->lastSale();

        $this->assertNull($sale->payment_terms_days);
        $response->assertDontSee(lang('Sales.due_date'));
    }

    public function testPostComplete_RefusesADayInAClosedPeriodAndSaysWhyAndSavesNothing(): void
    {
        $this->registerWithTheDateGrant();
        $this->closeThrough($this->daysAgo(5));
        $this->withSession(array_merge($this->session, ['sales_invoice_date' => $this->daysAgo(8)]));

        $sales = $this->countSales();

        $response = $this->post('/sales/complete');

        $company = (string) $this->accountingDb()->table('companies')->select('name')->where('company_id', 1)->get()->getRow()->name;

        $response->assertSee('<div class="alert alert-danger">' . esc(lang('Accounting.posting_period_closed', [$this->daysAgo(8), $this->daysAgo(5), $company])) . '</div>');
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertCount(1, Services::session()->get('sales_cart'), 'the cart of the cashier is still there');
    }

    public function testPostComplete_RefusesTheDayOfAnEmployeeWhoMayNoLongerTypeItAndSavesNothing(): void
    {
        $this->registerWithTheDateGrant(false);
        $this->withSession(array_merge($this->session, ['sales_invoice_date' => $this->daysAgo(3)]));

        $sales = $this->countSales();

        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Accounting.error_permission_denied'));
        $this->assertSame($sales, $this->countSales());
    }

    public function testPostSuspend_KeepsTheDayAndTheTermsOfADraftAndTheRegisterBringsThemBackWhenItIsTakenUp(): void
    {
        $this->registerWithTheDateGrant();
        $this->withSession(array_merge($this->session, ['sales_invoice_date' => $this->daysAgo(4), 'sales_payment_terms' => '20', 'sale_type' => SALE_TYPE_INVOICE]));

        $this->post('/sales/suspend');

        $draft = $this->lastSale();

        $this->assertSame((string) SUSPENDED, (string) $draft->sale_status);
        $this->assertSame($this->daysAgo(4), $draft->invoice_date);
        $this->assertSame(20, (int) $draft->payment_terms_days);
        $this->assertNull($draft->due_date);
        $this->assertSame(0, $this->countJournals());

        $this->withSession(array_merge($this->session, ['sales_cart' => [], 'sale_id' => NEW_ENTRY]));
        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $draft->sale_id]);

        $this->assertSame($this->daysAgo(4), Services::session()->get('sales_invoice_date'));
        $this->assertSame('20', Services::session()->get('sales_payment_terms'));
    }

    public function testGetEdit_ShowsTheDayTheTermsAndTheDueDateOfAnIssuedInvoice(): void
    {
        $this->registerWithTheDateGrant();

        $saleId = $this->invoiceDated('100.00', $this->daysAgo(6), 10);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $page = $this->get('/sales/edit/' . $saleId);

        $page->assertSee('id="invoice_date"');
        $page->assertSee(to_date(strtotime('-6 days')));
        $page->assertSee('id="payment_terms_days"');
        $page->assertSee('id="due_date"');
        $page->assertSee(to_date(strtotime('+4 days')));
    }

    public function testGetEdit_ShowsNoDayTermsOrDueDateOnASaleThatIsNotAnInvoiceOrWhileAccountingIsOff(): void
    {
        $this->startSelling();

        $pos = $this->save([$this->line($this->item('0', false), 0, '1', '40')], [], [$this->payment(lang('Sales.cash'), '40.00')], SALE_TYPE_POS);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->get('/sales/edit/' . $pos)->assertDontSee('id="payment_terms_days"');

        $invoice = $this->invoiceOf('100.00');

        $this->setConfigValue('accounting_enabled', '0');

        $page = $this->get('/sales/edit/' . $invoice);

        $page->assertDontSee('id="payment_terms_days"');
        $page->assertDontSee('id="due_date"');
    }

    public function testPostSave_ChangesThePaymentTermsOfAnIssuedInvoiceAndLeavesItsJournalsAsTheyWere(): void
    {
        $this->registerWithTheDateGrant();

        $saleId = $this->invoiceDated('100.00', $this->daysAgo(6), 1);
        $before = $this->journals($saleId);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $saleId, [
            'date'               => to_datetime(strtotime((string) $this->saleRow($saleId)->sale_time)),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'edited',
            'invoice_number'     => '',
            'number_of_payments' => '0',
            'payment_type_new'   => '--',
            'payment_amount_new' => '0',
            'payment_terms_days' => '30',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(30, (int) $this->saleRow($saleId)->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime('+24 days')), $this->saleRow($saleId)->due_date);
        $this->assertSame($before, $this->journals($saleId));
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'sale', 'entity_id' => $saleId, 'action' => 'payment_terms_changed'])->countAllResults());
    }

    public function testPostSave_RefusesTermsThatAreNotAWholeNumberOfDaysAndSaysSo(): void
    {
        $this->registerWithTheDateGrant();

        $saleId = $this->invoiceDated('100.00', $this->daysAgo(6), 1);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $saleId, [
            'date'               => to_datetime(strtotime((string) $this->saleRow($saleId)->sale_time)),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'edited',
            'invoice_number'     => '',
            'number_of_payments' => '0',
            'payment_type_new'   => '--',
            'payment_amount_new' => '0',
            'payment_terms_days' => 'soon',
        ]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_payment_terms_invalid', [(string) 3650]), $result['message']);
        $this->assertSame(1, (int) $this->saleRow($saleId)->payment_terms_days);
    }

    public function testPostSave_KeepsTheTermsOfAnInvoiceWhenTheFormSendsNone(): void
    {
        $this->registerWithTheDateGrant();

        $saleId = $this->invoiceDated('100.00', $this->daysAgo(6), 7);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $result = $this->jsonOf($this->post('/sales/save/' . $saleId, [
            'date'               => to_datetime(strtotime((string) $this->saleRow($saleId)->sale_time)),
            'customer_id'        => (string) $this->customerId,
            'employee_id'        => (string) $this->employeeId,
            'comment'            => 'edited',
            'invoice_number'     => '',
            'number_of_payments' => '0',
            'payment_type_new'   => '--',
            'payment_amount_new' => '0',
        ]));

        $this->assertTrue($result['success']);
        $this->assertSame(7, (int) $this->saleRow($saleId)->payment_terms_days);
    }

    public function testGetInvoice_PrintsTheDayOfTheInvoiceAndTheDueDateOnlyWhenItHasTerms(): void
    {
        $this->registerWithTheDateGrant();

        $withTerms    = $this->invoiceDated('100.00', $this->daysAgo(6), 10);
        $withoutTerms = $this->invoiceDated('50.00', $this->daysAgo(2));

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $page = $this->get('/sales/invoice/' . $withTerms);

        $page->assertSee(to_date(strtotime('-6 days')));
        $page->assertSee(lang('Sales.due_date'));
        $page->assertSee(to_date(strtotime('+4 days')));

        $page = $this->get('/sales/invoice/' . $withoutTerms);

        $page->assertSee(to_date(strtotime('-2 days')));
        $page->assertDontSee(lang('Sales.due_date'));
    }

    // ---------------------------------------------------------------------------------------
    // Drafts and quotes: their list, their page, the issue of a draft, the status of a quote and
    // its conversion into an invoice (WP14)
    // ---------------------------------------------------------------------------------------

    /**
     * A supervisor of the first branch at the register, with the books of the test started.
     *
     * @param list<string> $extraGrants
     */
    protected function supervisorForDocuments(array $extraGrants = []): int
    {
        $this->startSelling();

        $supervisorId = $this->createBranchLimitedSupervisor(1, $extraGrants);

        $this->grantStockLocationIn(1, $supervisorId);
        $this->loginAs($supervisorId);

        return $supervisorId;
    }

    /**
     * The reference lines with their two items taxed 15%, so a draft of them comes to 816.50.
     *
     * @return list<array<string, mixed>>
     */
    protected function taxedLines(): array
    {
        $cycle = $this->referenceInvoice();

        foreach ([$cycle['cream'], $cycle['perfume']] as $itemId) {
            Database::connect()->table('items_taxes')->insert(['item_id' => $itemId, 'name' => 'VAT', 'percent' => '15']);
        }

        return $cycle['items'];
    }

    /**
     * What the register holds after a request, handed to the next one the way the browser does.
     */
    protected function carryTheRegisterToTheNextRequest(): void
    {
        $keys = ['sales_cart', 'sales_mode', 'sale_id', 'sales_customer', 'sales_payments', 'sale_type', 'sales_quote_source', 'sales_invoice_date', 'sales_payment_terms', 'sales_invoice_discount', 'sales_delivered', 'sales_comment', 'sales_employee', 'sales_quote_number', 'cash_mode', 'cash_rounding', 'sales_return_document', 'sales_return_origin'];

        $this->withSession(array_merge($this->session, array_intersect_key(Services::session()->get(), array_flip($keys))));
    }

    /**
     * The markup of one form of a page, from its opening tag to its closing one.
     */
    protected function formOf(string $body, string $id): string
    {
        $this->assertSame(1, preg_match('/<form[^>]*id="' . preg_quote($id, '/') . '".*?<\/form>/s', $body, $found), "the page has a form {$id}");

        return $found[0];
    }

    protected function statusOfTheQuote(int $quoteId): ?string
    {
        $value = Database::connect()->table('sales')->select('quote_status')->where('sale_id', $quoteId)->get()->getRow()->quote_status;

        return $value === null ? null : (string) $value;
    }

    protected function saleStatusOf(int $saleId): int
    {
        return (int) Database::connect()->table('sales')->select('sale_status')->where('sale_id', $saleId)->get()->getRow()->sale_status;
    }

    public function testGetDocuments_ListsTheDraftsAndTheQuotesOfTheBranchesOfTheEmployeeByTheirNumbers(): void
    {
        $this->supervisorForDocuments();

        $items  = $this->taxedLines();
        $draft  = $this->draftInvoice($items);
        $open   = $this->quote(null, $items);
        $second = $this->createSecondBranch();
        $far    = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        Database::connect()->table('sales')->where('sale_id', $open)->update(['quote_number' => 'Q26-0042']);

        $page = $this->get('/sales/documents');

        $page->assertStatus(200);
        $page->assertSee('draft-' . $draft);
        $page->assertSee('Q26-0042');
        $page->assertDontSee('draft-' . $far);
        $page->assertSee('sales/document/' . $draft);
        $page->assertSee(lang('Sales.quote_status_open'));
    }

    public function testGetDocuments_ShowsTheBranchColumnOnlyToAnEmployeeOfSeveralBranches(): void
    {
        $this->supervisorForDocuments();
        $this->draftInvoice($this->taxedLines());

        $this->get('/sales/documents')->assertDontSeeElement('th.document-branch');

        $second = $this->createSecondBranch();
        $both   = $this->employeeWithGrants(['sales', 'sales_stock', 'reports_sales'], [1, $second]);

        $this->loginAs($both);

        $this->get('/sales/documents')->assertSeeElement('th.document-branch');
    }

    public function testGetDocuments_SendsAnEmployeeWithoutTheReportsSalesGrantToTheNoAccessPage(): void
    {
        $this->startSelling();
        $this->loginAs($this->createCashierEmployee());

        $response = $this->get('/sales/documents');

        $response->assertStatus(302);
        $response->assertRedirectTo('no_access/sales/reports_sales');
    }

    public function testGetDocuments_NarrowsTheQuotesToTheStatusAsked(): void
    {
        $this->supervisorForDocuments();

        $items    = $this->taxedLines();
        $open     = $this->quote(null, $items);
        $approved = $this->quote(null, $items);

        Database::connect()->table('sales')->where('sale_id', $open)->update(['quote_number' => 'OPEN-1']);
        Database::connect()->table('sales')->where('sale_id', $approved)->update(['quote_number' => 'APPROVED-1', 'quote_status' => QuoteStatus::Approved->value]);

        $page = $this->get('/sales/documents?quote_status=' . QuoteStatus::Approved->value);

        $page->assertSee('APPROVED-1');
        $page->assertDontSee('OPEN-1');
        $this->get('/sales/documents?quote_status=abc')->assertSee('OPEN-1');
    }

    public function testGetDocuments_EscapesTheNamesItShows(): void
    {
        $this->supervisorForDocuments();

        $customer = $this->anotherCustomer('<script>alert(1)</script>', 'Saad');

        $this->save($this->taxedLines(), [], [], SALE_TYPE_INVOICE, $customer, true, null, SUSPENDED);

        $page = $this->get('/sales/documents');

        $page->assertDontSee('<script>alert(1)</script>');
        $page->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;');
    }

    public function testGetIndex_OffersTheLinkToTheDocumentsOnlyToWhoMayOpenThem(): void
    {
        $this->supervisorForDocuments();

        $this->get('/sales')->assertSee('id="sales_documents_button"');

        $this->loginAs($this->createCashierEmployee());
        $this->grantStockLocationIn(1, (int) $this->session['person_id']);

        $this->get('/sales')->assertDontSee('id="sales_documents_button"');
    }

    public function testGetDocument_ShowsTheDraftByItsTemporaryNumberWithItsLinesAndWhatItWouldComeTo(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());
        $page  = $this->get('/sales/document/' . $draft);

        $page->assertStatus(200);
        $page->assertSee('draft-' . $draft);
        $page->assertSee(lang('Sales.draft_invoice'));
        $page->assertSee(to_currency('816.50'));
        $page->assertSee(to_currency('106.50'));
        $page->assertSee(lang('Sales.document_figures_note'));
        $page->assertSee('Omar Khaled');
    }

    public function testGetDocument_OffersTheIssueOfADraftAsAFormThatPostsWithTheTokenAndNeverAsALink(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());
        $page  = $this->get('/sales/document/' . $draft);
        $token = config('Security')->tokenName;

        $page->assertSee('<form method="post" id="issue_form" action="' . site_url('sales/issue/' . $draft) . '"');
        $page->assertSee('name="' . $token . '"');
        $page->assertDontSee('<a ' . 'id="issue');
        $this->assertStringNotContainsString('href="' . site_url('sales/issue/' . $draft) . '"', $page->getBody());
    }

    public function testGetDocument_OffersTheDayToAnEmployeeWhoMayTypeItAndTheTermsToWhoIssuesWhileAccountingIsOn(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        $page = $this->get('/sales/document/' . $draft);

        $page->assertSee('id="issue_payment_terms"');
        $page->assertDontSee('id="issue_invoice_date"');

        $this->loginAs($this->createBranchLimitedSupervisor(1, ['sales_invoice_date']));

        $this->get('/sales/document/' . $draft)->assertSee('id="issue_invoice_date"');
    }

    public function testGetDocument_OffersNeitherTheDayNorTheTermsWhileAccountingIsOff(): void
    {
        $this->supervisorForDocuments(['sales_invoice_date']);

        $draft = $this->draftInvoice($this->taxedLines());

        $this->setConfigValue('accounting_enabled', '0');

        $page = $this->get('/sales/document/' . $draft);

        $page->assertSee('id="issue_form"');
        $page->assertDontSee('id="issue_invoice_date"');
        $page->assertDontSee('id="issue_payment_terms"');
    }

    public function testGetDocument_ShowsTheDayAndTheTermsAdraftWasSavedWith(): void
    {
        $this->supervisorForDocuments();
        $this->grantInvoiceDate();

        $draft = $this->save($this->taxedLines(), [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(4), paymentTerms: 21);
        $page  = $this->get('/sales/document/' . $draft);

        $page->assertSee('id="draft_invoice_date"');
        $page->assertSee(to_date(strtotime($this->daysAgo(4))));
        $page->assertSee('id="draft_payment_terms"');
        $page->assertSee('21 ' . lang('Sales.payment_terms_days'));
    }

    public function testGetDocument_ShowsAQuoteWithItsStatusAndOnlyTheStatusesItMayMoveTo(): void
    {
        $this->supervisorForDocuments();

        $quote = $this->quote(null, $this->taxedLines());
        $page  = $this->get('/sales/document/' . $quote);

        $page->assertSee(lang('Sales.quote'));
        $page->assertSee('id="quote_status_badge"');

        foreach ([QuoteStatus::Sent, QuoteStatus::Viewed, QuoteStatus::Replied, QuoteStatus::Approved] as $state) {
            $page->assertSee('<option value="' . $state->value . '">' . lang($state->languageKey()) . '</option>');
        }

        foreach ([QuoteStatus::Draft, QuoteStatus::Open, QuoteStatus::Converted, QuoteStatus::Ordered] as $state) {
            $page->assertDontSee('<option value="' . $state->value . '">');
        }

        $page->assertSee(lang('Sales.quote_status_manual_note'));
        $page->assertDontSee('id="issue_form"');
    }

    public function testGetDocument_OffersTheConversionOfAQuoteAndNotOfADraftQuoteOrAConvertedOne(): void
    {
        $this->supervisorForDocuments();

        $items     = $this->taxedLines();
        $open      = $this->quote(null, $items);
        $draft     = $this->quote(QuoteStatus::Draft, $items);
        $converted = $this->quote(null, $items);

        Database::connect()->table('sales')->where('sale_id', $converted)->update(['quote_status' => QuoteStatus::Converted->value]);

        $this->get('/sales/document/' . $open)->assertSee('id="convert_form"');
        $this->get('/sales/document/' . $draft)->assertDontSee('id="convert_form"');

        $page = $this->get('/sales/document/' . $converted);

        $page->assertDontSee('id="convert_form"');
        $page->assertDontSee('id="register_form"');
        $page->assertDontSee('id="delete_button"');
        $page->assertDontSee('id="quote_status_form"');
        $page->assertSee('id="quote_locked_note"');
    }

    public function testGetDocument_AsksForAConfirmationOnlyWhileTheCustomerHasNotAcceptedTheQuote(): void
    {
        $this->supervisorForDocuments();

        $items    = $this->taxedLines();
        $open     = $this->quote(null, $items);
        $approved = $this->quote(null, $items);

        Database::connect()->table('sales')->where('sale_id', $approved)->update(['quote_status' => QuoteStatus::Approved->value]);

        $this->assertMatchesRegularExpression('/id="convert_button" data-confirm="[^"]+"/', $this->get('/sales/document/' . $open)->getBody());
        $this->assertMatchesRegularExpression('/id="convert_button" data-confirm=""/', $this->get('/sales/document/' . $approved)->getBody());
    }

    public function testGetDocument_NamesTheInvoiceAQuoteWasConvertedInto(): void
    {
        $this->supervisorForDocuments();
        $this->allowEmployeeEverywhere();

        $items = $this->taxedLines();
        $quote = $this->quote(null, $items);

        $invoice = $this->save($items, [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote);

        Database::connect()->table('sales')->where('sale_id', $invoice)->update(['invoice_number' => 'INV-77']);

        $page = $this->get('/sales/document/' . $quote);

        $page->assertSee('id="converted_invoice"');
        $page->assertSee(lang('Sales.quote_converted_to', ['INV-77']));
        $page->assertSee('sales/invoice/' . $invoice);
    }

    public function testGetDocument_HidesADocumentOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->startSelling();

        $second = $this->createSecondBranch();
        $draft  = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $this->expectException(PageNotFoundException::class);

        $this->get('/sales/document/' . $draft);
    }

    public function testGetDocument_HidesWhatIsNotADraftOrAQuote(): void
    {
        $this->supervisorForDocuments();

        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);

        $this->expectException(PageNotFoundException::class);

        $this->get('/sales/document/' . $invoice);
    }

    public function testGetDocument_HidesASaleThatIsNotThere(): void
    {
        $this->supervisorForDocuments();

        $this->expectException(PageNotFoundException::class);

        $this->get('/sales/document/999999');
    }

    public function testGetDocument_SendsAnEmployeeWithoutTheReportsSalesGrantToTheNoAccessPage(): void
    {
        $this->startSelling();

        $draft = $this->draftInvoice($this->taxedLines());

        $this->loginAs($this->createCashierEmployee());

        $response = $this->get('/sales/document/' . $draft);

        $response->assertStatus(302);
        $response->assertRedirectTo('no_access/sales/reports_sales');
    }

    public function testGetDocument_EscapesTheCommentAndTheNamesItShows(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        Database::connect()->table('sales')->where('sale_id', $draft)->update(['comment' => '<img src=x onerror=alert(1)>']);

        $page = $this->get('/sales/document/' . $draft);

        $page->assertDontSee('<img src=x onerror=alert(1)>');
        $page->assertSee('&lt;img src=x onerror=alert(1)&gt;');
    }

    public function testGetDocument_EscapesTheNameAndTheDescriptionOfEveryLineAndEveryNameAndNumberOnTheHeader(): void
    {
        $supervisor = $this->supervisorForDocuments();
        $db         = Database::connect();
        $item       = $this->item('0', false);
        $lines      = [$this->line($item, 0, '1', '100')];

        $lines[0]['description'] = '<u>described</u>';

        $draft = $this->save($lines, [], [], SALE_TYPE_INVOICE, $this->anotherCustomer('<b>Customer', 'Name'), true, null, SUSPENDED);
        $quote = $this->save($lines, [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);

        $db->table('items')->where('item_id', $item)->update(['name' => '<img src=x onerror=alert(2)>']);
        $db->table('people')->where('person_id', $this->employeeId)->update(['first_name' => '<i>Employee']);
        $db->table('branches')->where('branch_id', 1)->update(['code' => '<s>B1']);
        $db->table('sales')->where('sale_id', $quote)->update(['quote_number' => '<q>Q-1']);

        foreach ([$draft, $quote] as $saleId) {
            $body = $this->get('/sales/document/' . $saleId)->getBody();

            foreach (['<img src=x onerror=alert(2)>', '<u>described</u>', '<i>Employee', '<s>B1'] as $markup) {
                $this->assertStringNotContainsString($markup, $body, "{$markup} on the page of {$saleId}");
                $this->assertStringContainsString(esc($markup), $body, "{$markup} escaped on the page of {$saleId}");
            }
        }

        $this->assertStringNotContainsString('<b>Customer', $this->get('/sales/document/' . $draft)->getBody());
        $this->assertStringNotContainsString('<q>Q-1', $this->get('/sales/document/' . $quote)->getBody());
        $this->assertStringContainsString(esc('<q>Q-1'), $this->get('/sales/document/' . $quote)->getBody());
        $this->assertGreaterThan(0, $supervisor);
    }

    public function testGetDocument_EscapesTheNumberOfTheInvoiceAQuoteWasConvertedInto(): void
    {
        $this->supervisorForDocuments();
        $this->allowEmployeeEverywhere();

        $items   = $this->taxedLines();
        $quote   = $this->quote(null, $items);
        $invoice = $this->save($items, [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, null, $quote);

        Database::connect()->table('sales')->where('sale_id', $invoice)->update(['invoice_number' => '<b>INV-9</b>']);

        $body = $this->get('/sales/document/' . $quote)->getBody();

        $this->assertStringNotContainsString('<b>INV-9</b>', $body);
        $this->assertStringContainsString('&lt;b&gt;INV-9&lt;/b&gt;', $body);
    }

    public function testGetDocuments_EscapesTheNumberTheCustomerTheEmployeeAndTheBranchOfEveryQuote(): void
    {
        $this->supervisorForDocuments();

        $second = $this->createSecondBranch();
        $both   = $this->employeeWithGrants(['sales', 'sales_stock', 'reports_sales'], [1, $second]);
        $db     = Database::connect();
        $quote  = $this->save($this->taxedLines(), [], [], SALE_TYPE_QUOTE, $this->anotherCustomer('<b>Quoted', 'Customer'), true, null, SUSPENDED);

        $db->table('sales')->where('sale_id', $quote)->update(['quote_number' => '<q>Q-2']);
        $db->table('people')->where('person_id', $this->employeeId)->update(['first_name' => '<i>Seller']);
        $db->table('branches')->where('branch_id', 1)->update(['code' => '<s>B1']);

        $this->loginAs($both);

        $body = $this->get('/sales/documents')->getBody();

        foreach (['<q>Q-2', '<b>Quoted', '<i>Seller', '<s>B1'] as $markup) {
            $this->assertStringNotContainsString($markup, $body, $markup);
            $this->assertStringContainsString(esc($markup), $body, $markup);
        }
    }

    public function testGetDocuments_SaysThatOnlyTheNewestAreListedWhenAListIsCutAtTheLimit(): void
    {
        $this->supervisorForDocuments();

        $this->suspendedRows(SaleDocumentList::LIMIT, SALE_TYPE_INVOICE);

        $page = $this->get('/sales/documents');

        $page->assertSee('id="drafts_limited"');
        $page->assertDontSee('id="quotes_limited"');
        $this->assertStringContainsString(lang('Sales.documents_drafts_limited', [SaleDocumentList::LIMIT]), $page->getBody());

        $this->suspendedRows(SaleDocumentList::LIMIT, SALE_TYPE_QUOTE);

        $this->get('/sales/documents')->assertSee('id="quotes_limited"');
    }

    public function testGetDocuments_SaysNothingAboutALimitWhileEveryDocumentIsListed(): void
    {
        $this->supervisorForDocuments();

        $this->suspendedRows(SaleDocumentList::LIMIT - 1, SALE_TYPE_INVOICE);
        $this->suspendedRows(SaleDocumentList::LIMIT - 1, SALE_TYPE_QUOTE);

        $page = $this->get('/sales/documents');

        $page->assertDontSee('id="drafts_limited"');
        $page->assertDontSee('id="quotes_limited"');
    }

    public function testGetDocuments_NamesTheActionColumnAndGivesEveryOpenLinkTheNumberOfItsDocument(): void
    {
        $this->supervisorForDocuments();

        $items = $this->taxedLines();
        $draft = $this->draftInvoice($items);
        $quote = $this->quote(null, $items);

        Database::connect()->table('sales')->where('sale_id', $quote)->update(['quote_number' => 'Q26-0007']);

        $body = $this->get('/sales/documents')->getBody();

        $this->assertSame(2, substr_count($body, '<span class="visually-hidden">' . lang('Sales.document_actions') . '</span>'));
        $this->assertStringNotContainsString('<th scope="col"></th>', $body);
        $this->assertStringContainsString('aria-label="' . lang('Sales.document_open') . ' draft-' . $draft . '"', $body);
        $this->assertStringContainsString('aria-label="' . lang('Sales.document_open') . ' Q26-0007"', $body);
    }

    public function testGetDocument_AnswersARequestThatFailedAndLocksTheButtonsWhileOneRuns(): void
    {
        $this->supervisorForDocuments();

        $body = $this->get('/sales/document/' . $this->draftInvoice($this->taxedLines()))->getBody();

        $this->assertStringContainsString('.fail(function(request)', $body, 'a refusal with a status other than 200 is answered');
        $this->assertStringContainsString('request.responseJSON.message', $body, 'the words of the refusal are shown');
        $this->assertStringContainsString(json_encode(lang('Sales.document_request_failed'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), $body);
        $this->assertStringContainsString("buttons.prop('disabled', true)", $body, 'a second click is not sent while the first runs');
        $this->assertStringContainsString("buttons.prop('disabled', false)", $body, 'and the buttons come back when the request is answered');
    }

    public function testGetDocument_OffersNoConversionWhileInvoicesAreSwitchedOff(): void
    {
        $this->supervisorForDocuments();

        $quote  = $this->quote(null, $this->taxedLines());
        $before = $this->config()['invoice_enable'];

        config(OSPOS::class)->settings['invoice_enable'] = 0;

        try {
            $page = $this->get('/sales/document/' . $quote);
        } finally {
            config(OSPOS::class)->settings['invoice_enable'] = $before;
        }

        $page->assertDontSee('id="convert_form"');
    }

    public function testPostIssue_IssuesTheDraftAndAnswersWithTheInvoiceToShow(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft));

        $this->assertTrue($answer['success']);
        $this->assertSame(lang('Sales.draft_issued'), $answer['message']);
        $this->assertSame(site_url('sales/invoice/' . $draft), $answer['redirect']);
        $this->assertSame(COMPLETED, $this->saleStatusOf($draft));
        $this->assertSame(['revenue', 'cogs'], array_column($this->standing($draft), 'entry'));
        $this->assertCount(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $draft, 'action' => 'draft_issued'])->get()->getResult());
    }

    public function testPostIssue_TakesTheNextNumberOfTheInvoiceSequenceAtTheIssueAndNotBefore(): void
    {
        $this->supervisorForDocuments();
        $this->switchOn(DocumentType::SalesInvoice, $prefix = $this->freshPrefix());

        $draft = $this->draftInvoice($this->taxedLines());

        $this->assertNull(Database::connect()->table('sales')->where('sale_id', $draft)->get()->getRow()->invoice_number);

        $this->get('/sales/document/' . $draft)->assertSee('draft-' . $draft);

        $this->assertTrue($this->jsonOf($this->post('/sales/issue/' . $draft))['success']);
        $this->assertSame($prefix . '001', Database::connect()->table('sales')->where('sale_id', $draft)->get()->getRow()->invoice_number);
    }

    public function testPostIssue_KeepsTheFormatOfTheInvoiceSettingsWhileTheSequenceIsOff(): void
    {
        $this->supervisorForDocuments();

        $draft  = $this->draftInvoice($this->taxedLines());
        $format = 'LGC' . random_int(100000, 999999);
        $before = $this->config()['sales_invoice_format'];

        config(OSPOS::class)->settings['sales_invoice_format'] = $format;

        try {
            $answer = $this->jsonOf($this->post('/sales/issue/' . $draft));
        } finally {
            config(OSPOS::class)->settings['sales_invoice_format'] = $before;
        }

        $this->assertTrue($answer['success']);
        $this->assertSame($format, Database::connect()->table('sales')->where('sale_id', $draft)->get()->getRow()->invoice_number);
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
    }

    public function testPostIssue_RefusesAFormatNumberThatAnotherInvoiceAlreadyHasAndLeavesTheDraft(): void
    {
        $this->supervisorForDocuments();

        $cycle  = $this->referenceInvoice();
        $taken  = $this->save($cycle['items'], $cycle['taxes']);
        $draft  = $this->draftInvoice($cycle['items']);
        $format = 'DUP' . random_int(100000, 999999);

        Database::connect()->table('sales')->where('sale_id', $taken)->update(['invoice_number' => $format]);

        $before = $this->config()['sales_invoice_format'];

        config(OSPOS::class)->settings['sales_invoice_format'] = $format;

        try {
            $answer = $this->jsonOf($this->post('/sales/issue/' . $draft));
        } finally {
            config(OSPOS::class)->settings['sales_invoice_format'] = $before;
        }

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Sales.invoice_number_duplicate', [$format]), $answer['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
    }

    public function testPostIssue_TakesTheDayAndTheTermsTypedOnTheForm(): void
    {
        $this->supervisorForDocuments(['sales_invoice_date']);

        $draft = $this->draftInvoice($this->taxedLines());
        $day   = $this->daysAgo(3);

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft, ['invoice_date' => $day, 'payment_terms' => '30']));

        $this->assertTrue($answer['success']);

        $sale = Database::connect()->table('sales')->where('sale_id', $draft)->get()->getRow();

        $this->assertSame($day, $sale->invoice_date);
        $this->assertSame(30, (int) $sale->payment_terms_days);
        $this->assertSame(date('Y-m-d', strtotime($day . ' +30 days')), $sale->due_date);
        $this->assertSame([$day], array_values(array_unique(array_column($this->standing($draft), 'date'))));
    }

    public function testPostIssue_RefusesADayTypedByAnEmployeeWhoMayNotTypeItAndSaysWhy(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft, ['invoice_date' => $this->daysAgo(3)]));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $answer['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
        $this->assertSame([], $this->journals($draft));
    }

    public function testPostIssue_RefusesADayInAClosedPeriodAndSaysWhyAndKeepsTheDraft(): void
    {
        $this->supervisorForDocuments(['sales_invoice_date']);
        $this->closeThrough($this->daysAgo(5));

        $draft   = $this->draftInvoice($this->taxedLines());
        $company = (string) $this->accountingDb()->table('companies')->select('name')->where('company_id', 1)->get()->getRow()->name;

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft, ['invoice_date' => $this->daysAgo(8)]));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.posting_period_closed', [$this->daysAgo(8), $this->daysAgo(5), $company]), $answer['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());
        $this->assertNull(Database::connect()->table('sales')->where('sale_id', $draft)->get()->getRow()->invoice_number);
    }

    public function testPostIssue_RefusesADayThatIsNotUpToTodayAndTermsThatAreNotDays(): void
    {
        $this->supervisorForDocuments(['sales_invoice_date']);

        $draft = $this->draftInvoice($this->taxedLines());

        $future = $this->jsonOf($this->post('/sales/issue/' . $draft, ['invoice_date' => date('Y-m-d', strtotime('+2 days'))]));
        $terms  = $this->jsonOf($this->post('/sales/issue/' . $draft, ['payment_terms' => 'a month']));

        $this->assertFalse($future['success']);
        $this->assertSame(lang('Accounting.error_invoice_date_invalid'), $future['message']);
        $this->assertFalse($terms['success']);
        $this->assertSame(lang('Accounting.error_payment_terms_invalid', [InvoiceTiming::MAX_TERMS_DAYS]), $terms['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
    }

    public function testPostIssue_ReadsAListSentInPlaceOfTheDayAsADayThatIsNotOne(): void
    {
        $this->supervisorForDocuments(['sales_invoice_date']);

        $draft  = $this->draftInvoice($this->taxedLines());
        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft, ['invoice_date' => ['2026-10-01'], 'payment_terms' => ['5']]));

        $this->assertFalse($answer['success']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
    }

    public function testPostIssue_RefusesAnEmployeeWithoutTheReportsSalesGrantAndDoesNotIssue(): void
    {
        $this->startSelling();

        $draft = $this->draftInvoice($this->taxedLines());

        $this->loginAs($this->createCashierEmployee());

        $response = $this->post('/sales/issue/' . $draft);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.not_authorized'), $this->jsonOf($response)['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());
    }

    public function testPostIssue_RefusesADraftOfABranchTheEmployeeDoesNotWorkInAndDoesNotIssue(): void
    {
        $this->startSelling();

        $second = $this->createSecondBranch();
        $draft  = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->post('/sales/issue/' . $draft);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.branch_not_allowed'), $this->jsonOf($response)['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());
    }

    public function testPostIssue_IsNotReachedByALinkAndChangesNothingWhenOpenedWithGet(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        try {
            $this->get('/sales/issue/' . $draft);
        } catch (PageNotFoundException) {
            // The route answers a POST only
        }

        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());
        $this->assertNull(Database::connect()->table('sales')->where('sale_id', $draft)->get()->getRow()->invoice_number);
    }

    public function testPostIssue_IsTheOnlyWayToIssueAndNoActionThatChangesAStateAnswersAGet(): void
    {
        foreach (['Issue', 'QuoteStatus', 'ConvertQuote'] as $action) {
            $this->assertTrue(method_exists(Sales::class, 'post' . $action), "post{$action}");
            $this->assertFalse(method_exists(Sales::class, 'get' . $action), "get{$action} would change a state on a link");
            $this->assertFalse(method_exists(Sales::class, strtolower($action)), strtolower($action));
        }

        foreach (['Documents', 'Document'] as $page) {
            $this->assertTrue(method_exists(Sales::class, 'get' . $page), "get{$page} only reads");
        }
    }

    public function testFilters_KeepTheTokenOnEveryPostRouteOfTheSales(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Config/Filters.php');

        $this->assertMatchesRegularExpression("/'csrf' => \\['except' => 'login\\|migrate'\\]/", $source, 'no sales route is exempt from the token');
        $this->assertStringNotContainsString('sales', substr($source, (int) strpos($source, "'csrf' =>"), 80));
    }

    public function testGetDocument_PutsTheTokenInEveryFormThatChangesAState(): void
    {
        $this->supervisorForDocuments();

        $items = $this->taxedLines();
        $draft = $this->draftInvoice($items);
        $quote = $this->quote(null, $items);
        $token = 'name="' . config('Security')->tokenName . '"';

        $draftPage = $this->get('/sales/document/' . $draft);
        $quotePage = $this->get('/sales/document/' . $quote);

        foreach (['issue_form', 'register_form'] as $form) {
            $this->assertSame(1, substr_count($draftPage->getBody(), 'id="' . $form . '"'), $form);
            $this->assertStringContainsString($token, $this->formOf($draftPage->getBody(), $form), "the {$form} of a draft carries the token");
        }

        foreach (['quote_status_form', 'convert_form', 'register_form'] as $form) {
            $this->assertStringContainsString($token, $this->formOf($quotePage->getBody(), $form), "the {$form} of a quote carries the token");
        }

        $this->assertStringNotContainsString('href="' . site_url('sales/convertQuote/' . $quote) . '"', $quotePage->getBody());
        $this->assertStringNotContainsString('href="' . site_url('sales/quoteStatus/' . $quote) . '"', $quotePage->getBody());
    }

    public function testPostIssue_IssuesADraftOnlyOnceAndTheSecondRequestIsToldItIsGone(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        $this->assertTrue($this->jsonOf($this->post('/sales/issue/' . $draft))['success']);

        $journals = $this->countJournals();
        $second   = $this->jsonOf($this->post('/sales/issue/' . $draft));

        $this->assertFalse($second['success']);
        $this->assertSame(lang('Accounting.error_draft_not_found'), $second['message']);
        $this->assertSame($journals, $this->countJournals());
    }

    public function testPostIssue_RefusesWhatIsNotADraftInvoice(): void
    {
        $this->supervisorForDocuments();

        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);
        $quote   = $this->quote(null, $cycle['items']);

        foreach ([$invoice, $quote, 999999] as $saleId) {
            $answer = $this->jsonOf($this->post('/sales/issue/' . $saleId));

            $this->assertFalse($answer['success'], (string) $saleId);
            $this->assertSame(lang('Accounting.error_draft_not_found'), $answer['message'], (string) $saleId);
        }
    }

    public function testPostIssue_WorksWhileAccountingIsOffAndPostsNothing(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        $this->setConfigValue('accounting_enabled', '0');

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft));

        $this->assertTrue($answer['success']);
        $this->assertSame(COMPLETED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());
    }

    public function testPostIssue_RejectsAnEmployeeWithoutTheInvoiceDiscountGrantWhenTheDraftCarriesOne(): void
    {
        $this->supervisorForDocuments();

        $draft   = $this->save($this->taxedLines(), [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, ['type' => PERCENT, 'value' => '40']);
        $cashier = $this->employeeWithGrants(['sales', 'sales_stock', 'reports_sales']);

        $this->grantStockLocationIn(1, $cashier);
        $this->loginAs($cashier);

        $response = $this->post('/sales/issue/' . $draft);
        $answer   = $this->jsonOf($response);

        $response->assertStatus(403);
        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Sales.not_authorized'), $answer['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $draft, 'action' => 'invoice_discount_given'])->countAllResults());
    }

    public function testPostIssue_IssuesADraftWithADiscountForAnEmployeeWhoMayGiveItAndNamesHimAsTheOneWhoGaveIt(): void
    {
        $supervisor = $this->supervisorForDocuments(['sales_invoice_discount']);

        $draft  = $this->save($this->taxedLines(), [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED, NEW_ENTRY, ['type' => PERCENT, 'value' => '40']);
        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft));

        $this->assertTrue($answer['success']);
        $this->assertSame(COMPLETED, $this->saleStatusOf($draft));

        $given = $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $draft, 'action' => 'invoice_discount_given'])->get()->getResult();

        $this->assertCount(1, $given);
        $this->assertSame($supervisor, (int) $given[0]->employee_id);
    }

    public function testPostIssue_IssuesADraftWithNoDiscountForAnEmployeeWithoutTheInvoiceDiscountGrant(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        $this->assertTrue($this->jsonOf($this->post('/sales/issue/' . $draft))['success']);
        $this->assertSame(COMPLETED, $this->saleStatusOf($draft));
    }

    public function testPostIssue_SaysWhyTheBooksRefusedAndKeepsTheDraft(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->draftInvoice($this->taxedLines());

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['routing_type' => 'main_per_entity']);

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.posting_routing_type_unsupported'), $answer['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());
    }

    public function testPostIssue_TakesTheDraftOfARegisterSavedInvoiceModeSuspendAsItIs(): void
    {
        $supervisor = $this->supervisorForDocuments();
        $item       = $this->item('0');

        $this->seedCartLine(1, '100.00', $item);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_invoice', 'sales_customer' => $this->customerId, 'sales_payments' => [], 'sale_type' => SALE_TYPE_INVOICE]));

        $this->post('/sales/suspend');

        $draft = $this->lastSale();

        $this->assertSame(SUSPENDED, (int) $draft->sale_status);
        $this->assertNull($draft->invoice_number);

        $this->loginAs($supervisor);

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft->sale_id));

        $this->assertTrue($answer['success']);
        $this->assertSame(COMPLETED, $this->saleStatusOf((int) $draft->sale_id));
        $this->assertSame(['revenue'], array_column($this->standing((int) $draft->sale_id), 'entry'));
    }

    public function testPostQuoteStatus_MovesAQuoteAndSaysSo(): void
    {
        $this->supervisorForDocuments();

        $quote = $this->quote(null, $this->taxedLines());

        $answer = $this->jsonOf($this->post('/sales/quoteStatus/' . $quote, ['quote_status' => (string) QuoteStatus::Approved->value]));

        $this->assertTrue($answer['success']);
        $this->assertSame(lang('Sales.quote_status_changed'), $answer['message']);
        $this->assertSame((string) QuoteStatus::Approved->value, $this->statusOfTheQuote($quote));
        $this->assertSame(SUSPENDED, $this->saleStatusOf($quote), 'the status of the sale is not the one of the quote');
        $this->assertSame(0, $this->countJournals());
    }

    public function testPostQuoteStatus_WritesTheChangeInTheAuditLogWithWhoMadeItAndTheTwoStates(): void
    {
        $supervisor = $this->supervisorForDocuments();

        $quote = $this->quote(null, $this->taxedLines());

        $this->post('/sales/quoteStatus/' . $quote, ['quote_status' => (string) QuoteStatus::Sent->value]);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_id' => $quote, 'action' => 'quote_status_changed'])->get()->getResult();

        $this->assertCount(1, $entries);
        $this->assertSame($supervisor, (int) $entries[0]->employee_id);
        $this->assertSame(['from' => 'Open', 'to' => 'Sent'], json_decode((string) $entries[0]->details, true));
    }

    /**
     * @return array<string, array{0: QuoteStatus, 1: QuoteStatus}>
     */
    public static function refusedMoveProvider(): array
    {
        return [
            'a draft is not approved'         => [QuoteStatus::Draft, QuoteStatus::Approved],
            'an open quote is not ordered'    => [QuoteStatus::Open, QuoteStatus::Ordered],
            'an open quote is not a draft'    => [QuoteStatus::Open, QuoteStatus::Draft],
            'nothing is converted by hand'    => [QuoteStatus::Approved, QuoteStatus::Converted],
            'an approved quote is not sent'   => [QuoteStatus::Approved, QuoteStatus::Sent],
            'a converted quote is final'      => [QuoteStatus::Converted, QuoteStatus::Open],
            'an ordered quote is not open'    => [QuoteStatus::Ordered, QuoteStatus::Open],
        ];
    }

    #[DataProvider('refusedMoveProvider')]
    public function testPostQuoteStatus_RefusesAMoveTheStatusDoesNotAllowAndKeepsTheStatus(QuoteStatus $from, QuoteStatus $to): void
    {
        $this->supervisorForDocuments();

        $quote = $this->quote(null, $this->taxedLines());

        Database::connect()->table('sales')->where('sale_id', $quote)->update(['quote_status' => $from->value]);

        $answer = $this->jsonOf($this->post('/sales/quoteStatus/' . $quote, ['quote_status' => (string) $to->value]));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_quote_status_not_allowed', [lang($from->languageKey()), lang($to->languageKey())]), $answer['message']);
        $this->assertSame((string) $from->value, $this->statusOfTheQuote($quote));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function notAStatusProvider(): array
    {
        return ['text' => ['abc'], 'a number that is none' => ['77'], 'a list' => [['4']], 'empty' => ['']];
    }

    #[DataProvider('notAStatusProvider')]
    public function testPostQuoteStatus_RefusesWhatIsNotAStatusOfAQuote(mixed $value): void
    {
        $this->supervisorForDocuments();

        $quote  = $this->quote(null, $this->taxedLines());
        $answer = $this->jsonOf($this->post('/sales/quoteStatus/' . $quote, ['quote_status' => $value]));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Sales.quote_status_invalid'), $answer['message']);
        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($quote));
    }

    public function testPostQuoteStatus_RefusesAnEmployeeWithoutTheReportsSalesGrant(): void
    {
        $this->startSelling();

        $quote = $this->quote(null, $this->taxedLines());

        $this->loginAs($this->createCashierEmployee());

        $response = $this->post('/sales/quoteStatus/' . $quote, ['quote_status' => (string) QuoteStatus::Approved->value]);

        $response->assertStatus(403);
        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($quote));
    }

    public function testPostQuoteStatus_RefusesAQuoteOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->startSelling();

        $second = $this->createSecondBranch();
        $quote  = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->post('/sales/quoteStatus/' . $quote, ['quote_status' => (string) QuoteStatus::Approved->value]);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.branch_not_allowed'), $this->jsonOf($response)['message']);
        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($quote));
    }

    public function testPostQuoteStatus_RefusesWhatIsNotAQuote(): void
    {
        $this->supervisorForDocuments();

        $draft  = $this->draftInvoice($this->taxedLines());
        $answer = $this->jsonOf($this->post('/sales/quoteStatus/' . $draft, ['quote_status' => (string) QuoteStatus::Approved->value]));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_quote_not_found'), $answer['message']);
    }

    public function testPostQuoteStatus_OpensADraftQuoteWithTheNextNumberOfTheQuoteSequence(): void
    {
        $this->supervisorForDocuments();
        $this->switchOn(DocumentType::SalesQuote, $prefix = $this->freshPrefix());

        $draft = $this->quote(QuoteStatus::Draft, $this->taxedLines());

        $this->get('/sales/document/' . $draft)->assertSee('draft-' . $draft);

        $answer = $this->jsonOf($this->post('/sales/quoteStatus/' . $draft, ['quote_status' => (string) QuoteStatus::Open->value]));

        $this->assertTrue($answer['success']);
        $this->assertSame($prefix . '001', Database::connect()->table('sales')->where('sale_id', $draft)->get()->getRow()->quote_number);
        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($draft));
    }

    public function testPostQuoteStatus_IsNotReachedByAGetRequest(): void
    {
        $this->supervisorForDocuments();

        $quote = $this->quote(null, $this->taxedLines());

        try {
            $this->get('/sales/quoteStatus/' . $quote . '?quote_status=' . QuoteStatus::Approved->value);
        } catch (PageNotFoundException) {
            // The route answers a POST only
        }

        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($quote));
    }

    public function testPostConvertQuote_FillsTheRegisterWithAnInvoiceMadeFromTheQuoteAndPostsNothing(): void
    {
        $this->supervisorForDocuments();

        $items = [$this->line($this->item('0', false), 0, '2', '100')];
        $quote = $this->quote(null, $items);

        Database::connect()->table('sales')->where('sale_id', $quote)->update(['comment' => 'a quote for Omar']);

        $sales   = $this->countSales();
        $answer  = $this->jsonOf($this->post('/sales/convertQuote/' . $quote));
        $session = Services::session();

        $this->assertTrue($answer['success']);
        $this->assertSame(site_url('sales'), $answer['redirect']);
        $this->assertSame(lang('Sales.quote_convert_prepared'), $answer['message']);
        $this->assertSame('sale_invoice', $session->get('sales_mode'));
        $this->assertSame(SALE_TYPE_INVOICE, (int) $session->get('sale_type'));
        $this->assertSame(NEW_ENTRY, $session->get('sale_id'));
        $this->assertSame($quote, (int) $session->get('sales_quote_source'));
        $this->assertSame((string) $this->customerId, (string) $session->get('sales_customer'));
        $this->assertSame('a quote for Omar', $session->get('sales_comment'));
        $this->assertSame(['2'], array_values(array_map(static fn (array $line): string => rtrim(rtrim((string) $line['quantity'], '0'), '.'), $session->get('sales_cart'))));
        $this->assertSame($sales, $this->countSales(), 'nothing is saved by converting');
        $this->assertSame(0, $this->countJournals(), 'nothing is posted by converting');
        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($quote), 'the quote is converted when the invoice is saved');
    }

    public function testPostConvertQuote_ThenSavingTheInvoiceConvertsTheQuoteAndPostsTheInvoice(): void
    {
        $this->supervisorForDocuments();

        $items = [$this->line($this->item('0', false), 0, '2', '100')];
        $quote = $this->quote(null, $items);

        $this->post('/sales/convertQuote/' . $quote);
        $this->carryTheRegisterToTheNextRequest();

        $this->assertSame(0, $this->countJournals());

        $response = $this->post('/sales/complete');

        $response->assertStatus(200);

        $invoice = $this->lastSale();

        $this->assertNotSame($quote, (int) $invoice->sale_id);
        $this->assertSame(COMPLETED, (int) $invoice->sale_status);
        $this->assertSame(SALE_TYPE_INVOICE, (int) $invoice->sale_type);
        $this->assertSame((string) $quote, (string) $invoice->source_quote_id);
        $this->assertSame((string) QuoteStatus::Converted->value, $this->statusOfTheQuote($quote));
        $this->assertSame(SUSPENDED, $this->saleStatusOf($quote), 'the quote stays as the record');
        $this->assertSame(['revenue'], array_column($this->standing((int) $invoice->sale_id), 'entry'));
        $this->assertSame([], $this->journals($quote));
        $this->assertNull(Services::session()->get('sales_quote_source'), 'the register forgets the quote after the sale');
    }

    public function testPostConvertQuote_ThenSavingTheInvoiceAsADraftKeepsTheQuoteUntilTheDraftIsIssued(): void
    {
        $this->supervisorForDocuments();

        $items = [$this->line($this->item('0', false), 0, '2', '100')];
        $quote = $this->quote(null, $items);

        $this->post('/sales/convertQuote/' . $quote);
        $this->carryTheRegisterToTheNextRequest();
        $this->post('/sales/suspend');

        $draft = $this->lastSale();

        $this->assertSame(SUSPENDED, (int) $draft->sale_status);
        $this->assertSame(SALE_TYPE_INVOICE, (int) $draft->sale_type);
        $this->assertSame((string) $quote, (string) $draft->source_quote_id);
        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($quote));

        $answer = $this->jsonOf($this->post('/sales/issue/' . $draft->sale_id));

        $this->assertTrue($answer['success']);
        $this->assertSame((string) QuoteStatus::Converted->value, $this->statusOfTheQuote($quote));
    }

    public function testPostConvertQuote_RefusesAQuoteThatWasConvertedAlreadySoNoSecondInvoiceIsMade(): void
    {
        $this->supervisorForDocuments();

        $items = [$this->line($this->item('0', false), 0, '2', '100')];
        $quote = $this->quote(null, $items);

        $this->post('/sales/convertQuote/' . $quote);
        $this->carryTheRegisterToTheNextRequest();
        $this->post('/sales/complete');
        $this->lastSale();

        $sales  = $this->countSales();
        $answer = $this->jsonOf($this->post('/sales/convertQuote/' . $quote));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_quote_locked'), $answer['message']);
        $this->assertSame($sales, $this->countSales());
    }

    public function testPostComplete_RefusesTheSecondInvoiceOfAQuoteTwoRegistersConvertedAtTheSameTime(): void
    {
        $supervisor = $this->supervisorForDocuments();

        $items = [$this->line($this->item('0', false), 0, '2', '100')];
        $quote = $this->quote(null, $items);

        $this->post('/sales/convertQuote/' . $quote);
        $this->carryTheRegisterToTheNextRequest();

        $register = Services::session()->get();

        $this->post('/sales/complete');
        $this->lastSale();

        $this->loginAs($supervisor);
        $this->withSession(array_merge($this->session, array_intersect_key($register, array_flip(['sales_cart', 'sales_mode', 'sale_id', 'sales_customer', 'sales_payments', 'sale_type', 'sales_quote_source']))));

        $sales    = $this->countSales();
        $journals = $this->countJournals();
        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Accounting.error_quote_not_convertible', [lang('Sales.quote_status_converted')]));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($journals, $this->countJournals());
    }

    public function testPostConvertQuote_RefusesADraftQuoteAndAnUnknownSale(): void
    {
        $this->supervisorForDocuments();

        $draft = $this->quote(QuoteStatus::Draft, $this->taxedLines());

        $answer = $this->jsonOf($this->post('/sales/convertQuote/' . $draft));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_quote_not_convertible', [lang('Sales.quote_status_draft')]), $answer['message']);

        $answer = $this->jsonOf($this->post('/sales/convertQuote/999999'));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_quote_not_found'), $answer['message']);
        $this->assertNull(Services::session()->get('sales_quote_source'));
    }

    public function testPostConvertQuote_RefusesWhileInvoicesAreSwitchedOff(): void
    {
        $this->supervisorForDocuments();

        $quote  = $this->quote(null, $this->taxedLines());
        $before = $this->config()['invoice_enable'];

        config(OSPOS::class)->settings['invoice_enable'] = 0;

        try {
            $answer = $this->jsonOf($this->post('/sales/convertQuote/' . $quote));
        } finally {
            config(OSPOS::class)->settings['invoice_enable'] = $before;
        }

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Sales.quote_convert_unavailable'), $answer['message']);
    }

    public function testPostConvertQuote_RefusesAnEmployeeWithoutTheReportsSalesGrantAndAQuoteOfAnotherBranch(): void
    {
        $this->startSelling();

        $second = $this->createSecondBranch();
        $far    = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);
        $near   = $this->quote(null, $this->taxedLines());

        $this->loginAs($this->createCashierEmployee());

        $response = $this->post('/sales/convertQuote/' . $near);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.not_authorized'), $this->jsonOf($response)['message']);

        $this->loginAs($this->createBranchLimitedSupervisor(1));

        $response = $this->post('/sales/convertQuote/' . $far);

        $response->assertStatus(403);
        $this->assertSame(lang('Sales.branch_not_allowed'), $this->jsonOf($response)['message']);
        $this->assertNull(Services::session()->get('sales_quote_source'));
    }

    public function testPostConvertQuote_IsNotReachedByAGetRequest(): void
    {
        $this->supervisorForDocuments();

        $quote = $this->quote(null, $this->taxedLines());

        try {
            $this->get('/sales/convertQuote/' . $quote);
        } catch (PageNotFoundException) {
            // The route answers a POST only
        }

        $this->assertNull(Services::session()->get('sales_quote_source'));
    }

    public function testPostChangeMode_ForgetsTheQuoteTheInvoiceWasMadeFromWhenTheModeChanges(): void
    {
        $this->supervisorForDocuments();

        $quote = $this->quote(null, [$this->line($this->item('0', false), 0, '2', '100')]);

        $this->post('/sales/convertQuote/' . $quote);
        $this->carryTheRegisterToTheNextRequest();
        $this->post('/sales/changeMode', ['mode' => 'sale']);

        $this->assertNull(Services::session()->get('sales_quote_source'));
    }

    public function testPostChangeMode_KeepsTheQuoteWhenTheModeStaysTheInvoice(): void
    {
        $this->supervisorForDocuments();

        $quote = $this->quote(null, [$this->line($this->item('0', false), 0, '2', '100')]);

        $this->post('/sales/convertQuote/' . $quote);
        $this->carryTheRegisterToTheNextRequest();
        $this->post('/sales/changeMode', ['mode' => 'sale_invoice']);

        $this->assertSame($quote, (int) Services::session()->get('sales_quote_source'));
    }

    public function testPostSuspend_SavesAQuoteAsADraftThatTakesNoNumberOfTheSequence(): void
    {
        $this->supervisorForDocuments();
        $this->switchOn(DocumentType::SalesQuote, $this->freshPrefix());

        $this->seedCartLine(1, '100.00', $this->item('0', false));
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_quote', 'sales_customer' => $this->customerId, 'sales_payments' => [], 'sale_type' => SALE_TYPE_QUOTE]));

        $this->post('/sales/suspend');

        $quote = $this->lastSale();

        $this->assertSame(SUSPENDED, (int) $quote->sale_status);
        $this->assertSame(SALE_TYPE_QUOTE, (int) $quote->sale_type);
        $this->assertSame((string) QuoteStatus::Draft->value, (string) $quote->quote_status);
        $this->assertNull($quote->quote_number);
        $this->assertSame(0, Database::connect()->table('document_numbers')->countAllResults());
    }

    public function testPostComplete_SavesAQuoteOpenAndNumbersIt(): void
    {
        $this->startSelling();
        $this->switchOn(DocumentType::SalesQuote, $prefix = $this->freshPrefix());

        $this->completeAs('sale_quote');

        $quote = $this->lastSale();

        $this->assertSame((string) QuoteStatus::Open->value, (string) $quote->quote_status);
        $this->assertSame($prefix . '001', $quote->quote_number);
    }

    public function testPostSuspend_NeverTakesAnOpenQuoteBackToADraft(): void
    {
        $supervisor = $this->supervisorForDocuments();

        $quote = $this->quote(null, [$this->line($this->item('0', false), 0, '2', '100')]);

        $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $quote]);
        $this->carryTheRegisterToTheNextRequest();
        $this->post('/sales/suspend');

        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($quote));
        $this->assertSame($supervisor, (int) $this->session['person_id']);
    }

    public function testPostSuspend_LeavesASuspendedSaleOfThePointOfSaleWithNoQuoteState(): void
    {
        $this->supervisorForDocuments();

        $this->seedCartLine(1, '100.00', $this->item('0', false));
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale', 'sales_payments' => [], 'sale_type' => SALE_TYPE_POS]));

        $this->post('/sales/suspend');

        $sale = $this->lastSale();

        $this->assertSame(SUSPENDED, (int) $sale->sale_status);
        $this->assertNull($sale->quote_status);
        $this->assertNull($sale->source_quote_id);
        $this->assertNull($sale->invoice_number);
    }

    public function testPostUnsuspend_RefusesAConvertedQuoteAndKeepsTheCartTheCashierHolds(): void
    {
        $this->supervisorForDocuments();

        $item      = $this->item('0');
        $converted = $this->quote(null, [$this->line($item, 0, '2', '100')]);

        Database::connect()->table('sales')->where('sale_id', $converted)->update(['quote_status' => QuoteStatus::Converted->value]);

        $this->seedCartLine(7, '5.00', $item);
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $response = $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $converted]);

        $response->assertSee(lang('Accounting.error_quote_locked'));
        $this->assertArrayHasKey(7, Services::session()->get('sales_cart'));
        $this->assertSame(NEW_ENTRY, Services::session()->get('sale_id'));
    }

    public function testPostUnsuspend_RefusesASaleOfABranchTheEmployeeDoesNotWorkInAndKeepsTheCart(): void
    {
        $this->supervisorForDocuments();

        $second = $this->createSecondBranch();
        $far    = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $this->seedCartLine(7, '5.00', $this->item('0', false));
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY]));

        $response = $this->post('/sales/unsuspend', ['suspended_sale_id' => (string) $far]);

        $response->assertSee(lang('Sales.branch_not_allowed'));
        $this->assertArrayHasKey(7, Services::session()->get('sales_cart'));
        $this->assertSame(NEW_ENTRY, Services::session()->get('sale_id'));
    }

    public function testGetSuspended_ListsOnlyTheSuspendedSalesOfTheBranchesOfTheEmployee(): void
    {
        $this->supervisorForDocuments();

        $second = $this->createSecondBranch();
        $near   = $this->draftInvoice($this->taxedLines());
        $far    = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_INVOICE, -1, true, null, SUSPENDED);

        $page = $this->get('/sales/suspended');

        $page->assertSee('name="suspended_sale_id" value="' . $near . '"');
        $page->assertDontSee('name="suspended_sale_id" value="' . $far . '"');
    }

    public function testPostDelete_CancelsADraftWithNothingToReverseAndRefusesAConvertedQuote(): void
    {
        $this->supervisorForDocuments();

        $items     = $this->taxedLines();
        $draft     = $this->draftInvoice($items);
        $converted = $this->quote(null, $items);

        Database::connect()->table('sales')->where('sale_id', $converted)->update(['quote_status' => QuoteStatus::Converted->value]);

        $this->assertTrue($this->jsonOf($this->post('/sales/delete/' . $draft))['success']);
        $this->assertSame(CANCELED, $this->saleStatusOf($draft));
        $this->assertSame(0, $this->countJournals());

        $answer = $this->jsonOf($this->post('/sales/delete/' . $converted));

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_quote_locked'), $answer['message']);
        $this->assertSame(SUSPENDED, $this->saleStatusOf($converted));
    }

    public function testPostComplete_SaysWhyAQuoteOfAnotherBranchCannotBeConvertedAndSavesNothing(): void
    {
        $this->supervisorForDocuments();

        $second = $this->createSecondBranch();
        $far    = $this->save([$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))], [], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);

        $this->seedCartLine(1, '100.00', $this->item('0', false));
        $this->withSession(array_merge($this->session, ['sale_id' => NEW_ENTRY, 'sales_mode' => 'sale_invoice', 'sales_customer' => $this->customerId, 'sales_payments' => [], 'sale_type' => SALE_TYPE_INVOICE, 'sales_quote_source' => $far]));

        $sales    = $this->countSales();
        $response = $this->post('/sales/complete');

        $response->assertSee(lang('Branches.error_not_allowed'));
        $this->assertSame($sales, $this->countSales());
        $this->assertSame(0, $this->countJournals());
        $this->assertSame((string) QuoteStatus::Open->value, $this->statusOfTheQuote($far));
    }

    public function testGetSuspended_ListsWhatTheCustomerOfTheRegisterHasWaitingInTheBranchesOfTheEmployeeAndNamesADraftQuoteByItsTemporaryNumber(): void
    {
        $personId = $this->supervisorForDocuments();
        $customer = $this->anotherCustomer('Mona', 'Zaki');
        $stranger = $this->anotherCustomer('Omar', 'Ali');
        $second   = $this->createSecondBranch();
        $items    = $this->taxedLines();
        $far      = [$this->line($this->item('0', false), 0, '1', '100', '0', '0', PERCENT, $this->branchLocation($second))];

        $near       = $this->save($items, [], [], SALE_TYPE_INVOICE, $customer, true, null, SUSPENDED);
        $draftQuote = $this->save($items, [], [], SALE_TYPE_QUOTE, $customer, true, null, SUSPENDED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, QuoteStatus::Draft);
        $elsewhere  = $this->save($far, [], [], SALE_TYPE_INVOICE, $customer, true, null, SUSPENDED);
        $notTheirs  = $this->save($items, [], [], SALE_TYPE_INVOICE, $stranger, true, null, SUSPENDED);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home', 'sales_customer' => $customer]);

        $page = $this->get('/sales/suspended');

        $page->assertSee('name="suspended_sale_id" value="' . $near . '"');
        $page->assertSee('name="suspended_sale_id" value="' . $draftQuote . '"');
        $page->assertSee('draft-' . $draftQuote);
        $page->assertDontSee('name="suspended_sale_id" value="' . $elsewhere . '"');
        $page->assertDontSee('name="suspended_sale_id" value="' . $notTheirs . '"');

        $rows = array_column($this->sales->get_all_suspended($customer), null, 'sale_id');

        $this->assertSame([$near, $draftQuote, $elsewhere], array_map('intval', array_keys($rows)));
        $this->assertSame('1', (string) $rows[$near]['branch_id']);
        $this->assertSame((string) $second, (string) $rows[$elsewhere]['branch_id']);
        $this->assertSame('draft-' . $draftQuote, $rows[$draftQuote]['doc_id']);
    }

    public function testGetSuspended_NamesADraftQuoteByItsTemporaryNumberAndLeavesTheOtherDocumentsAsTheyWere(): void
    {
        $this->supervisorForDocuments();

        $items  = $this->taxedLines();
        $draft  = $this->quote(QuoteStatus::Draft, $items);
        $open   = $this->quote(null, $items);
        $number = $this->draftInvoice($items);

        Database::connect()->table('sales')->where('sale_id', $open)->update(['quote_number' => 'Q26-0099']);

        $rows = array_column($this->sales->get_all_suspended(NEW_ENTRY), 'doc_id', 'sale_id');

        $this->assertSame('draft-' . $draft, $rows[$draft]);
        $this->assertSame('Q26-0099', $rows[$open]);
        $this->assertSame((string) $number, (string) $rows[$number], 'a suspended invoice shows its id in the list of the register as it always did');
        $this->get('/sales/suspended')->assertSee('draft-' . $draft);
    }
}
