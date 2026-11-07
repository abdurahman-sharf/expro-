<?php

namespace Tests\Controllers;

use App\Libraries\SalesOrders\SalesOrders;
use App\Libraries\SalesOrders\SalesOrderSettings;
use App\Libraries\SalesOrders\SalesOrderStatus;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screens of the sales orders: what they show while the module is off, who may open and change
 * what, that every change is a POST the service may refuse in words, and that the conversion fills
 * the register and saves nothing.
 *
 * @internal
 */
final class Sales_ordersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private SalesOrders $orders;
    private int $clerk;
    private int $service;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->clearOrders();
        $this->setConfigValue('enable_sales_order', '1');
        $this->setConfigValue('sales_order_allow_excess', '0');
        $this->setConfigValue('invoice_enable', '1');

        $this->orders  = new SalesOrders($this->accountingDb());
        $this->clerk   = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::EDIT, SalesOrders::DELETE, SalesOrders::VIEW_ALL, SalesOrders::CONVERT]);
        $this->service = $this->item('0', false);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->clearOrders();
        $this->accountingDb()->table('accounting_audit_log')->whereIn('entity_type', ['sales_order', 'sales_order_settings'])->delete();
        $this->setConfigValue('enable_sales_order', '0');
        $this->setConfigValue('sales_order_allow_excess', '0');
        $this->stopSelling();

        parent::tearDown();
    }

    private function clearOrders(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach (['sales_order_invoice_lines', 'sales_order_invoices', 'sales_order_lines', 'sales_orders', 'document_numbers', 'document_sequences'] as $table) {
            $db->table($table)->truncate();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['sales_orders', ...$permissions], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function order(?int $by = null, int $location = self::LOCATION_ID, string $quantity = '10'): int
    {
        return $this->orders->create([
            'customer_id' => $this->customerId,
            'location_id' => $location,
            'order_date'  => '2026-10-20',
            'note'        => 'screen order',
            'lines'       => [['item_id' => $this->service, 'quantity' => $quantity, 'unit_price' => '50.00']],
        ], $by ?? $this->clerk);
    }

    private function approved(): int
    {
        $orderId = $this->order();
        $this->orders->changeStatus($orderId, SalesOrderStatus::Approved, $this->clerk);

        return $orderId;
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(string $quantity = '4'): array
    {
        return [
            'customer_id' => (string) $this->customerId,
            'location_id' => (string) self::LOCATION_ID,
            'order_date'  => '2026-10-21',
            'note'        => 'from the form',
            'lines'       => [['item_id' => (string) $this->service, 'quantity' => $quantity, 'unit_price' => '25.00', 'discount' => '0', 'discount_type' => '0', 'description' => '']],
        ];
    }

    public function testGetIndex_ListsTheOrdersAndOffersTheNewOneToWhoMayAddThem(): void
    {
        $orderId = $this->order();

        $this->actAsWith([SalesOrders::ADD, SalesOrders::VIEW_ALL]);

        $page = $this->get('/sales_orders');

        $page->assertStatus(200);
        $page->assertSee('id="sales_order_' . $orderId . '"');
        $page->assertSee(lang('Sales_orders.status_open'));
        $page->assertSee('id="sales_order_new"');
    }

    public function testGetIndex_OffersNoNewOrderToWhoMayOnlyLook(): void
    {
        $this->order();

        $this->actAsWith([SalesOrders::VIEW_ALL]);

        $page = $this->get('/sales_orders');

        $page->assertStatus(200);
        $page->assertDontSee('id="sales_order_new"');
        $page->assertDontSee('id="sales_order_settings"');
    }

    public function testGetIndex_ShowsTheEmptyStateAndNoOrderWhileTheModuleIsOff(): void
    {
        $this->order();
        $this->setConfigValue('enable_sales_order', '0');

        $this->actAsWith([SalesOrders::ADD, SalesOrders::VIEW_ALL]);

        $page = $this->get('/sales_orders');

        $page->assertStatus(200);
        $page->assertSee('id="sales_orders_off"');
        $page->assertDontSee('id="sales_orders_table"');
        $page->assertDontSee('id="sales_order_new"');
    }

    public function testGetForm_RedirectsToTheInvoiceRegisterWhileTheModuleIsOff(): void
    {
        $this->setConfigValue('enable_sales_order', '0');
        $this->actAsWith([SalesOrders::ADD]);

        $page = $this->get('/sales_orders/add');

        $page->assertRedirectTo(site_url('sales'));
    }

    public function testGetIndex_OffersTheSettingsToWhoHoldsTheConfigPermission(): void
    {
        $this->actAsWith([SalesOrderSettings::PERMISSION, SalesOrders::VIEW_ALL]);

        $page = $this->get('/sales_orders');

        $page->assertSee('id="sales_order_settings"');
    }

    public function testGetIndex_FiltersByTheStatusOfTheOrder(): void
    {
        $open     = $this->order();
        $approved = $this->approved();

        $this->actAsWith([SalesOrders::VIEW_ALL]);

        $page = $this->get('/sales_orders?status=approved');

        $page->assertSee('id="sales_order_' . $approved . '"');
        $page->assertDontSee('id="sales_order_' . $open . '"');
    }

    public function testGetIndex_ShowsOnlyTheOrdersOfTheBranchesOfTheEmployee(): void
    {
        $other       = $this->createBranch();
        $mine        = $this->order();
        $otherSeller = $this->employeeWithGrants([SalesOrders::ADD, SalesOrders::VIEW_ALL], [$other]);
        $otherStock  = $this->insertStockLocation('Other warehouse');
        $this->accountingDb()->table('stock_locations')->where('location_id', $otherStock)->update(['branch_id' => $other]);
        $theirs = $this->order($otherSeller, $otherStock);

        $this->actAsWith([SalesOrders::VIEW_ALL], [$other]);

        $page = $this->get('/sales_orders');

        $page->assertSee('id="sales_order_' . $theirs . '"');
        $page->assertDontSee('id="sales_order_' . $mine . '"');
    }

    public function testGetShow_IsNotFoundForAnOrderOutsideTheBranchesOfTheEmployee(): void
    {
        $other = $this->createBranch();
        $mine  = $this->order();

        $this->actAsWith([SalesOrders::VIEW_ALL], [$other]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/sales_orders/view/' . $mine);
    }

    public function testGetForm_OffersTheFormToWhoMayAddAndHidesItFromWhoMayNot(): void
    {
        $this->actAsWith([SalesOrders::ADD]);

        $this->get('/sales_orders/add')->assertSee('id="sales_order_form"');

        $this->actAsWith([SalesOrders::VIEW_ALL]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/sales_orders/add');
    }

    public function testGetForm_OpensAnOrderOfTheBranchForEditingOnlyWhileItIsNotInvoiced(): void
    {
        $orderId = $this->order();

        $this->actAsWith([SalesOrders::EDIT, SalesOrders::VIEW_ALL]);

        $this->get('/sales_orders/edit/' . $orderId)->assertSee('id="sales_order_form"');

        $this->orders->cancel($orderId, 'no longer wanted', $this->clerk);
        $this->expectException(PageNotFoundException::class);

        $this->get('/sales_orders/edit/' . $orderId);
    }

    public function testGetShow_ShowsTheLinesWithWhatIsInvoicedAndWhatIsLeft(): void
    {
        $orderId = $this->approved();

        $this->actAsWith([SalesOrders::VIEW_ALL, SalesOrders::EDIT, SalesOrders::CONVERT, SalesOrders::DELETE]);

        $page = $this->get('/sales_orders/view/' . $orderId);

        $page->assertStatus(200);
        $page->assertSee('id="order_header"');
        $page->assertSee(lang('Sales_orders.status_approved'));
        $page->assertSee('id="order_convert"');
        $page->assertSee('id="order_cancel"');
        $page->assertSee('10.000');
    }

    public function testGetShow_OffersOnlyWhatTheEmployeeMayDo(): void
    {
        $orderId = $this->approved();

        $this->actAsWith([SalesOrders::VIEW_ALL]);

        $page = $this->get('/sales_orders/view/' . $orderId);

        $page->assertDontSee('id="order_convert"');
        $page->assertDontSee('id="order_cancel"');
        $page->assertDontSee('id="order_edit"');
        $page->assertDontSee('data-status=');
    }

    public function testGetShow_IsNotFoundForAnOrderThatDoesNotExist(): void
    {
        $this->actAsWith([SalesOrders::VIEW_ALL]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/sales_orders/view/999999');
    }

    public function testGetShow_IsNotFoundWhileTheModuleIsOff(): void
    {
        $orderId = $this->order();
        $this->setConfigValue('enable_sales_order', '0');

        $this->actAsWith([SalesOrders::VIEW_ALL]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/sales_orders/view/' . $orderId);
    }

    public function testPostSave_SavesTheOrderThroughTheServiceAndSendsTheEmployeeToIt(): void
    {
        $this->actAsWith([SalesOrders::ADD, SalesOrders::VIEW_ALL]);

        $result = $this->postJson('/sales_orders/save', $this->formData());

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringEndsWith('/sales_orders/view/' . $result['id'], $result['redirect']);
        $this->assertSame(1, $this->countRows('sales_orders'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPostSave_RefusesInWordsWhatTheServiceRefuses(): void
    {
        $this->actAsWith([SalesOrders::ADD]);

        $data          = $this->formData();
        $data['lines'] = [];

        $result = $this->postJson('/sales_orders/save', $data);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_sales_order_no_lines'), $result['message']);
        $this->assertSame(0, $this->countRows('sales_orders'));
    }

    public function testPostSave_RequiresThePermissionToAddAndToEdit(): void
    {
        $orderId = $this->order();

        $this->actAsWith([SalesOrders::VIEW_ALL]);

        $added = $this->postJson('/sales_orders/save', $this->formData());
        $this->assertFalse($added['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $added['message']);

        $edited = $this->postJson('/sales_orders/save/' . $orderId, $this->formData());
        $this->assertFalse($edited['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $edited['message']);
    }

    public function testPostSave_ChangesAnOrderNothingWasInvoicedFrom(): void
    {
        $orderId = $this->order();

        $this->actAsWith([SalesOrders::EDIT, SalesOrders::VIEW_ALL]);

        $result = $this->postJson('/sales_orders/save/' . $orderId, $this->formData('7'));

        $this->assertTrue($result['success'], $result['message']);
        $this->assertSame('7.000', (string) $this->accountingDb()->table('sales_order_lines')->where('order_id', $orderId)->get()->getRow()->quantity);
    }

    public function testPostStatus_ApprovesTheOrderAndRefusesAStatusThatIsNotSetByHand(): void
    {
        $orderId = $this->order();

        $this->actAsWith([SalesOrders::EDIT, SalesOrders::VIEW_ALL]);

        $this->assertTrue($this->postJson('/sales_orders/status/' . $orderId, ['status' => 'approved'])['success']);
        $this->assertSame(SalesOrderStatus::Approved, $this->orders->statusOf($orderId));

        $refused = $this->postJson('/sales_orders/status/' . $orderId, ['status' => 'invoiced']);

        $this->assertFalse($refused['success']);
        $this->assertSame(SalesOrderStatus::Approved, $this->orders->statusOf($orderId));
    }

    public function testPostStatus_RequiresThePermissionToEdit(): void
    {
        $orderId = $this->order();

        $this->actAsWith([SalesOrders::VIEW_ALL]);

        $result = $this->postJson('/sales_orders/status/' . $orderId, ['status' => 'approved']);

        $this->assertFalse($result['success']);
        $this->assertSame(SalesOrderStatus::Open, $this->orders->statusOf($orderId));
    }

    public function testPostCancel_RequiresAReasonAndThePermissionToDelete(): void
    {
        $orderId = $this->order();

        $this->actAsWith([SalesOrders::DELETE, SalesOrders::VIEW_ALL]);

        $noReason = $this->postJson('/sales_orders/cancel/' . $orderId, ['reason' => '  ']);
        $this->assertFalse($noReason['success']);
        $this->assertSame(SalesOrderStatus::Open, $this->orders->statusOf($orderId));

        $this->assertTrue($this->postJson('/sales_orders/cancel/' . $orderId, ['reason' => 'customer changed his mind'])['success']);
        $this->assertSame(SalesOrderStatus::Cancelled, $this->orders->statusOf($orderId));

        $other = $this->order();
        $this->actAsWith([SalesOrders::VIEW_ALL]);

        $this->assertFalse($this->postJson('/sales_orders/cancel/' . $other, ['reason' => 'x'])['success']);
    }

    public function testPostConvert_FillsTheRegisterWithWhatIsLeftAndSavesNothing(): void
    {
        $orderId = $this->approved();

        $this->actAsWith([SalesOrders::CONVERT, SalesOrders::VIEW_ALL]);

        $result = $this->postJson('/sales_orders/convert/' . $orderId);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringEndsWith('/sales', $result['redirect']);
        $this->assertSame($orderId, session()->get('sales_order_source'));
        $this->assertSame(0, $this->countRows('sales'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->countRows('sales_order_invoice_lines'));
    }

    public function testPostConvert_RefusesAnOrderThatIsNotApprovedAndAnEmployeeWithoutThePermission(): void
    {
        $open     = $this->order();
        $approved = $this->approved();

        $this->actAsWith([SalesOrders::CONVERT, SalesOrders::VIEW_ALL]);

        $notApproved = $this->postJson('/sales_orders/convert/' . $open);
        $this->assertFalse($notApproved['success']);
        $this->assertSame(lang('Accounting.error_sales_order_not_convertible', [lang('Sales_orders.status_open')]), $notApproved['message']);

        $this->actAsWith([SalesOrders::VIEW_ALL]);
        $this->assertFalse($this->postJson('/sales_orders/convert/' . $approved)['success']);
        $this->assertNull(session()->get('sales_order_source'));
    }

    public function testPostConvert_RefusesWhenInvoicesAreNotEnabled(): void
    {
        $orderId = $this->approved();
        $this->setConfigValue('invoice_enable', '0');

        $this->actAsWith([SalesOrders::CONVERT, SalesOrders::VIEW_ALL]);

        $result = $this->postJson('/sales_orders/convert/' . $orderId);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Sales_orders.invoice_unavailable'), $result['message']);
    }

    public function testPostSettings_RequiresTheConfigPermissionAndSavesBothSwitches(): void
    {
        $this->setConfigValue('enable_sales_order', '0');

        $this->actAsWith([SalesOrders::VIEW_ALL]);
        $this->assertFalse($this->postJson('/sales_orders/settings', ['enabled' => '1', 'allow_excess' => '1'])['success']);
        $this->assertFalse($this->orders->isEnabled());

        $this->actAsWith([SalesOrderSettings::PERMISSION, SalesOrders::VIEW_ALL]);
        $this->assertTrue($this->postJson('/sales_orders/settings', ['enabled' => '1', 'allow_excess' => '1'])['success']);
        $this->assertTrue($this->orders->isEnabled());
        $this->assertTrue($this->orders->allowsExcess());
    }

    #[DataProvider('provideChange_IsNotReachableByAGetRequest')]
    public function testChange_IsNotReachableByAGetRequest(string $url): void
    {
        $this->actAsWith([SalesOrders::ADD, SalesOrders::EDIT, SalesOrders::DELETE, SalesOrders::CONVERT, SalesOrders::VIEW_ALL, SalesOrderSettings::PERMISSION]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/' . $url);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideChange_IsNotReachableByAGetRequest(): iterable
    {
        return [
            'save'     => ['sales_orders/save'],
            'save one' => ['sales_orders/save/1'],
            'status'   => ['sales_orders/status/1'],
            'cancel'   => ['sales_orders/cancel/1'],
            'convert'  => ['sales_orders/convert/1'],
            'settings' => ['sales_orders/settings'],
        ];
    }

    public function testGetCustomers_AnswersOnlyToWhoMayWriteAnOrder(): void
    {
        $this->actAsWith([SalesOrders::ADD]);

        $number    = (string) $this->accountingDb()->table('items')->where('item_id', $this->service)->get()->getRow()->item_number;
        $customers = json_decode($this->get('/sales_orders/customers?term=Omar')->getJSON(), true);
        $items     = json_decode($this->get('/sales_orders/items?term=' . rawurlencode($number))->getJSON(), true);

        $this->assertContains($this->customerId, array_column($customers, 'value'));
        $this->assertContains($this->service, array_column($items, 'value'));

        $this->actAsWith([SalesOrders::VIEW_ALL]);

        $this->get('/sales_orders/customers')->assertStatus(403);
        $this->get('/sales_orders/items')->assertStatus(403);
    }

    public function testGetIndex_IsNotOpenedByAnEmployeeWithoutTheModuleGrant(): void
    {
        $personId = $this->employeeWithGrants([SalesOrders::VIEW_ALL]);
        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        $page = $this->get('/sales_orders');

        $this->assertNotSame(200, $page->response()->getStatusCode());
    }
}
