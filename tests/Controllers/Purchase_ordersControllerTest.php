<?php

namespace Tests\Controllers;

use App\Libraries\PurchaseCycle\PurchaseDocumentKind;
use App\Libraries\PurchaseCycle\PurchaseDocuments;
use App\Libraries\PurchaseCycle\PurchaseDocumentStatus;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\PurchaseCycleFixtureTrait;
use Tests\Support\PurchaseCycleScreenTestTrait;
use Tests\Support\RoleFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The screens of the purchase orders: what they show while the cycle is off, who may open and change what, that every change is a POST
 * the service may refuse in words, and that the conversion fills in the receiving register with what is left of the order and saves
 * nothing until the receiving is completed there.
 *
 * @internal
 */
final class Purchase_ordersControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use PurchaseCycleFixtureTrait;
    use PurchaseCycleScreenTestTrait;
    use RoleFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function kind(): PurchaseDocumentKind
    {
        return PurchaseDocumentKind::Order;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpScreen();
    }

    protected function tearDown(): void
    {
        $this->tearDownScreen();

        parent::tearDown();
    }

    /**
     * @return array<string, string>
     */
    private function completeForm(): array
    {
        return ['comment' => '', 'recv_reference' => '', 'payment_type' => lang('Sales.due'), 'amount_tendered' => ''];
    }

    /**
     * @param list<string> $extra
     */
    private function receiver(array $extra = []): int
    {
        return $this->actAsWith([$this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::VIEW_ALL), 'receivings', 'items', 'accounting_journals', ...$extra]);
    }

    private function receivedUnits(int $orderId): array
    {
        return array_map(static fn (object $line): string => $line->received, $this->documentsOf(PurchaseDocumentKind::Order)->lines($orderId));
    }

    public function testPostConvert_FillsTheReceivingRegisterWithWhatIsLeftAndSavesNothing(): void
    {
        $orderId = $this->document();
        $before  = $this->bookCounts();

        $this->receiver();

        $result = $this->postJson('/purchase_orders/convert/' . $orderId);

        $this->assertTrue($result['success'], $result['message']);
        $this->assertStringEndsWith('/receivings', $result['redirect']);
        $this->assertSame($orderId, session()->get('recv_order_source'));
        $this->assertSame($this->supplierId, (int) session()->get('recv_supplier'));
        $this->assertSame('receive', session()->get('recv_mode'));

        $cart = array_values(session()->get('recv_cart') ?? []);

        $this->assertCount(1, $cart);
        $this->assertSame([$this->goods, '10', '4.00', self::LOCATION_ID], [(int) $cart[0]['item_id'], (string) (float) $cart[0]['quantity'], number_format((float) $cart[0]['price'], 2, '.', ''), (int) $cart[0]['item_location']]);
        $this->assertSame($before, $this->bookCounts(), 'nothing is posted and no stock moves until the receiving is saved');
        $this->assertSame(0, $this->countRows('purchase_order_receiving_lines'));
    }

    public function testPostConvert_FillsTheRegisterWithTheUnitsThatAreLeftAfterAnEarlierReceiving(): void
    {
        $orderId = $this->document();

        $this->receiver();
        $this->assertTrue($this->postJson('/purchase_orders/convert/' . $orderId)['success']);
        $this->withSession();
        $this->post('/receivings/complete', $this->completeForm())->assertStatus(200);

        $this->assertSame(PurchaseDocumentStatus::Received, $this->documentsOf(PurchaseDocumentKind::Order)->statusOf($orderId));

        $partial = $this->makeDocument(PurchaseDocumentKind::Order, ['lines' => [['item_id' => $this->goods, 'quantity' => '10', 'unit_price' => '4.00']]]);
        $this->receiveByModel($partial, '3');

        $this->receiver();
        $this->assertTrue($this->postJson('/purchase_orders/convert/' . $partial)['success']);

        $cart = array_values(session()->get('recv_cart') ?? []);

        $this->assertSame('7', (string) (float) $cart[0]['quantity'], 'what is left of the order and no more');
    }

    public function testPostConvert_FillsTheRegisterWithOnlyTheLinesThatHaveUnitsLeft(): void
    {
        $other   = $this->item('2.00');
        $orderId = $this->makeDocument(PurchaseDocumentKind::Order, ['lines' => [
            ['item_id' => $this->goods, 'quantity' => '2', 'unit_price' => '4.00'],
            ['item_id' => $other, 'quantity' => '5', 'unit_price' => '2.50'],
        ]]);

        $this->receiveByModel($orderId, '2');
        $this->receiver();

        $this->assertTrue($this->postJson('/purchase_orders/convert/' . $orderId)['success']);

        $cart = array_values(session()->get('recv_cart') ?? []);

        $this->assertCount(1, $cart, 'the line that was received in full is not asked for again');
        $this->assertSame([$other, '5', '2.50'], [(int) $cart[0]['item_id'], (string) (float) $cart[0]['quantity'], number_format((float) $cart[0]['price'], 2, '.', '')]);
    }

    private function receiveByModel(int $orderId, string $units): int
    {
        $receivingId = $this->purchases->save_value([$this->purchaseLine($this->goods, 0, $units, '4.00')], $this->supplierId, $this->clerk, 'test', 'REF-' . uniqid(), lang('Sales.due'), NEW_ENTRY, null, null, null, null, $orderId);

        $this->assertGreaterThan(0, $receivingId);
        $this->receivingIds[] = $receivingId;

        return $receivingId;
    }

    public function testPostConvert_ReceivesThroughTheRegisterPostsLikeAnyPurchaseAndTakesTheUnitsFromTheOrder(): void
    {
        $orderId = $this->document();
        $journals = $this->countRows('accounting_journals');

        $this->receiver();

        $this->assertTrue($this->postJson('/purchase_orders/convert/' . $orderId)['success']);
        $this->withSession();
        $this->post('/receivings/complete', $this->completeForm())->assertStatus(200);

        $link = $this->accountingDb()->table('purchase_order_receivings')->where('order_id', $orderId)->get()->getRow();

        $this->assertNotNull($link, 'the receiving saved from the register is the one the order was converted into');
        $this->receivingIds[] = (int) $link->receiving_id;
        $this->assertSame(['10.000'], $this->receivedUnits($orderId));
        $this->assertSame(PurchaseDocumentStatus::Received, $this->documentsOf(PurchaseDocumentKind::Order)->statusOf($orderId));
        $this->assertSame('10.000', $this->onHand($this->goods));
        $this->assertSame($journals + 1, $this->countRows('accounting_journals'), 'the receiving posts its journal as every purchase does');
        $this->assertNull(session()->get('recv_order_source'), 'the next receiving of the register is not taken for the order');
        $this->assertStockTies();
    }

    public function testPostConvert_ShowsTheOrderOnTheRegisterAndForgetsItWhenTheModeChanges(): void
    {
        $orderId = $this->document();

        $this->receiver();
        $this->assertTrue($this->postJson('/purchase_orders/convert/' . $orderId)['success']);
        $this->withSession();

        $this->get('/receivings')->assertSee('id="order_source_banner"');

        $this->withSession();
        $this->post('/receivings/changeMode', ['mode' => 'return'])->assertStatus(200);

        $this->assertNull(session()->get('recv_order_source'));
        $this->withSession();
        $this->get('/receivings')->assertDontSee('id="order_source_banner"');
    }

    public function testPostConvert_RefusesInWordsWhatTheReceivingCannotTake(): void
    {
        $orderId = $this->document();

        $this->receiver();
        $this->assertTrue($this->postJson('/purchase_orders/convert/' . $orderId)['success']);

        $this->receiveByModel($orderId, '9');
        $this->withSession();

        $page = $this->post('/receivings/complete', $this->completeForm());

        $page->assertSee(lang('Accounting.error_purchase_order_quantity_exceeded', ['1.000', (string) $this->accountingDb()->table('items')->where('item_id', $this->goods)->get()->getRow()->name]));
        $this->assertSame(1, $this->countRows('purchase_order_receivings'));
        $this->assertSame(['9.000'], $this->receivedUnits($orderId));
        $this->assertSame('9.000', $this->onHand($this->goods));
    }

    public function testPostConvert_RefusesAnOrderThatIsCancelledOrReceivedInFullAndSavesNothing(): void
    {
        $cancelled = $this->document();
        $full      = $this->document();

        $this->documentsOf(PurchaseDocumentKind::Order)->cancel($cancelled, 'no', $this->clerk);
        $this->receiveByModel($full, '10');
        $this->receiver();

        foreach ([$cancelled => PurchaseDocumentStatus::Cancelled, $full => PurchaseDocumentStatus::Received] as $orderId => $status) {
            $result = $this->postJson('/purchase_orders/convert/' . $orderId);

            $this->assertFalse($result['success']);
            $this->assertSame(lang('Accounting.error_purchase_order_not_receivable', [lang($status->languageKey())]), $result['message']);
        }

        $this->assertNull(session()->get('recv_order_source'));
    }

    public function testPostConvert_RequiresTheConvertPermissionAndTheUseOfTheReceivingRegister(): void
    {
        $orderId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), 'receivings']);

        $denied = $this->postJson('/purchase_orders/convert/' . $orderId);

        $this->assertFalse($denied['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $denied['message']);

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::CONVERT)]);

        $noRegister = $this->postJson('/purchase_orders/convert/' . $orderId);

        $this->assertFalse($noRegister['success']);
        $this->assertSame(lang('Purchase_cycle.receiving_not_allowed'), $noRegister['message']);
        $this->assertNull(session()->get('recv_order_source'));
    }

    public function testPostConvert_TreatsAnOrderOfAnotherBranchAsOneThatDoesNotExist(): void
    {
        $other   = $this->createBranch();
        $orderId = $this->document();

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::CONVERT), 'receivings'], [$other]);

        $result = $this->postJson('/purchase_orders/convert/' . $orderId);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_not_found'), $result['message']);
    }

    public function testPostConvert_RefusesWhileTheCycleIsOff(): void
    {
        $orderId = $this->document();

        $this->setConfigValue('enable_purchase_cycle', '0');
        $this->receiver();

        $result = $this->postJson('/purchase_orders/convert/' . $orderId);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_purchase_document_module_off'), $result['message']);
    }

    public function testGetShow_ShowsWhatWasOrderedReceivedAndLeftEachInItsOwnColumnWithTheReceivingsThatStand(): void
    {
        $orderId     = $this->document();
        $receivingId = $this->receiveByModel($orderId, '4');

        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $page = $this->get('/purchase_orders/view/' . $orderId);
        $cell = static fn (string $figure): string => '<td class="text-end"><bdi dir="ltr">' . $figure . '</bdi></td>\s*';

        $this->assertMatchesRegularExpression('~' . $cell('10\.000') . $cell('4\.000') . $cell('6\.000') . '~', $page->getBody(), 'ordered, received, left');
        $page->assertSee(lang('Purchase_cycle.status_partly_received'));
        $page->assertSee('id="document_receiving_' . $receivingId . '"');
        $this->assertStringContainsString('href="' . base_url('receivings/receipt/' . $receivingId) . '"', $page->getBody());
        $page->assertDontSee('id="document_edit"');
    }

    public function testGetShow_OffersTheReceivingWhileSomethingIsLeftAndNotWhenItIsAllReceived(): void
    {
        $partly = $this->document();
        $full   = $this->document();

        $this->receiveByModel($partly, '4');
        $this->receiveByModel($full, '10');
        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL), $this->permission(PurchaseDocuments::CONVERT), $this->permission(PurchaseDocuments::DELETE)]);

        $open = $this->get('/purchase_orders/view/' . $partly);

        $open->assertSee('id="document_convert"');
        $open->assertSee(lang('Purchase_cycle.convert_receive'));
        $open->assertSee('id="document_cancel"');

        $closed = $this->get('/purchase_orders/view/' . $full);

        $closed->assertDontSee('id="document_convert"');
        $closed->assertDontSee('id="document_cancel"');
    }

    public function testGetForm_IsNotFoundForAnOrderAReceivingWasMadeFrom(): void
    {
        $orderId = $this->document();

        $this->receiveByModel($orderId, '2');
        $this->actAsWith([$this->permission(PurchaseDocuments::EDIT), $this->permission(PurchaseDocuments::VIEW_ALL)]);
        $this->expectException(PageNotFoundException::class);

        $this->get('/purchase_orders/edit/' . $orderId);
    }

    public function testGetIndex_FiltersByThePartlyReceivedAndTheReceivedStatus(): void
    {
        $open    = $this->document();
        $partly  = $this->document();
        $full    = $this->document();

        $this->receiveByModel($partly, '1');
        $this->receiveByModel($full, '10');
        $this->actAsWith([$this->permission(PurchaseDocuments::VIEW_ALL)]);

        $byPartly = $this->get('/purchase_orders?status=partly_received');

        $byPartly->assertSee('id="document_' . $partly . '"');
        $byPartly->assertDontSee('id="document_' . $open . '"');
        $byPartly->assertDontSee('id="document_' . $full . '"');

        $byReceived = $this->get('/purchase_orders?status=received');

        $byReceived->assertSee('id="document_' . $full . '"');
        $byReceived->assertDontSee('id="document_' . $partly . '"');
    }
}
