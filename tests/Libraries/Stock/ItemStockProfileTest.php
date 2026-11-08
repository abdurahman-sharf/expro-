<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\ItemStockProfile;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\StockUnitPermissions;
use App\Libraries\Stock\TrackingType;
use App\Libraries\Stock\UnitTemplates;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ShelfFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * @internal
 */
final class ItemStockProfileTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ShelfFixtureTrait;
    use StockUnitsFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private ItemStockProfile $profiles;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startStockUnits();
        $this->profiles = new ItemStockProfile();
    }

    protected function tearDown(): void
    {
        $this->stopStockUnits();
        $this->accountingDb()->table('item_quantities')->where('quantity <>', 0)->update(['quantity' => 0]);
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function refusedWith(AccountingError $error, callable $action): void
    {
        try {
            $action();
            $this->fail('The change should have been refused with ' . $error->value . '.');
        } catch (AccountingException $exception) {
            $this->assertSame($error, $exception->error);
        }
    }

    public function testGet_GivesALegacyItemOneUnitAndNoTracking(): void
    {
        $profile = $this->profiles->get($this->createTestItem());

        $this->assertNull($profile->unitTemplateId);
        $this->assertSame(TrackingType::None, $profile->tracking);
    }

    public function testSetTemplate_NamesTheTemplateOfTheItemAndWritesTheAuditLog(): void
    {
        $itemId     = $this->createTestItem();
        $templateId = $this->template();

        $this->profiles->setTemplate($itemId, $templateId, $this->unitManagerId);

        $this->assertSame($templateId, $this->profiles->get($itemId)->unitTemplateId);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'item_unit_template_set', 'entity_id' => $itemId])->countAllResults());
    }

    public function testSetTemplate_RequiresThePermissionToManageUnits(): void
    {
        $itemId     = $this->createTestItem();
        $templateId = $this->template();

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->profiles->setTemplate($itemId, $templateId, $this->employeeWithGrants([])));
        $this->assertNull($this->profiles->get($itemId)->unitTemplateId);
    }

    public function testSetTemplate_RefusesWhileTheUnitsSettingIsOff(): void
    {
        $itemId = $this->createTestItem();

        $this->refusedWith(AccountingError::UnitsOff, fn () => $this->profiles->setTemplate($itemId, 1, $this->unitManagerId));
    }

    public function testSetTemplate_RejectsATemplateThatDoesNotExistOrIsSwitchedOff(): void
    {
        $itemId     = $this->createTestItem();
        $templateId = $this->template();

        $this->refusedWith(AccountingError::UnitTemplateNotFound, fn () => $this->profiles->setTemplate($itemId, 999999, $this->unitManagerId));

        (new UnitTemplates())->setActive($templateId, false, $this->unitManagerId);

        $this->refusedWith(AccountingError::UnitTemplateNotFound, fn () => $this->profiles->setTemplate($itemId, $templateId, $this->unitManagerId));
    }

    public function testSetTemplate_LetsAnItemKeepATemplateThatWasSwitchedOffAfterItWasGiven(): void
    {
        $itemId     = $this->createTestItem();
        $templateId = $this->template();

        $this->profiles->setTemplate($itemId, $templateId, $this->unitManagerId);
        (new UnitTemplates())->setActive($templateId, false, $this->unitManagerId);
        $this->profiles->setTemplate($itemId, $templateId, $this->unitManagerId);

        $this->assertSame($templateId, $this->profiles->get($itemId)->unitTemplateId);
    }

    public function testSetTemplate_TakesTheTemplateAwayWithANullTemplate(): void
    {
        $itemId = $this->createTestItem();

        $this->profiles->setTemplate($itemId, $this->template(), $this->unitManagerId);
        $this->profiles->setTemplate($itemId, null, $this->unitManagerId);

        $this->assertNull($this->profiles->get($itemId)->unitTemplateId);
    }

    public function testSetTracking_MakesAnItemWithNoStockSerialOrBatchTracked(): void
    {
        $itemId = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->profiles->setTracking($itemId, TrackingType::Serial, $this->unitManagerId);

        $this->assertSame(TrackingType::Serial, $this->profiles->get($itemId)->tracking);
        $this->assertSame(TrackingType::Serial, $this->profiles->trackingOf($itemId));

        $this->profiles->setTracking($itemId, TrackingType::Batch, $this->unitManagerId);

        $this->assertSame(TrackingType::Batch, $this->profiles->get($itemId)->tracking);
    }

    public function testSetTracking_RequiresThePermissionToManageTracking(): void
    {
        $itemId = $this->createTestItem();

        $this->switchOnStock('tracking');

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->profiles->setTracking($itemId, TrackingType::Serial, $this->employeeWithGrants([])));
        $this->assertSame(TrackingType::None, $this->profiles->get($itemId)->tracking);
    }

    public function testSetTracking_RefusesWhileTheTrackingSettingIsOff(): void
    {
        $this->refusedWith(AccountingError::TrackingOff, fn () => $this->profiles->setTracking($this->createTestItem(), TrackingType::Serial, $this->unitManagerId));
    }

    public function testSetTracking_RejectsAnItemThatHoldsStock(): void
    {
        $itemId = $this->createTestItem();

        $this->putOnShelf($itemId, '3');
        $this->switchOnStock('tracking');

        $this->refusedWith(AccountingError::TrackingChangeWithStock, fn () => $this->profiles->setTracking($itemId, TrackingType::Batch, $this->unitManagerId));
        $this->assertSame(TrackingType::None, $this->profiles->get($itemId)->tracking);
    }

    public function testSetTracking_RejectsAnItemThatKeepsNoStock(): void
    {
        $this->switchOnStock('tracking');

        $this->refusedWith(AccountingError::TrackingChangeWithStock, fn () => $this->profiles->setTracking($this->createTestItem(HAS_NO_STOCK), TrackingType::Serial, $this->unitManagerId));
    }

    public function testTrackingOf_ReturnsNoneWhileTheTrackingSettingIsOff(): void
    {
        $itemId = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->profiles->setTracking($itemId, TrackingType::Serial, $this->unitManagerId);
        $this->setConfigValue(StockOptions::TRACKING, '0');

        $this->assertSame(TrackingType::None, $this->profiles->trackingOf($itemId));
    }

    public function testHoldsStock_SeesAShelfAnOnHandSerialAndABatch(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $this->assertFalse($this->profiles->holdsStock($itemId));

        $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S1', 'state' => 'in_stock', 'location_id' => 1]);
        $this->assertTrue($this->profiles->holdsStock($itemId));

        $db->table('stock_serials')->where('item_id', $itemId)->update(['state' => 'sold', 'location_id' => null]);
        $this->assertFalse($this->profiles->holdsStock($itemId), 'a sold serial is not on hand');

        $db->table('stock_batches')->insert(['item_id' => $itemId, 'batch_number' => 'B1', 'expiry_date' => '2030-01-01']);
        $batchId = (int) $db->insertID();
        $db->table('stock_batch_quantities')->insert(['batch_id' => $batchId, 'location_id' => 1, 'quantity' => '2']);
        $this->assertTrue($this->profiles->holdsStock($itemId));
    }

    public function testSaveProfile_SavesTheTemplateAndTheTrackingTogetherAndWritesTheAuditLog(): void
    {
        $templateId = $this->template();
        $itemId     = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->profiles->saveProfile($itemId, true, $templateId, TrackingType::Serial, $this->unitManagerId);

        $profile = $this->profiles->get($itemId);

        $this->assertSame([$templateId, TrackingType::Serial], [$profile->unitTemplateId, $profile->tracking]);
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'item_stock_profile', 'action' => 'item_unit_template_set'])->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'item_stock_profile', 'action' => 'item_tracking_set'])->countAllResults());
    }

    public function testSaveProfile_AsksForNoPermissionAndNoSettingWhenNothingChanges(): void
    {
        $itemId = $this->createTestItem();

        $this->profiles->saveProfile($itemId, true, null, TrackingType::None, $this->employeeWithGrants([]));

        $this->assertSame(TrackingType::None, $this->profiles->get($itemId)->tracking);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'item_stock_profile')->countAllResults());
    }

    public function testSaveProfile_SavesTheTrackingWhileTheUnitsSettingIsOff(): void
    {
        $itemId = $this->createTestItem();

        $this->switchOnStock('tracking');
        $this->profiles->saveProfile($itemId, true, null, TrackingType::Batch, $this->unitManagerId);

        $this->assertSame(TrackingType::Batch, $this->profiles->get($itemId)->tracking);
    }

    public function testSaveProfile_SavesTheTemplateWhileTheTrackingSettingIsOff(): void
    {
        $templateId = $this->template();
        $itemId     = $this->createTestItem();

        $this->profiles->saveProfile($itemId, true, $templateId, TrackingType::None, $this->unitManagerId);

        $this->assertSame($templateId, $this->profiles->get($itemId)->unitTemplateId);
    }

    public function testSaveProfile_LeavesTheTemplateAloneWhenNoneWasPosted(): void
    {
        $templateId = $this->template();
        $itemId     = $this->createTestItem();

        $this->profiles->setTemplate($itemId, $templateId, $this->unitManagerId);
        $this->switchOnStock('tracking');
        $this->profiles->saveProfile($itemId, false, null, TrackingType::Serial, $this->unitManagerId);

        $profile = $this->profiles->get($itemId);

        $this->assertSame([$templateId, TrackingType::Serial], [$profile->unitTemplateId, $profile->tracking]);
    }

    public function testSaveProfile_SavesNothingWhenTheTrackingChangeIsRefused(): void
    {
        $templateId = $this->template();
        $itemId     = $this->createTestItem();

        $this->putOnShelf($itemId, '3');
        $this->switchOnStock('tracking');

        $this->refusedWith(AccountingError::TrackingChangeWithStock, fn () => $this->profiles->saveProfile($itemId, true, $templateId, TrackingType::Serial, $this->unitManagerId));

        $profile = $this->profiles->get($itemId);

        $this->assertSame([null, TrackingType::None], [$profile->unitTemplateId, $profile->tracking]);
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'item_stock_profile', 'action' => 'item_unit_template_set'])->countAllResults());
    }

    public function testSaveProfile_RequiresThePermissionOfWhatChanges(): void
    {
        $templateId = $this->template();
        $itemId     = $this->createTestItem();
        $trackOnly  = $this->employeeWithGrants([StockUnitPermissions::TRACKING_MANAGE]);

        $this->switchOnStock('tracking');

        $this->refusedWith(AccountingError::PermissionDenied, fn () => $this->profiles->saveProfile($itemId, true, $templateId, TrackingType::None, $trackOnly));
        $this->profiles->saveProfile($itemId, true, null, TrackingType::Serial, $trackOnly);

        $this->assertSame(TrackingType::Serial, $this->profiles->get($itemId)->tracking);
    }
}
