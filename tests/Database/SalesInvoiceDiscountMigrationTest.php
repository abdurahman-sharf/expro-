<?php

namespace Tests\Database;

use App\Database\Migrations\SalesInvoiceDiscount;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

/**
 * @internal
 */
final class SalesInvoiceDiscountMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

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
        (new SalesInvoiceDiscount())->up();

        parent::tearDown();
    }

    /**
     * @return array{type: string, nullable: string, default: ?string}|null
     */
    private function column(string $table, string $column): ?array
    {
        $row = $this->accountingDb()->query(
            'SELECT COLUMN_TYPE AS type, IS_NULLABLE AS nullable, COLUMN_DEFAULT AS dflt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRow();

        return $row === null ? null : ['type' => strtolower((string) $row->type), 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : $row->dflt];
    }

    public function testUpAddsTheDiscountOfTheSaleAndTheShareOfEachLineAsColumnsThatMayBeEmpty(): void
    {
        $this->assertSame(['type' => 'tinyint(1)', 'nullable' => 'YES', 'default' => null], $this->column('sales', 'invoice_discount_type'));
        $this->assertSame(['type' => 'decimal(15,4)', 'nullable' => 'YES', 'default' => null], $this->column('sales', 'invoice_discount'));
        $this->assertSame(['type' => 'decimal(15,4)', 'nullable' => 'YES', 'default' => null], $this->column('sales_items', 'invoice_discount_share'));
    }

    public function testUpIsSafeToRunAgain(): void
    {
        (new SalesInvoiceDiscount())->up();
        (new SalesInvoiceDiscount())->up();

        $this->assertNotNull($this->column('sales', 'invoice_discount_type'));
        $this->assertNotNull($this->column('sales', 'invoice_discount'));
        $this->assertNotNull($this->column('sales_items', 'invoice_discount_share'));
    }

    public function testUpFinishesWhatWasStoppedHalfWay(): void
    {
        $sales = $this->accountingDb()->prefixTable('sales');

        $this->accountingDb()->query("ALTER TABLE `{$sales}` DROP COLUMN `invoice_discount`");

        $this->assertNull($this->column('sales', 'invoice_discount'));

        (new SalesInvoiceDiscount())->up();

        $this->assertNotNull($this->column('sales', 'invoice_discount'));
    }

    public function testUpLeavesASaleThatExistsAndItsLinesExactlyAsTheyWere(): void
    {
        $migration = new SalesInvoiceDiscount();
        $itemId    = $this->createTestItem(HAS_NO_STOCK);

        $migration->down();

        $this->assertNull($this->column('sales', 'invoice_discount_type'), 'The state before the migration');

        $this->accountingDb()->table('sales')->insert(['sale_time' => '2026-10-21 10:00:00', 'customer_id' => null, 'employee_id' => $this->createEmployee(), 'comment' => 'before the discount existed', 'invoice_number' => null]);
        $saleId = (int) $this->accountingDb()->insertID();
        $this->accountingDb()->table('sales_items')->insert(['sale_id' => $saleId, 'item_id' => $itemId, 'line' => 1, 'quantity_purchased' => 3, 'item_cost_price' => 4, 'item_unit_price' => 75, 'discount' => 10, 'discount_type' => PERCENT, 'item_location' => 1]);

        $saleBefore = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
        $lineBefore = $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->get()->getRowArray();

        $migration->up();

        $saleAfter = $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRowArray();
        $lineAfter = $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->get()->getRowArray();

        $this->assertNull($saleAfter['invoice_discount_type']);
        $this->assertNull($saleAfter['invoice_discount']);
        $this->assertNull($lineAfter['invoice_discount_share']);
        $this->assertSame($saleBefore, array_diff_key($saleAfter, ['invoice_discount_type' => 0, 'invoice_discount' => 0]));
        $this->assertSame($lineBefore, array_diff_key($lineAfter, ['invoice_discount_share' => 0]));

        $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->delete();
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
    }

    public function testDownRemovesTheColumnsAndTakingItDownAgainChangesNothing(): void
    {
        (new SalesInvoiceDiscount())->down();

        $this->assertNull($this->column('sales', 'invoice_discount_type'));
        $this->assertNull($this->column('sales', 'invoice_discount'));
        $this->assertNull($this->column('sales_items', 'invoice_discount_share'));

        (new SalesInvoiceDiscount())->down();

        $this->assertNull($this->column('sales', 'invoice_discount'));
    }
}
