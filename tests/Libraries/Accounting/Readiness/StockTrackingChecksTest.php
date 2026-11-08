<?php

namespace Tests\Libraries\Accounting\Readiness;

use App\Libraries\Accounting\Readiness\CheckResult;
use App\Libraries\Accounting\Readiness\CheckStatus;
use App\Libraries\Accounting\Readiness\StockTrackingChecks;
use App\Libraries\Stock\TrackingType;
use App\Models\Item_kit;
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
final class StockTrackingChecksTest extends CIUnitTestCase
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
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('item_kit_items')->truncate();
        $db->table('item_kits')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $db->table('item_quantities')->where('quantity <>', 0)->update(['quantity' => 0]);

        $this->stopStockUnits();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    /**
     * @return array{CheckResult, CheckResult}
     */
    private function results(): array
    {
        $results = (new StockTrackingChecks($this->accountingDb()))->run();

        $this->assertSame(['stock_tracking', 'stock_bundles'], array_map(static fn (CheckResult $result): string => $result->id, $results));
        $this->assertSame(['WP42-TRACKING', 'WP42-BUNDLES'], array_map(static fn (CheckResult $result): string => $result->ref, $results));

        return [$results[0], $results[1]];
    }

    private function stockedBatch(string $number, string $expiry, string $quantity, int $itemId): void
    {
        $db = $this->accountingDb();

        $db->table('stock_batches')->insert(['item_id' => $itemId, 'batch_number' => $number, 'expiry_date' => $expiry]);
        $db->table('stock_batch_quantities')->insert(['batch_id' => (int) $db->insertID(), 'location_id' => 1, 'quantity' => $quantity]);
    }

    public function testRun_PassesWhenNoItemIsTrackedAndNoKitBreaksTheRules(): void
    {
        [$tracking, $bundles] = $this->results();

        $this->assertSame(CheckStatus::Pass, $tracking->status);
        $this->assertSame(CheckStatus::Pass, $bundles->status);
    }

    public function testRun_PassesForTrackedItemsWhoseShelvesEqualTheirBatchesAndSerialNumbers(): void
    {
        $batch  = $this->trackedItem(TrackingType::Batch);
        $serial = $this->trackedItem(TrackingType::Serial);

        $this->putOnShelf($batch, '4');
        $this->stockedBatch('B-1', date('Y-m-d', strtotime('+30 days')), '4', $batch);
        $this->putOnShelf($serial, '1');
        $this->accountingDb()->table('stock_serials')->insert(['item_id' => $serial, 'serial_number' => 'S-1', 'state' => 'in_stock', 'location_id' => 1]);

        [$tracking] = $this->results();

        $this->assertSame(CheckStatus::Pass, $tracking->status);
        $this->assertSame(2, $tracking->data['tracked_items']);
    }

    public function testRun_FailsAndNamesAnItemWhoseShelfIsNotWhatItsBatchesAddUpTo(): void
    {
        $batch = $this->trackedItem(TrackingType::Batch);

        $this->putOnShelf($batch, '9');
        $this->stockedBatch('B-2', date('Y-m-d', strtotime('+30 days')), '4', $batch);

        [$tracking] = $this->results();

        $this->assertSame(CheckStatus::Fail, $tracking->status);
        $this->assertSame(1, $tracking->data['untied']);
        $this->assertStringContainsString('9.000', $tracking->findings[0]);
        $this->assertStringContainsString('4.000', $tracking->findings[0]);
    }

    public function testRun_FailsForAShelfOfASerialItemThatNoSerialNumberAccountsFor(): void
    {
        $serial = $this->trackedItem(TrackingType::Serial);

        $this->putOnShelf($serial, '2');

        [$tracking] = $this->results();

        $this->assertSame(CheckStatus::Fail, $tracking->status);
    }

    public function testRun_ReviewsAnExpiredBatchThatStillStandsOnAShelf(): void
    {
        $batch = $this->trackedItem(TrackingType::Batch);

        $this->putOnShelf($batch, '3');
        $this->stockedBatch('B-OLD', date('Y-m-d', strtotime('-3 days')), '3', $batch);

        [$tracking] = $this->results();

        $this->assertSame(CheckStatus::Review, $tracking->status);
        $this->assertSame(1, $tracking->data['expired_on_hand']);
    }

    public function testRun_ReviewsAKitThatBreaksTheRulesOfABundleOnlyWhileTheSettingIsOn(): void
    {
        $product = $this->createTestItem(HAS_STOCK);
        $data    = ['name' => 'Bad kit', 'item_id' => $product, 'kit_discount' => 0, 'kit_discount_type' => 0, 'price_option' => PRICE_OPTION_ALL, 'print_option' => 0, 'description' => ''];

        $this->assertTrue(model(Item_kit::class)->save_value($data));
        $this->assertSame(CheckStatus::Pass, $this->results()[1]->status);

        $this->switchOnStock('bundles');

        [, $bundles] = $this->results();

        $this->assertSame(CheckStatus::Review, $bundles->status);
        $this->assertSame(1, $bundles->data['kits_off_the_rules']);
    }

    public function testRun_ReviewsLinesEnteredInUnitsWhileTheUnitsSettingIsOff(): void
    {
        $setup = $this->cartonItem('5.00');

        $this->accountingDb()->table('stock_permits')->insert(['company_id' => 1, 'branch_id' => 1, 'permit_type' => 'add', 'permit_date' => date('Y-m-d'), 'location_id' => 1]);
        $permitId = (int) $this->accountingDb()->insertID();
        $this->accountingDb()->table('stock_permit_lines')->insert(['permit_id' => $permitId, 'line_no' => 1, 'item_id' => $setup['item'], 'quantity' => '24', 'unit_cost' => '5', 'unit_id' => $setup['carton'], 'unit_factor' => '12', 'unit_quantity' => '2', 'unit_cost_entered' => '60']);
        $this->setConfigValue('stock_units_enabled', '0');

        [, $bundles] = $this->results();

        $this->assertSame(CheckStatus::Review, $bundles->status);
        $this->assertSame(1, $bundles->data['lines_in_units']);

        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 0');
        $this->accountingDb()->table('stock_permit_lines')->truncate();
        $this->accountingDb()->table('stock_permits')->truncate();
        $this->accountingDb()->query('SET FOREIGN_KEY_CHECKS = 1');
    }
}
