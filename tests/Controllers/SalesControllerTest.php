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
}
