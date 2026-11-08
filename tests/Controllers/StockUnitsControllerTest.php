<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Stock\ItemStockProfile;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockUnitPermissions;
use App\Libraries\Stock\TrackingType;
use App\Libraries\Stock\UnitBarcodes;
use App\Libraries\Stock\UnitTemplates;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * The screens of the unit templates and of the units and tracking of an item, and who may use them.
 *
 * @internal
 */
final class StockUnitsControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ShelfFixtureTrait;
    use StockUnitsFixtureTrait;

    private const GHOST = 999000002;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockUnits();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('sales_items')->where('sale_id', self::GHOST)->delete();
        $this->stopStockUnits();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @param list<string> $permissions
     */
    private function actAs(array $permissions): int
    {
        $personId = $this->employeeWithGrants($permissions);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    private function actAsManager(): int
    {
        return $this->actAs([...StockUnitPermissions::ALL, StockUnitPermissions::MODULE, AccountingGuard::SETTINGS_MANAGE]);
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $data = []): array
    {
        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    private function markUsed(int $templateId): void
    {
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('sales_items')->insert(['sale_id' => self::GHOST, 'item_id' => self::GHOST, 'line' => 1, 'item_cost_price' => '0', 'item_unit_price' => '10.00', 'item_location' => 1, 'quantity_purchased' => '12.000', 'unit_id' => $this->unitIdOf($templateId), 'unit_factor' => '12.0000', 'unit_quantity' => '1.000', 'unit_price_entered' => '120.000000']);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function messageOf(AccountingError $error, string ...$arguments): string
    {
        return lang('Accounting.error_' . $error->value, $arguments);
    }

    /**
     * @return array<string, mixed>
     */
    private function form(string $name = 'Boxes'): array
    {
        return ['name' => $name, 'main_unit_name' => 'Piece', 'main_small_name' => 'pc', 'active' => '1', 'unit_name' => ['Box', ''], 'unit_factor' => ['6', ''], 'unit_small_name' => ['bx', '']];
    }

    public function testGetIndex_ShowsTheSettingsAndTheTemplatesToWhoMayManageThem(): void
    {
        $this->template('Cartons');
        $this->actAsManager();

        $result = $this->get('/stock_units');

        $result->assertOK();
        $result->assertSee('Cartons');
        $result->assertSee('stock_options_form');
        $result->assertSee('template_new');
    }

    public function testGetIndex_RejectsAnEmployeeWithoutTheModule(): void
    {
        $this->actAs([]);

        $response = $this->get('/stock_units');

        $response->assertStatus(302);
        $this->assertStringContainsString('no_access', (string) $response->getRedirectUrl());
    }

    public function testGetIndex_HidesTheSettingsFromWhoMayNotChangeThem(): void
    {
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $result = $this->get('/stock_units');

        $result->assertOK();
        $result->assertDontSee('stock_options_form');
    }

    public function testPostSettings_SwitchesTheOptionsOnForWhoMayChangeTheSettings(): void
    {
        $this->actAsManager();

        $response = $this->postJson('/stock_units/settings', ['units' => '1', 'tracking' => '1', 'bundles' => '0', 'negative_stock' => 'refuse']);

        $this->assertTrue($response['success']);
        $this->assertSame(['units' => true, 'bundles' => false, 'tracking' => true, 'expired_refused' => true, 'negative_stock' => 'refuse'], (new StockOptions())->all());
    }

    public function testPostSettings_RefusesWhoMayNotChangeTheSettings(): void
    {
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $response = $this->postJson('/stock_units/settings', ['units' => '1']);

        $this->assertFalse($response['success']);
        $this->assertFalse((new StockOptions())->unitsEnabled());
    }

    public function testPostSaveTemplate_CreatesATemplateFromTheFormAndSkipsTheEmptyRow(): void
    {
        $this->switchOnStock('units');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveTemplate/-1', $this->form());

        $this->assertTrue($response['success']);

        $template = (new UnitTemplates())->find((int) $response['id']);

        $this->assertSame(['Boxes', 1], [$template->name, count($template->units)]);
        $this->assertSame('6.0000', $template->units[0]->factor);
    }

    public function testPostSaveTemplate_RefusesAFactorThatIsNotPositiveWithAMessage(): void
    {
        $this->switchOnStock('units');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveTemplate/-1', [...$this->form(), 'unit_factor' => ['0', '']]);

        $this->assertFalse($response['success']);
        $this->assertNotSame('', $response['message']);
        $this->assertSame([], (new UnitTemplates())->all());
    }

    public function testPostSaveTemplate_RefusesWhoMayNotManageUnits(): void
    {
        $this->switchOnStock('units');
        $this->actAs([StockUnitPermissions::MODULE]);

        $this->assertFalse($this->postJson('/stock_units/saveTemplate/-1', $this->form())['success']);
        $this->assertSame([], (new UnitTemplates())->all());
    }

    public function testGetTemplateForm_RejectsWhoMayNotManageUnits(): void
    {
        $this->actAs([StockUnitPermissions::MODULE]);

        $this->assertSame(403, $this->get('/stock_units/templateForm/-1')->response()->getStatusCode());
    }

    public function testPostSetActive_SwitchesATemplateOff(): void
    {
        $templateId = $this->template('Cartons');
        $this->actAsManager();

        $this->assertTrue($this->postJson('/stock_units/setActive/' . $templateId, ['active' => '0'])['success']);
        $this->assertFalse((new UnitTemplates())->find($templateId)->active);
    }

    public function testPostSaveItem_GivesAnItemItsTemplateAndItsTracking(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => (string) $templateId, 'tracking_type' => 'batch']);

        $profile = (new ItemStockProfile())->get($itemId);

        $this->assertTrue($response['success']);
        $this->assertSame([$templateId, TrackingType::Batch], [$profile->unitTemplateId, $profile->tracking]);
    }

    public function testPostSaveItem_RefusesWhoMayNotManageTracking(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => (string) $templateId, 'tracking_type' => 'serial']);

        $this->assertFalse($response['success']);
        $this->assertSame(TrackingType::None, (new ItemStockProfile())->get($itemId)->tracking);
    }

    public function testPostSaveItem_RefusesToChangeTheTrackingOfAnItemThatHoldsStock(): void
    {
        $this->template('Cartons');

        $itemId = $this->createTestItem();
        $name   = (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->name;

        $this->putOnShelf($itemId, '3');
        $this->switchOnStock('tracking');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => '', 'tracking_type' => 'serial']);

        $this->assertFalse($response['success']);
        $this->assertSame($this->messageOf(AccountingError::TrackingChangeWithStock, $name), $response['message']);
        $this->assertSame(TrackingType::None, (new ItemStockProfile())->get($itemId)->tracking);
        $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->delete();
    }

    public function testPostSaveItem_SavesTheTrackingWhileTheUnitsSettingIsOff(): void
    {
        $itemId = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => '', 'tracking_type' => 'serial']);

        $this->assertTrue($response['success']);
        $this->assertSame(TrackingType::Serial, (new ItemStockProfile())->get($itemId)->tracking);
    }

    public function testPostSaveItem_SavesTheTemplateWhileTheTrackingSettingIsOff(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => (string) $templateId, 'tracking_type' => 'none']);

        $this->assertTrue($response['success']);
        $this->assertSame($templateId, (new ItemStockProfile())->get($itemId)->unitTemplateId);
    }

    public function testPostSaveItem_SavesNothingWhenTheTrackingChangeIsRefused(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        $this->putOnShelf($itemId, '3');
        $this->switchOnStock('tracking');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => (string) $templateId, 'tracking_type' => 'serial']);
        $profile  = (new ItemStockProfile())->get($itemId);

        $this->assertFalse($response['success']);
        $this->assertSame([null, TrackingType::None], [$profile->unitTemplateId, $profile->tracking]);
        $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->delete();
    }

    public function testPostSaveItem_SavesTheTemplateOfWhoMayNotManageTrackingWhenTheTrackingIsAsItWas(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => (string) $templateId, 'tracking_type' => 'none']);

        $this->assertTrue($response['success']);
        $this->assertSame($templateId, (new ItemStockProfile())->get($itemId)->unitTemplateId);
    }

    public function testPostSaveItem_LeavesTheTemplateAloneWhenNoneIsPosted(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        (new ItemStockProfile())->setTemplate($itemId, $templateId, $this->unitManagerId);
        $this->switchOnStock('tracking');
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::TRACKING_MANAGE]);

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['tracking_type' => 'batch']);
        $profile  = (new ItemStockProfile())->get($itemId);

        $this->assertTrue($response['success']);
        $this->assertSame([$templateId, TrackingType::Batch], [$profile->unitTemplateId, $profile->tracking]);
    }

    public function testPostSaveTemplate_UpdatesATemplateNoLineHasUsed(): void
    {
        $templateId = $this->template('Cartons');

        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveTemplate/' . $templateId, [...$this->form('Cartons'), 'unit_factor' => ['24', '']]);

        $this->assertTrue($response['success']);
        $this->assertSame($templateId, $response['id']);
        $this->assertSame(lang('Stock_units.template_saved'), $response['message']);
        $this->assertSame('24.0000', (new UnitTemplates())->find($templateId)->units[0]->factor);
    }

    public function testPostSaveTemplate_SavesAUsedTemplateAsANewVersionWhenAsked(): void
    {
        $templateId = $this->template('Cartons');

        $this->markUsed($templateId);
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveTemplate/' . $templateId, [...$this->form('Cartons'), 'unit_factor' => ['24', ''], 'as_new_version' => '1']);

        $this->assertTrue($response['success']);
        $this->assertSame(lang('Stock_units.template_versioned'), $response['message']);
        $this->assertNotSame($templateId, $response['id']);

        $templates = new UnitTemplates();

        $this->assertSame(['12.0000', false, 1], [$templates->find($templateId)->units[0]->factor, $templates->find($templateId)->active, $templates->find($templateId)->version]);
        $this->assertSame(['24.0000', true, 2], [$templates->find($response['id'])->units[0]->factor, $templates->find($response['id'])->active, $templates->find($response['id'])->version]);
    }

    public function testPostSaveTemplate_RefusesToChangeTheFactorOfAUsedTemplateThatIsNotSavedAsANewVersion(): void
    {
        $templateId = $this->template('Cartons');

        $this->markUsed($templateId);
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveTemplate/' . $templateId, [...$this->form('Cartons'), 'unit_factor' => ['24', '']]);

        $this->assertFalse($response['success']);
        $this->assertSame($this->messageOf(AccountingError::UnitTemplateUsed, 'Cartons'), $response['message']);
        $this->assertSame('12.0000', (new UnitTemplates())->find($templateId)->units[0]->factor);
    }

    public function testGetTemplateForm_OffersANewVersionOnlyForATemplateALineUsed(): void
    {
        $templateId = $this->template('Cartons');

        $this->actAsManager();
        $this->get('/stock_units/templateForm/' . $templateId)->assertDontSee('as_new_version');

        $this->markUsed($templateId);

        $result = $this->get('/stock_units/templateForm/' . $templateId);

        $result->assertSee('as_new_version');
        $result->assertSee(lang('Stock_units.used_note'));
    }

    public function testPostSetActive_RefusesATemplateThatDoesNotExistWithItsMessage(): void
    {
        $this->switchOnStock('units');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/setActive/999999999', ['active' => '0']);

        $this->assertFalse($response['success']);
        $this->assertSame($this->messageOf(AccountingError::UnitTemplateNotFound), $response['message']);
    }

    public function testPostAddBarcode_GivesAUnitABarcodeAndPostRemoveBarcodeTakesItAway(): void
    {
        $setup = $this->cartonItem('5.00');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/addBarcode/' . $setup['item'], ['barcode' => 'CTN-777', 'unit_id' => (string) $setup['carton']]);

        $this->assertTrue($response['success']);
        $this->assertNotNull((new UnitBarcodes())->resolve('CTN-777'));
        $this->assertTrue($this->postJson('/stock_units/removeBarcode/' . $response['id'])['success']);
        $this->assertNull((new UnitBarcodes())->resolve('CTN-777'));
    }

    public function testGetIndex_OpensTheProfileOfAnItemFoundByItsNumber(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->accountingDb()->table('items')->where('item_id', $setup['item'])->update(['item_number' => 'FIND-ME-1']);
        $this->actAsManager();

        $result = $this->get('/stock_units?item=FIND-ME-1');

        $result->assertOK();
        $result->assertSee('item_profile_form');
        $result->assertSee('barcode_form');
    }

    public function testGetIndex_ClosesTheTrackingChoiceToWhoMayNotManageTracking(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->switchOnStock('tracking');
        $this->accountingDb()->table('items')->where('item_id', $setup['item'])->update(['item_number' => 'CLOSE-1']);
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $body = $this->get('/stock_units?item=CLOSE-1')->getBody();

        $this->assertMatchesRegularExpression('/<select[^>]*id="tracking_type"[^>]*disabled/', $body);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*id="unit_template_id"[^>]*disabled/', $body);
    }

    public function testGetIndex_ClosesTheTemplateChoiceWhileTheUnitsSettingIsOff(): void
    {
        $itemId = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['item_number' => 'CLOSE-2']);
        $this->switchOnStock('tracking');
        $this->actAsManager();

        $body = $this->get('/stock_units?item=CLOSE-2')->getBody();

        $this->assertMatchesRegularExpression('/<select[^>]*id="unit_template_id"[^>]*disabled/', $body);
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*id="tracking_type"[^>]*disabled/', $body);
    }

    public function testGetIndex_SaysSoWhenNoItemMatches(): void
    {
        $this->actAsManager();

        $this->get('/stock_units?item=NO-SUCH-ITEM')->assertSee(lang('Stock_units.item_not_found'));
    }
}
