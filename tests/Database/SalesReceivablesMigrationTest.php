<?php

namespace Tests\Database;

use App\Database\Migrations\SalesReceivables;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\SaleFixtureTrait;

class SalesReceivablesMigrationTest extends CIUnitTestCase
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
        $db->table('customer_credit_applications')->truncate();

        foreach ($this->createdSales as $saleId) {
            $db->table('sales_payments')->where('sale_id', $saleId)->delete();
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 1');
        $this->createdSales = [];

        if (! $this->hasColumn('payment_status')) {
            (new SalesReceivables())->up();
        }

        parent::tearDown();
    }

    private function hasColumn(string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('sales_payments'), $column],
        )->getRow()->found > 0;
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->accountingDb()->prefixTable($table)],
        )->getRow()->found > 0;
    }

    private function newSale(): int
    {
        $saleId               = $this->createSale($this->employeeWithGrants(['sales']));
        $this->createdSales[] = $saleId;

        return $saleId;
    }

    public function testUp_CountsEveryPaymentThatExistsAsCompleted(): void
    {
        $saleId = $this->newSale();

        $this->accountingDb()->table('sales_payments')->insert(['sale_id' => $saleId, 'payment_type' => 'Cash', 'payment_amount' => '10.00']);

        $this->assertSame('completed', $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_status);
    }

    public function testUp_RegistersTheScreenOfCustomerBalancesWithoutGrantingItToAnyone(): void
    {
        $db = $this->accountingDb();

        $module = $db->table('modules')->where('module_id', 'accounting_receivables')->get()->getRow();

        $this->assertNotNull($module);
        $this->assertSame('123', (string) $module->sort);
        $this->assertSame(1, $db->table('permissions')->where('permission_id', 'accounting_receivables')->countAllResults());
        $this->assertSame(0, $db->table('grants')->where('permission_id', 'accounting_receivables')->countAllResults());
    }

    public function testUp_RefusesCreditAppliedToAnInvoiceThatDoesNotExist(): void
    {
        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('customer_credit_applications')->insert(['customer_id' => 1, 'invoice_sale_id' => 99999999, 'source_type' => 'credit_note', 'source_id' => 1, 'amount' => '5.0000']);
    }

    public function testUp_RefusesCreditOfNothingOrLess(): void
    {
        $saleId   = $this->newSale();
        $customer = $this->insertCustomer();

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('customer_credit_applications')->insert(['customer_id' => $customer, 'invoice_sale_id' => $saleId, 'source_type' => 'credit_note', 'source_id' => $saleId, 'amount' => '0.0000']);
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $migration = new SalesReceivables();

        $migration->up();
        $migration->up();

        $this->assertTrue($this->hasColumn('payment_status'));
        $this->assertTrue($this->hasTable('customer_credit_applications'));
        $this->assertSame(1, $this->accountingDb()->table('modules')->where('module_id', 'accounting_receivables')->countAllResults());
    }

    public function testDown_ThenUpRemovesAndRestoresWhatTheMigrationAdded(): void
    {
        $migration = new SalesReceivables();

        $migration->down();
        $this->assertFalse($this->hasColumn('payment_status'));
        $this->assertFalse($this->hasTable('customer_credit_applications'));
        $this->assertSame(0, $this->accountingDb()->table('modules')->where('module_id', 'accounting_receivables')->countAllResults());

        $migration->down();

        $migration->up();
        $this->assertTrue($this->hasColumn('payment_status'));
        $this->assertTrue($this->hasTable('customer_credit_applications'));
        $this->assertSame(1, $this->accountingDb()->table('modules')->where('module_id', 'accounting_receivables')->countAllResults());
    }
}
