<?php

namespace Tests\Database;

use App\Database\Migrations\CostPrecision;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleFixtureTrait;

class CostPrecisionMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ItemFixtureTrait;
    use SaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

    /**
     * @var list<int>
     */
    private array $createdSales = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');

        foreach ($this->createdSales as $saleId) {
            $db->table('sales_items')->where('sale_id', $saleId)->delete();
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->createdSales = [];

        (new CostPrecision())->up();

        parent::tearDown();
    }

    private function scale(string $table, string $column): int
    {
        return (int) $this->accountingDb()->query(
            'SELECT NUMERIC_SCALE AS scale FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow()->scale;
    }

    /**
     * @return array{int, int} The sale and the item the line of it is for.
     */
    private function sellWithCost(string $cost): array
    {
        $saleId               = $this->createSale($this->employeeWithGrants(['sales']));
        $this->createdSales[] = $saleId;
        $itemId               = $this->createTestItem(HAS_STOCK);
        $line                 = 1 + (int) $this->accountingDb()->table('sales_items')->selectMax('line', 'line')->where('sale_id', $saleId)->get()->getRow()->line;

        $this->accountingDb()->table('sales_items')->insert([
            'sale_id'            => $saleId,
            'item_id'            => $itemId,
            'line'               => $line,
            'description'        => '',
            'serialnumber'       => '',
            'quantity_purchased' => '1.000',
            'item_cost_price'    => $cost,
            'item_unit_price'    => '100.00',
            'discount'           => '0.00',
            'discount_type'      => 0,
            'item_location'      => 1,
            'print_option'       => 0,
        ]);

        return [$saleId, $itemId];
    }

    private function lineCostOf(int $saleId, int $itemId): string
    {
        return (string) $this->accountingDb()->table('sales_items')->where(['sale_id' => $saleId, 'item_id' => $itemId])->get()->getRow()->item_cost_price;
    }

    private function itemCostOf(int $itemId): string
    {
        return (string) $this->accountingDb()->table('items')->where('item_id', $itemId)->get()->getRow()->cost_price;
    }

    public function testUp_KeepsTheCostOfAnItemAndOfALineToSixDecimals(): void
    {
        $this->assertSame(6, $this->scale('items', 'cost_price'));
        $this->assertSame(6, $this->scale('sales_items', 'item_cost_price'));

        [$saleId, $itemId] = $this->sellWithCost('71.428571');

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '71.428571']);

        $this->assertSame('71.428571', $this->lineCostOf($saleId, $itemId));
        $this->assertSame('71.428571', $this->itemCostOf($itemId));
    }

    public function testUp_KeepsEveryCostThatWasThereAsItWas(): void
    {
        (new CostPrecision())->down();

        $this->assertSame(2, $this->scale('items', 'cost_price'));
        $this->assertSame(2, $this->scale('sales_items', 'item_cost_price'));

        [$saleId, $itemId] = $this->sellWithCost('71.43');

        $this->accountingDb()->table('items')->where('item_id', $itemId)->update(['cost_price' => '71.43']);

        (new CostPrecision())->up();

        $this->assertSame('71.430000', $this->lineCostOf($saleId, $itemId));
        $this->assertSame('71.430000', $this->itemCostOf($itemId));
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        [$saleId, $itemId] = $this->sellWithCost('12.345678');

        (new CostPrecision())->up();
        (new CostPrecision())->up();

        $this->assertSame(6, $this->scale('items', 'cost_price'));
        $this->assertSame('12.345678', $this->lineCostOf($saleId, $itemId));
    }

    public function testDown_BringsBothColumnsBackToTwoDecimals(): void
    {
        (new CostPrecision())->down();
        (new CostPrecision())->down();

        $this->assertSame(2, $this->scale('items', 'cost_price'));
        $this->assertSame(2, $this->scale('sales_items', 'item_cost_price'));
    }
}
