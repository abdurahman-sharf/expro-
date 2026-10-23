<?php

namespace Tests\Database;

use App\Database\Migrations\SalesAccounting;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\SaleFixtureTrait;

class SalesAccountingMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use SaleFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
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

        if (! $this->hasColumn('delivered')) {
            (new SalesAccounting())->up();
        }

        parent::tearDown();
    }

    private function hasColumn(string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('sales'), $column],
        )->getRow()->found > 0;
    }

    private function newSale(): int
    {
        $saleId               = $this->createSale($this->employeeWithGrants(['sales']));
        $this->createdSales[] = $saleId;

        return $saleId;
    }

    public function testUp_CountsEverySaleAsDeliveredAndAsNoReturnOfAnother(): void
    {
        $sale = $this->accountingDb()->table('sales')->where('sale_id', $this->newSale())->get()->getRow();

        $this->assertSame('1', (string) $sale->delivered);
        $this->assertNull($sale->return_of_sale_id);
    }

    public function testUp_RefusesAReturnOfASaleThatDoesNotExist(): void
    {
        $saleId = $this->newSale();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['return_of_sale_id' => 999999]);
    }

    public function testUp_TakesAReturnOfAnExistingSale(): void
    {
        $original = $this->newSale();
        $return   = $this->newSale();

        $this->accountingDb()->table('sales')->where('sale_id', $return)->update(['return_of_sale_id' => $original]);

        $this->assertSame($original, (int) $this->accountingDb()->table('sales')->where('sale_id', $return)->get()->getRow()->return_of_sale_id);
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $migration = new SalesAccounting();

        $migration->up();
        $migration->up();

        $this->assertTrue($this->hasColumn('delivered'));
        $this->assertTrue($this->hasColumn('return_of_sale_id'));
    }

    public function testDown_ThenUpRemovesAndRestoresTheColumns(): void
    {
        $migration = new SalesAccounting();

        $migration->down();
        $this->assertFalse($this->hasColumn('delivered'));
        $this->assertFalse($this->hasColumn('return_of_sale_id'));

        $migration->down();

        $migration->up();
        $this->assertTrue($this->hasColumn('delivered'));
        $this->assertTrue($this->hasColumn('return_of_sale_id'));
    }
}
