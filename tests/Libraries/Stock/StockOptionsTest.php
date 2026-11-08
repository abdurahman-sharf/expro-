<?php

namespace Tests\Libraries\Stock;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Stock\NegativeStockOption;
use App\Libraries\Stock\StockOptions;
use App\Libraries\Stock\TrackingType;
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
final class StockOptionsTest extends CIUnitTestCase
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

    public function testAll_StartsWithEverythingOffAndNegativeStockAllowed(): void
    {
        $this->assertSame(['units' => false, 'bundles' => false, 'tracking' => false, 'expired_refused' => true, 'negative_stock' => 'allow'], (new StockOptions())->all());
    }

    public function testUpdate_RequiresTheAccountingSettingsPermission(): void
    {
        $stranger = $this->employeeWithGrants([]);

        try {
            (new StockOptions())->update(['units' => true], $stranger);
            $this->fail('Whoever may not change the settings must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::PermissionDenied, $exception->error);
        }

        $this->assertFalse((new StockOptions())->unitsEnabled());
    }

    public function testUpdate_SwitchesEachOptionAndWritesWhatItWasToTheAuditLog(): void
    {
        $options = new StockOptions();

        $options->update(['units' => true, 'bundles' => true, 'tracking' => true, 'expired_refused' => false, 'negative_stock' => 'refuse_below_available'], $this->unitManagerId);

        $this->assertSame(['units' => true, 'bundles' => true, 'tracking' => true, 'expired_refused' => false, 'negative_stock' => 'refuse_below_available'], $options->all());
        $this->assertSame(NegativeStockOption::RefuseAvailable, $options->negativeStock());

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'stock_options_updated')->get()->getRow();

        $this->assertNotNull($audit);
        $this->assertStringContainsString('"before"', (string) $audit->details);
    }

    public function testUpdate_WritesNothingWhenNothingChanges(): void
    {
        (new StockOptions())->update(['units' => false, 'negative_stock' => 'allow'], $this->unitManagerId);

        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'stock_options_updated')->countAllResults());
    }

    public function testUpdate_RejectsAnOptionOfNegativeStockThatIsNotOneOfTheThree(): void
    {
        try {
            (new StockOptions())->update(['negative_stock' => 'sometimes'], $this->unitManagerId);
            $this->fail('The option must be refused.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::InvalidName, $exception->error);
        }

        $this->assertSame(NegativeStockOption::Allow, (new StockOptions())->negativeStock());
    }

    public function testNegativeStock_FallsBackToAllowWhenTheStoredValueIsUnknown(): void
    {
        $this->setConfigValue(StockOptions::NEGATIVE_STOCK, 'garbage');

        $this->assertSame(NegativeStockOption::Allow, (new StockOptions())->negativeStock());
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

    private function holdSerial(int $itemId): void
    {
        $this->putOnShelf($itemId, '1');
        $this->accountingDb()->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'OPT-1', 'state' => 'in_stock', 'location_id' => 1]);
    }

    public function testUpdate_RejectsSwitchingTrackingOffWhileASerialItemHoldsStock(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->holdSerial($itemId);

        $this->refusedWith(AccountingError::TrackingOffWithStock, fn () => (new StockOptions())->update(['tracking' => false], $this->unitManagerId));
        $this->assertTrue((new StockOptions())->trackingEnabled());
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('action', 'stock_options_updated')->countAllResults());
    }

    public function testUpdate_RejectsSwitchingTrackingOffWhileABatchHoldsStock(): void
    {
        $itemId = $this->trackedItem(TrackingType::Batch);
        $db     = $this->accountingDb();

        $db->table('stock_batches')->insert(['item_id' => $itemId, 'batch_number' => 'OPT-B', 'expiry_date' => '2031-01-01']);
        $db->table('stock_batch_quantities')->insert(['batch_id' => (int) $db->insertID(), 'location_id' => 1, 'quantity' => '4']);
        $this->putOnShelf($itemId, '4');

        $this->refusedWith(AccountingError::TrackingOffWithStock, fn () => (new StockOptions())->update(['tracking' => false], $this->unitManagerId));
        $this->assertTrue((new StockOptions())->trackingEnabled());
    }

    public function testUpdate_RejectsSwitchingTrackingOffWhileTheShelfOfATrackedItemHoldsStock(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->putOnShelf($itemId, '2');

        $this->refusedWith(AccountingError::TrackingOffWithStock, fn () => (new StockOptions())->update(['tracking' => false], $this->unitManagerId));
        $this->assertTrue((new StockOptions())->trackingEnabled());
    }

    public function testUpdate_AcceptsSwitchingTrackingOffWhenNoTrackedItemHoldsStock(): void
    {
        $this->trackedItem(TrackingType::Serial);

        (new StockOptions())->update(['tracking' => false], $this->unitManagerId);

        $this->assertFalse((new StockOptions())->trackingEnabled());
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'stock_options_updated')->countAllResults());
    }

    public function testUpdate_RejectsSwitchingTrackingOnWhileATrackedItemDoesNotTie(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->setConfigValue(StockOptions::TRACKING, '0');
        $this->putOnShelf($itemId, '3');

        $this->refusedWith(AccountingError::TrackingOnUntied, fn () => (new StockOptions())->update(['tracking' => true], $this->unitManagerId));
        $this->assertFalse((new StockOptions())->trackingEnabled());
    }

    public function testUpdate_AcceptsSwitchingTrackingOnWhenEveryTrackedItemTies(): void
    {
        $itemId = $this->trackedItem(TrackingType::Serial);

        $this->holdSerial($itemId);
        $this->setConfigValue(StockOptions::TRACKING, '0');

        (new StockOptions())->update(['tracking' => true], $this->unitManagerId);

        $this->assertTrue((new StockOptions())->trackingEnabled());
    }

    public function testUpdate_AcceptsSwitchingTrackingOnWhenNothingIsTracked(): void
    {
        (new StockOptions())->update(['tracking' => true], $this->unitManagerId);

        $this->assertTrue((new StockOptions())->trackingEnabled());
    }
}
