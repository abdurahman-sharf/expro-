<?php

namespace Tests\Database;

use App\Database\Migrations\ProductMasterData;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class ProductMasterDataMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /** @var list<int> */
    private array $itemIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new ProductMasterData())->up();

        $db = $this->accountingDb();
        $db->table('items')->update(['brand_id' => null, 'status' => 0, 'supplier_code' => null]);

        foreach ($this->itemIds as $itemId) {
            $db->table('item_quantities')->where('item_id', $itemId)->delete();
            $db->table('items')->where('item_id', $itemId)->delete();
        }

        $this->itemIds = [];

        $db->query('DELETE FROM ' . $db->prefixTable('item_brands'));

        parent::tearDown();
    }

    /**
     * @return array{type: string, nullable: string, default: ?string}|null
     */
    private function column(string $column): ?array
    {
        $row = $this->accountingDb()->query(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('items'), $column],
        )->getRow();

        if ($row === null) {
            return null;
        }

        return ['type' => (string) preg_replace('/^((?:small|medium|big|tiny)?int)\(\d+\)/', '$1', strtolower((string) $row->type)), 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : (string) $row->dflt];
    }

    public function testUp_AddsColumnsThatChangeNothingForAnItemThatExists(): void
    {
        $this->assertSame(['type' => 'int unsigned', 'nullable' => 'YES', 'default' => null], $this->column('brand_id'));
        $this->assertSame(['type' => 'tinyint', 'nullable' => 'NO', 'default' => '0'], $this->column('status'));
        $this->assertSame(['type' => 'varchar(100)', 'nullable' => 'YES', 'default' => null], $this->column('supplier_code'));
    }

    public function testUp_LeavesAnItemThatExistsActiveWithNoBrandAndNoSupplierCodeAndItsOtherColumnsAsTheyWere(): void
    {
        $migration = new ProductMasterData();
        $migration->down();

        $itemId = $this->itemIds[] = $this->createTestItem();
        $before = $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRowArray();

        $migration->up();

        $after = $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRowArray();

        $this->assertSame([null, '0', null], [$after['brand_id'], (string) $after['status'], $after['supplier_code']]);
        $this->assertSame($before, array_diff_key($after, array_flip(['brand_id', 'status', 'supplier_code'])));
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new ProductMasterData())->up();
        (new ProductMasterData())->up();

        $this->assertNotNull($this->column('status'));
    }

    public function testUp_RejectsAStatusOutsideTheThreeAndABrandThatDoesNotExistAtTheDatabase(): void
    {
        $itemId = $this->itemIds[] = $this->createTestItem();

        foreach ([['status', 3], ['brand_id', 987654]] as [$column, $value]) {
            $accepted = false;

            try {
                $accepted = $this->accountingDb()->table('items')->where('item_id', $itemId)->update([$column => $value]) !== false;
            } catch (Throwable) {
            }

            $this->assertFalse($accepted, "The database accepted {$value} for {$column}");
        }
    }

    public function testUp_AcceptsEachOfTheThreeStatuses(): void
    {
        $itemId = $this->itemIds[] = $this->createTestItem();

        foreach ([0, 1, 2] as $status) {
            $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['status' => $status]);

            $this->assertSame((string) $status, (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->status);
        }
    }

    public function testDown_RemovesEverythingWhenNothingWasEntered(): void
    {
        (new ProductMasterData())->down();

        $this->assertNull($this->column('brand_id'));
        $this->assertNull($this->column('status'));
        $this->assertNull($this->column('supplier_code'));
        $this->assertSame(0, (int) $this->accountingDb()->query('SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable('item_brands')])->getRow()->n);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function enteredProvider(): array
    {
        return ['a status' => ['status', 2], 'a supplier code' => ['supplier_code', 'SUP-1']];
    }

    #[DataProvider('enteredProvider')]
    public function testDown_RejectsOnceAnItemCarriesAValue(string $column, mixed $value): void
    {
        $itemId = $this->itemIds[] = $this->createTestItem();
        $this->accountingDb()->table('items')->where('item_id', $itemId)->update([$column => $value]);

        $this->expectException(RuntimeException::class);

        (new ProductMasterData())->down();
    }

    public function testDown_RejectsOnceAnItemCarriesABrandAndWhileABrandExists(): void
    {
        $db = $this->accountingDb();
        $db->table('item_brands')->insert(['name' => 'Acme']);
        $brandId = (int) $db->insertID();

        try {
            (new ProductMasterData())->down();
            $this->fail('down() dropped a brand that exists.');
        } catch (RuntimeException $refusal) {
            $this->assertNotNull($this->column('brand_id'));
        }

        $db->query('DELETE FROM ' . $db->prefixTable('item_brands'));
        $itemId = $this->itemIds[] = $this->createTestItem();
        $db->table('item_brands')->insert(['brand_id' => $brandId, 'name' => 'Acme']);
        $db->table('items')->where('item_id', $itemId)->update(['brand_id' => $brandId]);
        $db->table('items')->update(['brand_id' => null]);
        $db->table('items')->where('item_id', $itemId)->update(['brand_id' => $brandId]);

        $this->expectException(RuntimeException::class);

        (new ProductMasterData())->down();
    }
}
