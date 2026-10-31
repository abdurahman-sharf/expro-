<?php

namespace Tests\Database;

use App\Database\Migrations\PurchaseTaxesAndSupplierRegistry;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class PurchaseTaxesAndSupplierRegistryMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;

    private const ITEM_COLUMNS = ['purchase_tax_1_name', 'purchase_tax_1_percent', 'purchase_tax_2_name', 'purchase_tax_2_percent'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        (new PurchaseTaxesAndSupplierRegistry())->up();

        $db = $this->accountingDb();

        $db->query('UPDATE ' . $db->prefixTable('items') . ' SET purchase_tax_1_name = NULL, purchase_tax_1_percent = NULL, purchase_tax_2_name = NULL, purchase_tax_2_percent = NULL');
        $db->query('UPDATE ' . $db->prefixTable('suppliers') . " SET commercial_registry = ''");

        parent::tearDown();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->found > 0;
    }

    private function hasAllColumns(): bool
    {
        foreach (self::ITEM_COLUMNS as $column) {
            if (! $this->hasColumn('items', $column)) {
                return false;
            }
        }

        return $this->hasColumn('suppliers', 'commercial_registry');
    }

    private function hasNoColumn(): bool
    {
        foreach (self::ITEM_COLUMNS as $column) {
            if ($this->hasColumn('items', $column)) {
                return false;
            }
        }

        return ! $this->hasColumn('suppliers', 'commercial_registry');
    }

    public function testUp_AddsTheColumnsAnItemLeavesEmptyAndASupplierLeavesBlank(): void
    {
        $this->assertTrue($this->hasAllColumns());

        $columns = $this->accountingDb()->query(
            'SELECT TABLE_NAME, COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = ? AND COLUMN_NAME LIKE ?) OR (TABLE_NAME = ? AND COLUMN_NAME = ?))',
            [$this->accountingDb()->prefixTable('items'), 'purchase_tax_%', $this->accountingDb()->prefixTable('suppliers'), 'commercial_registry'],
        )->getResult();

        $this->assertCount(5, $columns);

        foreach ($columns as $column) {
            if ($column->COLUMN_NAME === 'commercial_registry') {
                $this->assertSame('NO', $column->IS_NULLABLE);
                $this->assertSame('', trim((string) $column->COLUMN_DEFAULT, "'"));

                continue;
            }

            $this->assertSame('YES', $column->IS_NULLABLE, $column->COLUMN_NAME);
            $this->assertContains($column->COLUMN_DEFAULT, [null, 'NULL'], $column->COLUMN_NAME);
        }
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new PurchaseTaxesAndSupplierRegistry())->up();
        (new PurchaseTaxesAndSupplierRegistry())->up();

        $this->assertTrue($this->hasAllColumns());
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $items     = $this->accountingDb()->prefixTable('items');
        $suppliers = $this->accountingDb()->prefixTable('suppliers');

        $this->accountingDb()->query("ALTER TABLE `{$items}` DROP COLUMN `purchase_tax_2_percent`");
        $this->accountingDb()->query("ALTER TABLE `{$suppliers}` DROP COLUMN `commercial_registry`");

        $this->assertFalse($this->hasAllColumns());

        (new PurchaseTaxesAndSupplierRegistry())->up();

        $this->assertTrue($this->hasAllColumns());
    }

    public function testUp_LeavesAnExistingItemWithoutPurchaseTaxesAndAnExistingSupplierWithoutARegistryNumber(): void
    {
        (new PurchaseTaxesAndSupplierRegistry())->down();

        $this->assertTrue($this->hasNoColumn(), 'The state before the migration');

        $itemId     = $this->createTestItem();
        $supplierId = $this->insertSupplier('Before The Registry');

        (new PurchaseTaxesAndSupplierRegistry())->up();

        $item     = $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow();
        $supplier = $this->accountingDb()->table('suppliers')->where('person_id', $supplierId)->get()->getRow();

        foreach (self::ITEM_COLUMNS as $column) {
            $this->assertNull($item->{$column}, $column);
        }

        $this->assertSame('', $supplier->commercial_registry);
    }

    public function testDown_RemovesTheColumnsWhenNothingUsesThem(): void
    {
        (new PurchaseTaxesAndSupplierRegistry())->down();

        $this->assertTrue($this->hasNoColumn());

        (new PurchaseTaxesAndSupplierRegistry())->down();

        $this->assertTrue($this->hasNoColumn(), 'Taking it down again changes nothing');
    }

    public function testDown_RefusesToLoseThePurchaseTaxOfAnItem(): void
    {
        $itemId = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['purchase_tax_2_percent' => '5']);

        $this->expectRefusal();

        $this->assertTrue($this->hasAllColumns());
        $this->assertSame('5.000', (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->purchase_tax_2_percent);
    }

    public function testDown_RefusesToLoseTheNameOfAPurchaseTax(): void
    {
        $itemId = $this->createTestItem();

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['purchase_tax_1_name' => 'Import levy']);

        $this->expectRefusal();

        $this->assertTrue($this->hasAllColumns());
    }

    public function testDown_RefusesToLoseTheRegistryNumberOfASupplier(): void
    {
        $supplierId = $this->insertSupplier('Registered Supplier');

        $this->accountingDb()->table('suppliers')->where('person_id', $supplierId)->update(['commercial_registry' => '1010123456']);

        $this->expectRefusal();

        $this->assertTrue($this->hasAllColumns());
        $this->assertSame('1010123456', $this->accountingDb()->table('suppliers')->where('person_id', $supplierId)->get()->getRow()->commercial_registry);
    }

    private function expectRefusal(): void
    {
        try {
            (new PurchaseTaxesAndSupplierRegistry())->down();

            $this->fail('The migration was taken back although a value would be lost');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('restore', $refusal->getMessage());
        }
    }
}
