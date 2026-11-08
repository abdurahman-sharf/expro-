<?php

namespace Tests\Controllers;

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

    /**
     * @return array<string, mixed>
     */
    private function form(string $name = 'Boxes'): array
    {
        return ['name' => $name, 'main_unit_name' => 'Piece', 'main_small_name' => 'pc', 'active' => '1', 'unit_name' => ['Box', ''], 'unit_factor' => ['6', ''], 'unit_small_name' => ['bx', '']];
    }

    public function testGetIndexShowsTheSettingsAndTheTemplatesToWhoMayManageThem(): void
    {
        $this->template('Cartons');
        $this->actAsManager();

        $result = $this->get('/stock_units');

        $result->assertOK();
        $result->assertSee('Cartons');
        $result->assertSee('stock_options_form');
        $result->assertSee('template_new');
    }

    public function testGetIndexIsClosedToAnEmployeeWithoutTheModule(): void
    {
        $this->actAs([]);

        $this->assertNotSame(200, $this->get('/stock_units')->response()->getStatusCode());
    }

    public function testGetIndexHidesTheSettingsFromWhoMayNotChangeThem(): void
    {
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $result = $this->get('/stock_units');

        $result->assertOK();
        $result->assertDontSee('stock_options_form');
    }

    public function testPostSettingsSwitchesTheOptionsOnForWhoMayChangeTheSettings(): void
    {
        $this->actAsManager();

        $response = $this->postJson('/stock_units/settings', ['units' => '1', 'tracking' => '1', 'bundles' => '0', 'negative_stock' => 'refuse']);

        $this->assertTrue($response['success']);
        $this->assertSame(['units' => true, 'bundles' => false, 'tracking' => true, 'expired_refused' => true, 'negative_stock' => 'refuse'], (new StockOptions())->all());
    }

    public function testPostSettingsRefusesWhoMayNotChangeTheSettings(): void
    {
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $response = $this->postJson('/stock_units/settings', ['units' => '1']);

        $this->assertFalse($response['success']);
        $this->assertFalse((new StockOptions())->unitsEnabled());
    }

    public function testPostSaveTemplateCreatesATemplateFromTheFormAndSkipsTheEmptyRow(): void
    {
        $this->switchOn('units');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveTemplate/-1', $this->form());

        $this->assertTrue($response['success']);

        $template = (new UnitTemplates())->find((int) $response['id']);

        $this->assertSame(['Boxes', 1], [$template->name, count($template->units)]);
        $this->assertSame('6.0000', $template->units[0]->factor);
    }

    public function testPostSaveTemplateRefusesAFactorThatIsNotPositiveWithAMessage(): void
    {
        $this->switchOn('units');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveTemplate/-1', [...$this->form(), 'unit_factor' => ['0', '']]);

        $this->assertFalse($response['success']);
        $this->assertNotSame('', $response['message']);
        $this->assertSame([], (new UnitTemplates())->all());
    }

    public function testPostSaveTemplateRefusesWhoMayNotManageUnits(): void
    {
        $this->switchOn('units');
        $this->actAs([StockUnitPermissions::MODULE]);

        $this->assertFalse($this->postJson('/stock_units/saveTemplate/-1', $this->form())['success']);
        $this->assertSame([], (new UnitTemplates())->all());
    }

    public function testGetTemplateFormIsForbiddenToWhoMayNotManageUnits(): void
    {
        $this->actAs([StockUnitPermissions::MODULE]);

        $this->assertSame(403, $this->get('/stock_units/templateForm/-1')->response()->getStatusCode());
    }

    public function testPostSetActiveSwitchesATemplateOff(): void
    {
        $templateId = $this->template('Cartons');
        $this->actAsManager();

        $this->assertTrue($this->postJson('/stock_units/setActive/' . $templateId, ['active' => '0'])['success']);
        $this->assertFalse((new UnitTemplates())->find($templateId)->active);
    }

    public function testPostSaveItemGivesAnItemItsTemplateAndItsTracking(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        $this->switchOn('tracking');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => (string) $templateId, 'tracking_type' => 'batch']);

        $profile = (new ItemStockProfile())->get($itemId);

        $this->assertTrue($response['success']);
        $this->assertSame([$templateId, TrackingType::Batch], [$profile->unitTemplateId, $profile->tracking]);
    }

    public function testPostSaveItemRefusesWhoMayNotManageTracking(): void
    {
        $templateId = $this->template('Cartons');
        $itemId     = $this->createTestItem();

        $this->switchOn('tracking');
        $this->actAs([StockUnitPermissions::MODULE, StockUnitPermissions::UNITS_MANAGE]);

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => (string) $templateId, 'tracking_type' => 'serial']);

        $this->assertFalse($response['success']);
        $this->assertSame(TrackingType::None, (new ItemStockProfile())->get($itemId)->tracking);
    }

    public function testPostSaveItemRefusesToChangeTheTrackingOfAnItemThatHoldsStock(): void
    {
        $itemId = $this->createTestItem();

        $this->putOnShelf($itemId, '3');
        $this->switchOn('tracking');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/saveItem/' . $itemId, ['unit_template_id' => '', 'tracking_type' => 'serial']);

        $this->assertFalse($response['success']);
        $this->accountingDb()->table('item_quantities')->where('item_id', $itemId)->delete();
    }

    public function testPostAddBarcodeGivesAUnitABarcodeAndPostRemoveBarcodeTakesItAway(): void
    {
        $setup = $this->cartonItem('5.00');
        $this->actAsManager();

        $response = $this->postJson('/stock_units/addBarcode/' . $setup['item'], ['barcode' => 'CTN-777', 'unit_id' => (string) $setup['carton']]);

        $this->assertTrue($response['success']);
        $this->assertNotNull((new UnitBarcodes())->resolve('CTN-777'));
        $this->assertTrue($this->postJson('/stock_units/removeBarcode/' . $response['id'])['success']);
        $this->assertNull((new UnitBarcodes())->resolve('CTN-777'));
    }

    public function testGetIndexOpensTheProfileOfAnItemFoundByItsNumber(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->accountingDb()->table('items')->where('item_id', $setup['item'])->update(['item_number' => 'FIND-ME-1']);
        $this->actAsManager();

        $result = $this->get('/stock_units?item=FIND-ME-1');

        $result->assertOK();
        $result->assertSee('item_profile_form');
        $result->assertSee('barcode_form');
    }

    public function testGetIndexSaysSoWhenNoItemMatches(): void
    {
        $this->actAsManager();

        $this->get('/stock_units?item=NO-SUCH-ITEM')->assertSee(lang('Stock_units.item_not_found'));
    }
}
