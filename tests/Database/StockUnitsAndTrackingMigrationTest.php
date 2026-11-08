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
    private const GHOST       = 999000001;

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

        foreach (['sales_items' => 'sale_id', 'receivings_items' => 'receiving_id', 'stock_permit_lines' => 'permit_id'] as $table => $key) {
            $this->accountingDb()->table($table)->where($key, self::GHOST)->delete();
        }

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

    /**
     * @param array<string, mixed> $row
     */
    private function insertWithoutParents(string $table, array $row): void
    {
        $db = $this->accountingDb();

        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        try {
            $db->table($table)->insert($row);
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private function saleLine(array $more = []): array
    {
        return [...['sale_id' => self::GHOST, 'item_id' => self::GHOST, 'line' => 1, 'item_cost_price' => '0', 'item_unit_price' => '10.00', 'item_location' => 1, 'quantity_purchased' => '24.000', 'unit_id' => 1, 'unit_factor' => '12.0000', 'unit_quantity' => '2.000', 'unit_price_entered' => '120.000000'], ...$more];
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private function receivingLine(array $more = []): array
    {
        return [...['receiving_id' => self::GHOST, 'item_id' => self::GHOST, 'line' => 1, 'item_cost_price' => '0', 'item_unit_price' => '10.00', 'item_location' => 1, 'quantity_purchased' => '24.000', 'receiving_quantity' => '1.000', 'unit_id' => 1, 'unit_factor' => '12.0000', 'unit_quantity' => '2.000', 'unit_price_entered' => '120.000000'], ...$more];
    }

    /**
     * @param array<string, mixed> $more
     *
     * @return array<string, mixed>
     */
    private function permitLine(array $more = []): array
    {
        return [...['permit_id' => self::GHOST, 'line_no' => 1, 'item_id' => self::GHOST, 'quantity' => '24.000', 'unit_cost' => '0', 'unit_id' => 1, 'unit_factor' => '12.0000', 'unit_quantity' => '2.000', 'unit_cost_entered' => '0'], ...$more];
    }

    public function testUp_CreatesTheEightTablesAndTheUnitColumnsOfTheThreeLineTables(): void
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

    public function testUp_RegistersTheModuleAndThePermissionsWithoutGrantingThem(): void
    {
        $db = $this->accountingDb();

        $this->assertSame('module_stock_units', $db->table('modules')->where('module_id', 'stock_units')->get()->getRow()->name_lang_key);
        $this->assertSame(4, $db->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
    }

    public function testUp_RunsTwiceWithoutAnError(): void
    {
        (new StockUnitsAndTracking())->up();
        (new StockUnitsAndTracking())->up();

        $this->assertSame(4, $this->accountingDb()->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
    }

    public function testUp_LeavesAnExistingSaleLineWithoutAUnit(): void
    {
        $this->assertSame(0, $this->accountingDb()->table('sales_items')->where('unit_id IS NOT NULL', null, false)->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('receivings_items')->where('unit_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testUnitTemplateUnits_RejectsAFactorOfZeroOrLess(): void
    {
        $db = $this->accountingDb();

        $db->table('unit_templates')->insert(['company_id' => 1, 'name' => 'T', 'main_unit_name' => 'p', 'main_small_name' => 'p']);
        $templateId = (int) $db->insertID();

        $this->refused(static fn () => $db->table('unit_template_units')->insert(['template_id' => $templateId, 'name' => 'Z', 'factor' => '0', 'small_name' => 'z']));
        $this->refused(static fn () => $db->table('unit_template_units')->insert(['template_id' => $templateId, 'name' => 'N', 'factor' => '-1', 'small_name' => 'n']));
    }

    public function testItemStockProfiles_RejectsAnUnknownTrackingType(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $this->refused(static fn () => $db->table('item_stock_profiles')->insert(['item_id' => $itemId, 'tracking_type' => 'lot']));
    }

    public function testStockSerials_RejectsTheSameSerialNumberTwiceForAnItemAndAnUnknownState(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-1', 'state' => 'in_stock', 'location_id' => 1]);

        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-1', 'state' => 'in_stock', 'location_id' => 1]));
        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-2', 'state' => 'lost', 'location_id' => 1]));
        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-3', 'state' => 'in_stock', 'location_id' => null]));
        $this->refused(static fn () => $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'S-4', 'state' => 'sold', 'location_id' => 1]));
    }

    public function testStockBatchQuantities_RejectsANegativeQuantity(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $db->table('stock_batches')->insert(['item_id' => $itemId, 'batch_number' => 'B-1', 'expiry_date' => '2031-01-01']);
        $batchId = (int) $db->insertID();

        $this->refused(static fn () => $db->table('stock_batch_quantities')->insert(['batch_id' => $batchId, 'location_id' => 1, 'quantity' => '-1']));
        $this->refused(static fn () => $db->table('stock_batches')->insert(['item_id' => $itemId, 'batch_number' => 'B-1', 'expiry_date' => '2032-01-01']));
    }

    public function testStockTrackingLines_RequiresExactlyOneOfASerialAndABatch(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $this->refused(static fn () => $db->table('stock_tracking_lines')->insert(['source_type' => 'sale', 'source_id' => 1, 'line_no' => 1, 'movement' => 'sale', 'item_id' => $itemId, 'location_id' => 1, 'quantity' => '-1']));
    }

    public function testDown_RefusesOnceTrackingRecordsExist(): void
    {
        $db     = $this->accountingDb();
        $itemId = $this->createTestItem();

        $db->table('stock_serials')->insert(['item_id' => $itemId, 'serial_number' => 'D-1', 'state' => 'in_stock', 'location_id' => 1]);

        $this->expectException(RuntimeException::class);

        (new StockUnitsAndTracking())->down();
    }

    public function testSalesItems_RejectsALineWhoseBaseQuantityIsNotItsUnitQuantityTimesTheFactor(): void
    {
        $this->refused(fn () => $this->insertWithoutParents('sales_items', $this->saleLine(['quantity_purchased' => '25.000'])));
        $this->refused(fn () => $this->insertWithoutParents('sales_items', $this->saleLine(['unit_factor' => '0'])));
        $this->refused(fn () => $this->insertWithoutParents('sales_items', $this->saleLine(['quantity_purchased' => '0.000', 'unit_factor' => '0', 'unit_quantity' => '5.000'])));
        $this->refused(fn () => $this->insertWithoutParents('sales_items', $this->saleLine(['quantity_purchased' => '0.000', 'unit_factor' => '-1', 'unit_quantity' => '0.000'])));
        $this->refused(fn () => $this->insertWithoutParents('sales_items', $this->saleLine(['unit_quantity' => null])));

        $this->insertWithoutParents('sales_items', $this->saleLine());

        $this->assertSame(1, $this->accountingDb()->table('sales_items')->where('sale_id', self::GHOST)->countAllResults());
    }

    public function testSalesItems_AcceptsALineWithNoUnitWhateverItsQuantity(): void
    {
        $this->insertWithoutParents('sales_items', $this->saleLine(['quantity_purchased' => '7.000', 'unit_id' => null, 'unit_factor' => null, 'unit_quantity' => null, 'unit_price_entered' => null]));

        $this->assertSame(1, $this->accountingDb()->table('sales_items')->where('sale_id', self::GHOST)->countAllResults());
    }

    public function testReceivingsItems_RejectsALineWhoseBaseQuantityIsNotItsUnitQuantityTimesTheFactor(): void
    {
        $this->refused(fn () => $this->insertWithoutParents('receivings_items', $this->receivingLine(['quantity_purchased' => '25.000'])));
        $this->refused(fn () => $this->insertWithoutParents('receivings_items', $this->receivingLine(['quantity_purchased' => '2.000', 'receiving_quantity' => '1.000'])));

        $this->insertWithoutParents('receivings_items', $this->receivingLine(['quantity_purchased' => '2.000', 'receiving_quantity' => '12.000']));

        $this->assertSame(1, $this->accountingDb()->table('receivings_items')->where('receiving_id', self::GHOST)->countAllResults());
    }

    public function testStockPermitLines_RejectsALineWhoseBaseQuantityIsNotItsUnitQuantityTimesTheFactor(): void
    {
        $this->refused(fn () => $this->insertWithoutParents('stock_permit_lines', $this->permitLine(['quantity' => '25.000'])));
        $this->refused(fn () => $this->insertWithoutParents('stock_permit_lines', $this->permitLine(['unit_factor' => '-12.0000'])));

        $this->insertWithoutParents('stock_permit_lines', $this->permitLine());

        $this->assertSame(1, $this->accountingDb()->table('stock_permit_lines')->where('permit_id', self::GHOST)->countAllResults());
    }

    public function testDown_RemovesTheTablesTheUnitColumnsAndThePermissionsWhenNothingWasRecorded(): void
    {
        (new StockUnitsAndTracking())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        foreach (self::LINE_TABLES as $table) {
            $this->assertFalse($this->hasColumn($table, 'unit_id'), $table);
        }

        $this->assertSame(0, $this->accountingDb()->table('permissions')->whereIn('permission_id', self::PERMISSIONS)->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', 'stock_units')->countAllResults());
    }

    public function testDown_RefusesWhileALineWasEnteredInAUnitAndDropsNothing(): void
    {
        $this->insertWithoutParents('sales_items', $this->saleLine());

        try {
            (new StockUnitsAndTracking())->down();
            $this->fail('A line in a unit must stop the roll back.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $this->assertTrue($this->hasColumn('sales_items', 'unit_id'));
    }
}

