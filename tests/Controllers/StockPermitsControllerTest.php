<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingError;
use App\Libraries\Stock\ItemStockProfile;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockPermissions;
use App\Libraries\Stock\StockPermit;
use App\Libraries\Stock\StockPermitSources;
use App\Libraries\Stock\TrackingType;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Exceptions\PageNotFoundException;
use Config\Database;
use Config\Filters;
use Config\Services;
use ReflectionClass;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StockDocumentFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * The screens of the stock permits: who may open them, what they list for the branches of the employee,
 * that every change is a POST the books may refuse in words, and that they work while accounting is off.
 */
class StockPermitsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;
    use StockDocumentFixtureTrait;
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private StockPermit $permits;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockDocuments();
        $this->permits = new StockPermit();
    }

    protected function tearDown(): void
    {
        $this->stopStockUnits();
        $this->stopStockDocuments();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<string>   $permissions
     * @param list<int>|null $branchIds
     */
    private function actAsWith(array $permissions, ?array $branchIds = null): int
    {
        $personId = $this->keeper($branchIds, $permissions);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function actAsKeeper(): int
    {
        return $this->actAsWith([StockPermissions::MODULE, StockPermissions::PERMITS_MANAGE, 'items_stock']);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    private function addPermit(int $itemId, string $quantity = '2', string $cost = '10', array $more = []): int
    {
        return $this->permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => $quantity, 'unit_cost' => $cost]], ...$more], $this->keeperId);
    }

    public function testGetIndex_ListsThePermitsAndOffersTheFormsToWhoMayManageThem(): void
    {
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->addPermit($itemId, '2', '25', ['note' => 'for the shop']);

        $this->actAsKeeper();

        $page = $this->get('/stock_documents/permits');

        $page->assertStatus(200);
        $page->assertSee('id="permit_' . $permitId . '"');
        $page->assertSee('for the shop');
        $page->assertSee('id="permit_new_add"');
        $page->assertSee('id="permit_new_issue"');
        $page->assertDontSee('id="permit_new_transfer"');
        $page->assertSee(lang('Stock_documents.edit'));
        $page->assertSee(lang('Stock_documents.cancel'));
        $page->assertSee('id="stock_tab_permits"');
        $page->assertSee('id="stock_tab_stocktakes"');
    }

    public function testGetIndex_PointsTheArrowFromTheSendingLocationToTheReceivingOneInBothReadingDirections(): void
    {
        $this->switchOnTransferPermit();

        $second = $this->openLocation('Second warehouse');
        $itemId = $this->stocked('10', '50');

        $this->permits->create(['type' => 'transfer', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'to_location_id' => $second, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]], $this->keeperId);

        $personId = $this->actAsKeeper();

        $english = $this->get('/stock_documents/permits')->getBody();

        $this->assertStringContainsString('</bdi> &rarr; <bdi>Second warehouse</bdi>', $english);
        $this->assertStringNotContainsString('&larr;', $english);

        $this->accountingDb()->table('employees')->where('person_id', $personId)->update(['language_code' => 'ar-EG', 'language' => 'arabic-egypt']);

        $arabic = $this->get('/stock_documents/permits')->getBody();

        $this->assertStringContainsString('</bdi> &larr; <bdi>Second warehouse</bdi>', $arabic, 'the sending side is on the right and the arrow points to the receiving side on the left');
        $this->assertStringNotContainsString('&rarr;', $arabic);
    }

    public function testGetIndex_OffersNoFormAndNoChangeToWhoMayOnlyLook(): void
    {
        $this->addPermit($this->stocked('10', '50'));
        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $page = $this->get('/stock_documents');

        $page->assertStatus(200);
        $page->assertDontSee('id="permit_new_add"');
        $page->assertDontSee('stock_documents/permits/edit/');
        $page->assertDontSee('stock_documents/permits/cancel_form/');
        $page->assertDontSee('id="stock_settings_form"');
    }

    public function testGetIndex_OffersTheTransferFormOnlyWhileItsSettingIsOn(): void
    {
        $this->actAsKeeper();
        $this->switchOnTransferPermit();

        $this->get('/stock_documents/permits')->assertSee('id="permit_new_transfer"');
    }

    public function testGetIndex_RedirectsAnEmployeeWithoutTheModuleGrant(): void
    {
        $this->actAsWith([]);

        $this->assertSame(302, $this->get('/stock_documents/permits')->response()->getStatusCode());
    }

    public function testGetIndex_OpensWhileAccountingIsOffAndAPermitIsSavedThen(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->setConfigValue('accounting_enabled', '0');
        $this->actAsKeeper();

        $this->get('/stock_documents/permits')->assertStatus(200);

        $answer = $this->postJson('/stock_documents/permits/save', ['type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => (string) $itemId, 'quantity' => '3', 'unit_cost' => '5']]]);

        $this->assertTrue($answer['success']);
        $this->assertSame('13.000', $this->onHand($itemId));
    }

    public function testGetIndex_ShowsOnlyThePermitsOfTheBranchesOfTheEmployeeAndSaysSo(): void
    {
        $other  = $this->createBranch();
        $store  = $this->locationOfBranch($other, 'Branch store');
        $this->allowLocation($store, $this->keeperId);
        $itemId = $this->stocked('10', '50');

        $mine   = $this->addPermit($itemId);
        $theirs = $this->addPermit($itemId, '1', '1', ['location_id' => $store]);

        $this->actAsWith([StockPermissions::MODULE], [$other]);

        $page = $this->get('/stock_documents/permits');

        $page->assertSee('id="permit_' . $theirs . '"');
        $page->assertDontSee('id="permit_' . $mine . '"');
        $page->assertSee('id="permits_scope"');

        $this->actAsWith([StockPermissions::MODULE]);

        $this->get('/stock_documents/permits')->assertDontSee('id="permits_scope"');
    }

    public function testGetIndex_FiltersAndPages(): void
    {
        $itemId = $this->stocked('10', '50');
        $added  = $this->addPermit($itemId);
        $issued = $this->permits->create(['type' => 'issue', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $itemId, 'quantity' => '1']]], $this->keeperId);

        $this->permits->cancel($issued, 'x', $this->keeperId);

        for ($i = 0; $i < 25; $i++) {
            $this->addPermit($itemId, '1', '1');
        }

        $this->actAsKeeper();

        foreach (['status=cancelled', 'type=issue'] as $filter) {
            $page = $this->get('/stock_documents/permits?' . $filter);

            $page->assertSee('id="permit_' . $issued . '"');
            $page->assertDontSee('id="permit_' . $added . '"');
        }

        $first = $this->get('/stock_documents/permits');

        $this->assertSame(25, preg_match_all('/id="permit_\d+"/', $first->getBody()));
        $first->assertSee(lang('Stock_documents.next'));
        $first->assertDontSee(lang('Stock_documents.previous'));
        $this->get('/stock_documents/permits?offset=25')->assertSee(lang('Stock_documents.previous'));
    }

    public function testGetIndex_OffersTheSettingsToWhoMayChangeThemAndSavesThemInThePlaceOfTheAccountingOnes(): void
    {
        $this->actAsWith([StockPermissions::MODULE, AccountingGuard::SETTINGS_MANAGE]);

        $this->get('/stock_documents/permits')->assertSee('id="stock_settings_form"');

        $answer = $this->postJson('/stock_documents/permits/settings', ['transfer_permit' => '1', 'requests' => '0']);

        $this->assertTrue($answer['success']);
        $this->assertSame('1', $this->accountingDb()->table('app_config')->where('key', 'stock_transfer_permit_enabled')->get()->getRow()->value);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'settings', 'action' => 'settings_updated'])->countAllResults());
    }

    public function testPostSettings_RejectsWhoMayNotChangeTheSettings(): void
    {
        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/settings', ['transfer_permit' => '1']);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $answer['message']);
        $this->assertSame(0, $this->accountingDb()->table('app_config')->where('key', 'stock_transfer_permit_enabled')->countAllResults());
    }

    public function testGetForm_ServesTheFormsToWhoMayManagePermitsAndRejectsWhatIsNotAllowed(): void
    {
        $this->actAsKeeper();

        $add = $this->get('/stock_documents/permits/form/add');

        $add->assertStatus(200);
        $add->assertSee('name="permit_date"');
        $add->assertSee('name="supplier_id"');
        $add->assertSee('name="account_id"');
        $add->assertSee('lines[');

        $issue = $this->get('/stock_documents/permits/form/issue');

        $issue->assertStatus(200);
        $issue->assertSee('name="customer_id"');
        $issue->assertSee('const withCost = false;');
        $issue->assertSee(lang('Stock_documents.cost_is_average'));

        $this->assertSame(403, $this->get('/stock_documents/permits/form/transfer')->response()->getStatusCode(), 'off until the setting is on');
        $this->assertSame(403, $this->get('/stock_documents/permits/form/swap')->response()->getStatusCode());

        $this->switchOnTransferPermit();

        $transfer = $this->get('/stock_documents/permits/form/transfer');

        $transfer->assertStatus(200);
        $transfer->assertSee('name="to_location_id"');

        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $this->assertSame(403, $this->get('/stock_documents/permits/form/add')->response()->getStatusCode());
    }

    public function testGetForm_OffersOnlyTheLocationsTheEmployeeHoldsAndCarriesTheTokenWhenProtectionIsOn(): void
    {
        $this->actAsKeeper();

        $hidden = $this->openLocation('Hidden warehouse', $this->employeeWithGrants([]));

        $body = $this->get('/stock_documents/permits/form/add')->getBody();

        $this->assertStringContainsString('value="' . self::LOCATION_ID . '"', $body);
        $this->assertStringNotContainsString('value="' . $hidden . '"', $body);

        service('filters')->enableFilters(['csrf'], 'before');

        try {
            $html = view('stock_permits/cancel_form', ['permitId' => 5, 'number' => 'X']);
        } finally {
            Services::resetSingle('filters');
        }

        $this->assertStringContainsString('name="' . csrf_token() . '"', $html);
    }

    public function testGetEditForm_ServesAVisiblePermitAndRejectsTheRest(): void
    {
        $other    = $this->createBranch();
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->addPermit($itemId, '2', '25');

        $this->actAsKeeper();

        $edit = $this->get('/stock_documents/permits/edit/' . $permitId);

        $edit->assertStatus(200);
        $edit->assertSee('"quantity":"2"');

        $cancel = $this->get('/stock_documents/permits/cancel_form/' . $permitId);

        $cancel->assertStatus(200);
        $cancel->assertSee('name="reason"');

        $view = $this->get('/stock_documents/permits/view/' . $permitId);

        $view->assertStatus(200);
        $view->assertSee('id="permit_lines_table"');
        $view->assertDontSee('id="permit_journals"');

        $this->assertSame(403, $this->get('/stock_documents/permits/view/999999')->response()->getStatusCode());

        $this->actAsWith([StockPermissions::MODULE, StockPermissions::PERMITS_MANAGE, 'accounting_journals', 'items_stock']);

        $this->get('/stock_documents/permits/view/' . $permitId)->assertSee('id="permit_journals"');

        $this->actAsWith([StockPermissions::MODULE, StockPermissions::PERMITS_MANAGE], [$other]);

        foreach (['edit', 'cancel_form', 'view'] as $page) {
            $this->assertSame(403, $this->get('/stock_documents/permits/' . $page . '/' . $permitId)->response()->getStatusCode(), $page);
        }

        $this->actAsWith([StockPermissions::MODULE]);

        $this->assertSame(403, $this->get('/stock_documents/permits/edit/' . $permitId)->response()->getStatusCode());
        $this->assertSame(403, $this->get('/stock_documents/permits/cancel_form/' . $permitId)->response()->getStatusCode());

        $this->permits->cancel($permitId, 'gone', $this->keeperId);
        $this->actAsKeeper();

        $this->assertSame(403, $this->get('/stock_documents/permits/edit/' . $permitId)->response()->getStatusCode(), 'a cancelled permit is not corrected');

        $cancelled = $this->get('/stock_documents/permits/view/' . $permitId);

        $cancelled->assertStatus(200);
        $cancelled->assertDontSee('stock_documents/permits/edit/');
    }

    public function testPostSavePermit_SavesThePermitMovesTheStockAndPostsTheBooks(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/save', [
            'type'        => 'add',
            'permit_date' => date('Y-m-d'),
            'location_id' => (string) self::LOCATION_ID,
            'supplier_id' => (string) $this->supplierId,
            'note'        => 'delivery',
            'lines'       => [['item_id' => (string) $itemId, 'quantity' => '2', 'unit_cost' => '150'], ['item_id' => '', 'quantity' => '', 'unit_cost' => '']],
        ]);

        $this->assertTrue($answer['success']);
        $this->assertSame(lang('Stock_documents.permit_saved'), $answer['message']);
        $this->assertSame('12.000', $this->onHand($itemId));
        $this->assertCount(1, $this->journalsOfSource(StockPermitSources::PERMIT, (int) $answer['id']));
        $this->assertSame('delivery', $this->permits->find((int) $answer['id'])->note);
        $this->assertCount(1, $this->permits->lines((int) $answer['id']), 'a row left blank is no line');
    }

    public function testPostSavePermit_RejectsInWordsWhatTheShelvesCannotGiveAndSavesNothing(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/save', ['type' => 'issue', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => (string) $itemId, 'quantity' => '11']]]);

        $this->assertFalse($answer['success']);
        $this->assertSame(AccountingMessage::of(new AccountingException(AccountingError::PurchaseStockWouldGoNegative, 'x', ['name' => $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->name, 'scope_name' => 'stock'])), $answer['message']);
        $this->assertSame(0, $this->countRows('stock_permits'));
        $this->assertSame('10.000', $this->onHand($itemId));
    }

    public function testPostSavePermit_RejectsAFieldThatIsAListInWordsAndSavesNothing(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->actAsKeeper();

        $line = $this->postJson('/stock_documents/permits/save', ['type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => [(string) $itemId], 'quantity' => '2', 'unit_cost' => '1']]]);

        $this->assertFalse($line['success']);
        $this->assertSame(lang('Accounting.error_stock_permit_lines_invalid', ['1']), $line['message']);

        $type = $this->postJson('/stock_documents/permits/save', ['type' => ['add'], 'permit_date' => ['x'], 'location_id' => (string) self::LOCATION_ID, 'note' => ['x'], 'lines' => [['item_id' => (string) $itemId, 'quantity' => ['2'], 'unit_cost' => '1']]]);

        $this->assertFalse($type['success']);
        $this->assertSame(lang('Accounting.error_stock_permit_type_invalid'), $type['message']);

        $this->assertSame(0, $this->countRows('stock_permits'));
        $this->assertSame('10.000', $this->onHand($itemId));
        $this->assertSame(200, $this->get('/stock_documents/permits?status[]=x&type[]=y&search[]=z')->response()->getStatusCode(), 'a filter that is a list is no filter');
    }

    public function testPostSavePermit_RejectsAnEmployeeWithoutThePermissionEvenIfTheyHoldTheModule(): void
    {
        $itemId = $this->stocked('10', '50');
        $this->actAsWith([StockPermissions::MODULE, 'items_stock']);

        $answer = $this->postJson('/stock_documents/permits/save', ['type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => (string) $itemId, 'quantity' => '1', 'unit_cost' => '1']]]);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_permission_denied'), $answer['message']);
        $this->assertSame(0, $this->countRows('stock_permits'));
    }

    public function testPostUpdate_CorrectsAndCancelsAPermitOfTheBranchesOfTheEmployeeOnly(): void
    {
        $other    = $this->createBranch();
        $itemId   = $this->stocked('10', '50');
        $permitId = $this->addPermit($itemId, '2', '25');

        $this->actAsWith([StockPermissions::MODULE, StockPermissions::PERMITS_MANAGE, 'items_stock'], [$other]);

        $refused = $this->postJson('/stock_documents/permits/cancel/' . $permitId, ['reason' => 'no']);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_stock_permit_not_found', ['']), $refused['message']);
        $this->assertSame('posted', $this->permits->find($permitId)->status);

        $refusedUpdate = $this->postJson('/stock_documents/permits/update/' . $permitId, ['permit_date' => date('Y-m-d'), 'note' => 'other branch', 'lines' => [['item_id' => (string) $itemId, 'quantity' => '9', 'unit_cost' => '25']]]);

        $this->assertFalse($refusedUpdate['success']);
        $this->assertSame(lang('Accounting.error_stock_permit_not_found', ['']), $refusedUpdate['message']);
        $this->assertSame(1, (int) $this->permits->find($permitId)->revision);

        $this->actAsKeeper();

        $updated = $this->postJson('/stock_documents/permits/update/' . $permitId, ['permit_date' => date('Y-m-d'), 'note' => 'corrected', 'lines' => [['item_id' => (string) $itemId, 'quantity' => '3', 'unit_cost' => '25']]]);

        $this->assertTrue($updated['success']);
        $this->assertSame(lang('Stock_documents.permit_updated'), $updated['message']);
        $this->assertSame('13.000', $this->onHand($itemId));

        $cancelled = $this->postJson('/stock_documents/permits/cancel/' . $permitId, ['reason' => 'entered twice']);

        $this->assertTrue($cancelled['success']);
        $this->assertSame(lang('Stock_documents.permit_cancelled'), $cancelled['message']);
        $this->assertSame('10.000', $this->onHand($itemId));

        $again = $this->postJson('/stock_documents/permits/cancel/' . $permitId, ['reason' => 'twice']);

        $this->assertFalse($again['success']);
    }

    public function testPostCancel_AsksForAReasonInWords(): void
    {
        $permitId = $this->addPermit($this->stocked('10', '50'));
        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/cancel/' . $permitId, ['reason' => '']);

        $this->assertFalse($answer['success']);
        $this->assertSame(lang('Accounting.error_reason_required'), $answer['message']);
    }

    public function testGetItems_FindsStockItemsByNameAndNumberForWhoMayManageStockDocumentsAndNoOneElse(): void
    {
        $itemId = $this->item('5.00');
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['name' => 'Zebra paint', 'item_number' => 'ZP-77']);
        $service = $this->item('1.00', false);
        $this->accountingDb()->table('items')->where('item_id', $service)->update(['name' => 'Zebra service']);

        $this->actAsKeeper();

        $found = json_decode($this->get('/stock_documents/items?term=Zebra')->getJSON(), true);

        $this->assertSame([$itemId], array_column($found, 'value'), 'only items that keep stock');
        $this->assertSame('Zebra paint (ZP-77)', $found[0]['label']);
        $this->assertSame([$itemId], array_column(json_decode($this->get('/stock_documents/items?term=ZP-77')->getJSON(), true), 'value'));

        $this->actAsWith([StockPermissions::MODULE]);

        $this->assertSame(403, $this->get('/stock_documents/items')->response()->getStatusCode());
    }

    public function testGetItems_TellsTheUnitsAndTheTrackingOfAnItemWhileTheirSettingsAreOn(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->switchOnStock('tracking');
        (new ItemStockProfile())->setTracking($setup['item'], TrackingType::Serial, $this->unitManagerId);
        $this->actAsKeeper();

        $found = json_decode($this->get('/stock_documents/items?item_id=' . $setup['item'])->getJSON(), true)[0];

        $this->assertSame('serial', $found['tracking']);
        $this->assertSame([['value' => '', 'label' => 'Piece'], ['value' => (string) $setup['carton'], 'label' => 'Carton (x12)']], $found['units']);
    }

    public function testGetItems_TellsTheTrackingButNotTheUnitsWhileOnlyTheUnitsAreOff(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->switchOnStock('tracking');
        (new ItemStockProfile())->setTracking($setup['item'], TrackingType::Batch, $this->unitManagerId);
        $this->accountingDb()->table('app_config')->where('key', StockOptions::UNITS)->delete();
        $this->actAsKeeper();

        $found = json_decode($this->get('/stock_documents/items?item_id=' . $setup['item'])->getJSON(), true)[0];

        $this->assertSame('batch', $found['tracking']);
        $this->assertArrayNotHasKey('units', $found);
    }

    public function testGetItems_TellsNothingOfUnitsOrTrackingWhileTheirSettingsAreOff(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->accountingDb()->table('app_config')->whereIn('key', [StockOptions::UNITS, StockOptions::TRACKING])->delete();
        $this->actAsKeeper();

        $found = json_decode($this->get('/stock_documents/items?item_id=' . $setup['item'])->getJSON(), true)[0];

        $this->assertArrayNotHasKey('tracking', $found);
        $this->assertArrayNotHasKey('units', $found);
    }

    public function testGetForm_OffersTheUnitAndTheTrackingColumnsOnlyWhileTheirSettingsAreOn(): void
    {
        $this->actAsKeeper();

        $off = $this->get('/stock_documents/permits/form/add');

        $off->assertDontSee(lang('Stock_units.tracking_entry'));

        $this->switchOnStock('units', 'tracking');

        $on = $this->get('/stock_documents/permits/form/add');

        $on->assertSee(lang('Stock_units.tracking_entry'));
        $this->assertStringContainsString('const unitsOn = true;', $on->getBody());
        $this->assertStringContainsString('const trackingOn = true;', $on->getBody());
        $this->assertStringContainsString('<th scope="col">' . lang('Stock_units.unit') . '</th>', $on->getBody());
    }

    public function testGetForm_OffersTheUnitColumnWithoutTheTrackingColumnWhileOnlyUnitsAreOn(): void
    {
        $this->switchOnStock('units');
        $this->actAsKeeper();

        $body = $this->get('/stock_documents/permits/form/add')->getBody();

        $this->assertStringContainsString('const unitsOn = true;', $body);
        $this->assertStringContainsString('const trackingOn = false;', $body);
        $this->assertStringContainsString('<th scope="col">' . lang('Stock_units.unit') . '</th>', $body);
        $this->assertStringNotContainsString(lang('Stock_units.tracking_entry'), $body);
    }

    public function testGetForm_OffersTheTrackingColumnWithoutTheUnitColumnWhileOnlyTrackingIsOn(): void
    {
        $this->switchOnStock('tracking');
        $this->actAsKeeper();

        $body = $this->get('/stock_documents/permits/form/add')->getBody();

        $this->assertStringContainsString('const unitsOn = false;', $body);
        $this->assertStringContainsString('const trackingOn = true;', $body);
        $this->assertStringNotContainsString('<th scope="col">' . lang('Stock_units.unit') . '</th>', $body);
        $this->assertStringContainsString(lang('Stock_units.tracking_entry'), $body);
    }

    public function testPostSavePermit_SavesALineEnteredInCartonsInBaseUnits(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/save', ['type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => (string) $setup['item'], 'quantity' => '2', 'unit_cost' => '60', 'unit_id' => (string) $setup['carton']]]]);

        $this->assertTrue($answer['success']);

        $line = $this->permits->lines((int) $answer['id'])[0];

        $this->assertSame(['24.000', '5.000000', $setup['carton'], '2.000'], [bcadd((string) $line->quantity, '0', 3), bcadd((string) $line->unit_cost, '0', 6), (int) $line->unit_id, bcadd((string) $line->unit_quantity, '0', 3)]);
        $this->assertSame('24.000', $this->onHand($setup['item']));
    }

    public function testPostSavePermit_SavesTheSerialNumbersTypedForATrackedItem(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial, '0.00');

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/save', ['type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => (string) $itemId, 'quantity' => '2', 'unit_cost' => '10', 'serials' => "SP-1\nSP-2"]]]);

        $this->assertTrue($answer['success']);
        $this->assertSame(2, $this->accountingDb()->table('stock_serials')->where(['item_id' => $itemId, 'state' => 'in_stock'])->countAllResults());
        $this->assertSame('2.000', $this->onHand($itemId));
    }

    public function testPostSavePermit_SavesTheSingleBatchTypedForATrackedItemForTheWholeQuantity(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch, '0.00');

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/save', ['type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => (string) $itemId, 'quantity' => '6', 'unit_cost' => '10', 'batch_number' => 'SP-B1', 'batch_expiry' => date('Y-m-d', strtotime('+60 days'))]]]);

        $this->assertTrue($answer['success']);
        $this->assertSame('6.000', bcadd((string) $this->accountingDb()->table('stock_batch_quantities q')->select('q.quantity')->join('stock_batches b', 'b.batch_id = q.batch_id')->where(['b.item_id' => $itemId, 'b.batch_number' => 'SP-B1'])->get()->getRow()->quantity, '0', 3));
        $this->assertSame('6.000', $this->onHand($itemId));
    }

    public function testPostSavePermit_RejectsATrackedItemWithNoSerialNumberTypedAndSavesNothing(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial, '0.00');

        $this->actAsKeeper();

        $answer = $this->postJson('/stock_documents/permits/save', ['type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => (string) self::LOCATION_ID, 'lines' => [['item_id' => (string) $itemId, 'quantity' => '2', 'unit_cost' => '10']]]);

        $this->assertFalse($answer['success']);
        $this->assertStringContainsString(lang('Accounting.error_tracked_entry_required', [1, (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->name]), $answer['message']);
        $this->assertSame('0.000', $this->onHand($itemId));
    }

    public function testGetEditForm_ShowsAUnitLineAsItWasEntered(): void
    {
        $setup    = $this->cartonItem('5.00');
        $permitId = $this->permits->create(['type' => 'add', 'date' => date('Y-m-d'), 'location_id' => self::LOCATION_ID, 'lines' => [['item_id' => $setup['item'], 'unit_id' => $setup['carton'], 'unit_quantity' => '2', 'unit_price' => '60']]], $this->keeperId);

        $this->actAsKeeper();

        $page = $this->get('/stock_documents/permits/edit/' . $permitId);

        $page->assertStatus(200);
        $this->assertStringContainsString('"unit":"' . $setup['carton'] . '","quantity":"2","cost":"60"', $page->getBody());
    }

    public function testGetItems_ShowsWhatIsOnHandInTheLocationAskedForOnlyWhereTheEmployeeMayUseIt(): void
    {
        $itemId = $this->stocked('10', '50');
        $other  = $this->stocked('4', '50');
        $closed = $this->anotherLocation('Closed warehouse');
        $this->addPermit($itemId, '2', '25');

        $empty = $this->openLocation('Shelf two', $this->actAsKeeper());

        $own = json_decode($this->get('/stock_documents/items?item_id=' . $itemId . '&location_id=' . self::LOCATION_ID)->getJSON(), true);

        $this->assertSame([$itemId], array_column($own, 'value'), 'a single item is asked for by its id');
        $this->assertSame('12.000', $own[0]['on_hand']);

        $none = json_decode($this->get('/stock_documents/items?item_id=' . $other . '&location_id=' . $closed)->getJSON(), true);

        $this->assertCount(1, $none);
        $this->assertArrayNotHasKey('on_hand', $none[0], 'a location the employee may not use gives no figure');

        $without = json_decode($this->get('/stock_documents/items?item_id=' . $other)->getJSON(), true);

        $this->assertArrayNotHasKey('on_hand', $without[0]);

        $elsewhere = json_decode($this->get('/stock_documents/items?item_id=' . $itemId . '&location_id=' . $empty)->getJSON(), true);

        $this->assertSame('0.000', $elsewhere[0]['on_hand'], 'nothing there');
    }

    public function testGetParties_FindsSuppliersAndCustomersByNameForWhoMayManagePermits(): void
    {
        $this->actAsKeeper();

        $suppliers = json_decode($this->get('/stock_documents/parties?kind=supplier&term=Gulf')->getJSON(), true);
        $customers = json_decode($this->get('/stock_documents/parties?kind=customer&term=Omar')->getJSON(), true);

        $this->assertContains($this->supplierId, array_column($suppliers, 'value'));
        $this->assertContains($this->customerId, array_column($customers, 'value'));
        $this->assertSame([], json_decode($this->get('/stock_documents/parties?kind=supplier&term=zzzzzz-nobody')->getJSON(), true));

        $this->actAsWith([StockPermissions::MODULE]);

        $this->assertSame(403, $this->get('/stock_documents/parties')->response()->getStatusCode());
    }

    public function testRoutes_RequiresPostAndTheGlobalFilterForEveryChangeOfAStockDocument(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        $this->assertSame(1, preg_match("/group\\('stock_documents'.*?\\n}\\);/s", $source, $group));
        $this->assertGreaterThan(0, preg_match_all("/\\\$routes->(get|post)\\('([^']*)', '(\\w+)::(get|post)\\w*[^']*'\\);/", $group[0], $routes, PREG_SET_ORDER));

        $posts = 0;

        foreach ($routes as [, $verb, $uri, , $prefix]) {
            $this->assertSame($verb, $prefix, "{$uri} is a {$verb} route to a {$prefix} method");

            $posts += $verb === 'post' ? 1 : 0;
        }

        $this->assertSame(substr_count($group[0], '$routes->'), count($routes), 'every route of the group was read');
        $this->assertSame(14, $posts);

        $globals = (new ReflectionClass(Filters::class))->getDefaultProperties()['globals'];

        $this->assertArrayHasKey('csrf', $globals['before']);
        $this->assertSame(0, preg_match('#^(' . $globals['before']['csrf']['except'] . ')#', 'stock_documents/permits/save'));
        $this->expectException(PageNotFoundException::class);

        $this->get('/stock_documents/permits/save');
    }
}
