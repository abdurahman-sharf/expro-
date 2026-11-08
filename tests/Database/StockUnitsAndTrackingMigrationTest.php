<?php

namespace Tests\Database;

use App\Database\Migrations\StockUnitsAndTracking;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\StockUnitsFixtureTrait;

/**
 * @internal
 */
final class StockUnitsAndTrackingMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use StockUnitsFixtureTrait;

    private const TABLES      = ['unit_templates', 'unit_template_units', 'item_stock_profiles', 'item_unit_barcodes', 'stock_serials', 'stock_batches', 'stock_batch_quantities', 'stock_tracking_lines'];
    private const LINE_TABLES = ['sales_items', 'receivings_items', 'stock_permit_lines'];
    private const PERMISSIONS = ['stock_units', 'stk_units_manage', 'stk_tracking_manage', 'stk_batch_manual_pick'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new StockUnitsAndTracking())->up();
        $this->stopStockUnits();
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->accountingDb()->prefixTable($table), $column])->getRow()->found > 0;
    }

    private function refused(callable $insert): void
    {
        try {
            $insert();
            $this->fail('The insert should have been refused.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testUpCreatesTheEightTablesAndTheUnitColumnsOfTheThreeLineTables(): void
    {
        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        foreach (self::LINE_TABLES as $table) {
            foreach (['unit_id', 'unit_factor', 'unit_quantity'] as $column) {
                $this->assertTrue($this->hasColumn($table, $column), "{$table}.{$column}");
            }
        }

        $this->assertTrue($this->hasColumn('sales_items', 'unit_price_entered'));
        $this->assertTrue($this->hasColumn('receivings_items', 'unit_price_entered'));
        $this->assertTrue($this->hasColumn('stock_permit_lines', 'unit_cost_entered'));
    }

    public function testUpRegistersTheModuleAndThePermissionsWithoutGrantingThem(): void
    {
        $db = $this->accountingDb();

        $this->assertSame('module_stock_units', $db->table('modules')->where('module_id', 'stock_units')->get()->getRow()->name_lang_key);
        $this->assertSame(4, $db->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
    }

    public function testUpRunsTwiceWithoutAnError(): void
    {
        (new StockUnitsAndTracking())->up();
        (new StockUnitsAndTracking())->up();

        $this->assertSame(4, $this->accountingDb()->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
    }

    public function testUpLeavesAnExistingSaleLineWithoutAUnit(): void
    {
        $this->assertSame(0, $this->accountingDb()->table('sales_items')->where('unit_id IS NOT NULL', null, false)->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('receivings_items')->where('unit_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testUnitTemplateUnitsRejectsAFactorOfZeroOrLess(): void
    {
        $db = $this->accountingDb();

        $db->table('unit_templates')->insert(['company_id' => 1, 'name' => 'T', 'main_unit_name' => 'p', 'main_small_name' => 'p']);
        $templateId = (int) $db->insertID();

        $this->refused(static fn () => $db->table('unit_template_units')->insert(['template_id' => $templateId, 'name' => 'Z', 'factor' => '0', 'small_name' => 'z']));
        $this->refused(static fn () => $db->table('unit_template_units')->insert(['template_id' => $templateId, 'name' => 'N', 'factor' => '-1', 'small_name' => 'n']));
    }

    public function testItemStockProfilesRejectsAnUnknownTrackingType(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $this->refused(static fn () => $db->table('item_stock_profiles')->insert(['item_id' => $itemId, 'tracking_type' => 'lot']));
    }

    public function testStockSerialsRejectsTheSameSerialNumberTwiceForAnItemAndAnUnknownState(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-1', 'state' => 'in_stock', 'location_id' => 1]);

        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-1', 'state' => 'in_stock', 'location_id' => 1]));
        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-2', 'state' => 'lost', 'location_id' => 1]));
        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-3', 'state' => 'in_stock', 'location_id' => null]));
        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-4', 'state' => 'sold', 'location_id' => 1]));
    }

    public function testStockBatchQuantitiesRejectsANegativeQuantity(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $db->table('stock_batches')->insert(['item_id' => $itemId, 'batch_number' => 'B-1', 'expiry_date' => '2031-01-01']);
        $batchId = (int) $db->insertID();

        $this->refused(static fn () => $db->table('stock_batch_quantities')->insert(['batch_id' => $batchId, 'location_id' => 1, 'quantity' => '-1']));
        $this->refused(static fn () => $db->table('stock_batches')->insert(['item_id' => $itemId, 'batch_number' => 'B-1', 'expiry_date' => '2032-01-01']));
    }

    public function testStockTrackingLinesNeedsExactlyOneOfASerialAndABatch(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $this->refused(static fn () => $db->table('stock_tracking_lines')->insert(['source_type' => 'sale', 'source_id' => 1, 'line_no' => 1, 'movement' => 'sale', 'item_id' => $itemId, 'location_id' => 1, 'quantity' => '-1']));
    }

    public function testDownRefusesOnceTrackingRecordsExist(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'D-1', 'state' => 'in_stock', 'location_id' => 1]);

        $this->expectException(RuntimeException::class);

        (new StockUnitsAndTracking())->down();
    }
}
