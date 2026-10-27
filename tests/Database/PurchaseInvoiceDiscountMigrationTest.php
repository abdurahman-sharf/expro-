<?php

namespace Tests\Database;

use App\Database\Migrations\PurchaseInvoiceDiscount;
use App\Libraries\Accounting\ReceivingAmounts;
use App\Libraries\Accounting\ReceivingLoader;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;

class PurchaseInvoiceDiscountMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    /** @var list<int> */
    private array $receivingIds = [];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function tearDown(): void
    {
        foreach ($this->receivingIds as $receivingId) {
            $this->accountingDb()->table('receivings_items')->where('receiving_id', $receivingId)->delete();
            $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->delete();
        }

        $this->receivingIds = [];

        (new PurchaseInvoiceDiscount())->up();

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
        return $this->hasColumn('receivings', 'invoice_discount_type') && $this->hasColumn('receivings', 'invoice_discount') && $this->hasColumn('receivings_items', 'invoice_discount_share');
    }

    private function hasNoColumn(): bool
    {
        return ! $this->hasColumn('receivings', 'invoice_discount_type') && ! $this->hasColumn('receivings', 'invoice_discount') && ! $this->hasColumn('receivings_items', 'invoice_discount_share');
    }

    /**
     * A purchase with one line of 10 units at 100 less 10%, written the way it was before the migration.
     */
    private function oldPurchase(): int
    {
        $itemId = $this->createTestItem();

        $this->accountingDb()->table('receivings')->insert(['receiving_time' => '2026-10-21 10:00:00', 'supplier_id' => null, 'employee_id' => 1, 'comment' => 'migration', 'reference' => 'M-' . uniqid(), 'payment_type' => null]);
        $id = (int) $this->accountingDb()->insertID();

        $this->receivingIds[] = $id;

        $this->accountingDb()->table('receivings_items')->insert([
            'receiving_id' => $id, 'item_id' => $itemId, 'line' => 0, 'description' => '', 'serialnumber' => '', 'quantity_purchased' => '10', 'item_cost_price' => '0',
            'item_unit_price' => '100', 'discount' => '10', 'discount_type' => PERCENT, 'item_location' => 1, 'receiving_quantity' => '1', 'tax_percent' => '15',
        ]);

        return $id;
    }

    public function testUp_AddsTheColumnsThatAreNullableAndDefaultToNothing(): void
    {
        $this->assertTrue($this->hasAllColumns());

        $columns = $this->accountingDb()->query(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?) AND COLUMN_NAME IN (?, ?, ?) ORDER BY COLUMN_NAME',
            [$this->accountingDb()->prefixTable('receivings'), $this->accountingDb()->prefixTable('receivings_items'), 'invoice_discount', 'invoice_discount_share', 'invoice_discount_type'],
        )->getResult();

        $this->assertCount(3, $columns);

        foreach ($columns as $column) {
            $this->assertSame('YES', $column->IS_NULLABLE, $column->COLUMN_NAME);
        }

        $defaults = array_column(array_map(static fn (object $column): array => [$column->COLUMN_NAME, $column->COLUMN_DEFAULT], $columns), 1, 0);

        $this->assertContains($defaults['invoice_discount_share'], [null, 'NULL'], 'MariaDB says NULL as text');
        $this->assertContains($defaults['invoice_discount_type'], [null, 'NULL']);
        $this->assertSame('0.0000', bcadd((string) $defaults['invoice_discount'], '0', 4));
    }

    public function testUp_IsSafeToRunAgain(): void
    {
        (new PurchaseInvoiceDiscount())->up();
        (new PurchaseInvoiceDiscount())->up();

        $this->assertTrue($this->hasAllColumns());
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $items = $this->accountingDb()->prefixTable('receivings_items');

        $this->accountingDb()->query("ALTER TABLE `{$items}` DROP COLUMN `invoice_discount_share`");

        $this->assertFalse($this->hasAllColumns());

        (new PurchaseInvoiceDiscount())->up();

        $this->assertTrue($this->hasAllColumns());
    }

    public function testUp_LeavesAnExistingPurchaseWithNoDiscountAndItsAmountsAsTheyWere(): void
    {
        (new PurchaseInvoiceDiscount())->down();

        $this->assertTrue($this->hasNoColumn(), 'The state before the migration');

        $id = $this->oldPurchase();

        (new PurchaseInvoiceDiscount())->up();

        $header = $this->accountingDb()->table('receivings')->where('receiving_id', $id)->get()->getRow();
        $line   = $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->get()->getRow();

        $this->assertNull($header->invoice_discount_type);
        $this->assertSame('0.0000', bcadd((string) $header->invoice_discount, '0', 4));
        $this->assertNull($line->invoice_discount_share);

        $document = (new ReceivingLoader())->load($id, false);

        $this->assertSame('0', $document->lines[0]->invoiceDiscount);
        $this->assertSame(['net' => '900.0000', 'tax' => '135.0000', 'total' => '1035.00'], ReceivingAmounts::totals($document->lines), 'What the line cost before the migration is what it costs after');
    }

    public function testDown_RemovesTheColumnsWhenNoPurchaseUsesThem(): void
    {
        (new PurchaseInvoiceDiscount())->down();

        $this->assertTrue($this->hasNoColumn());

        (new PurchaseInvoiceDiscount())->down();

        $this->assertTrue($this->hasNoColumn(), 'Taking it down again changes nothing');
    }

    public function testDown_RefusesToLoseTheDiscountOfAPurchaseThatHasOne(): void
    {
        $id = $this->oldPurchase();

        $this->accountingDb()->table('receivings')->where('receiving_id', $id)->update(['invoice_discount_type' => PERCENT, 'invoice_discount' => '10']);
        $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->update(['invoice_discount_share' => '90']);

        try {
            (new PurchaseInvoiceDiscount())->down();

            $this->fail('The migration was taken back although a purchase carries a discount');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('restore', $refusal->getMessage());
        }

        $this->assertTrue($this->hasAllColumns());
        $this->assertSame('90.0000', bcadd((string) $this->accountingDb()->table('receivings_items')->where('receiving_id', $id)->get()->getRow()->invoice_discount_share, '0', 4));
    }
}
