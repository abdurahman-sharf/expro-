<?php

namespace Tests\Database;

use App\Database\Migrations\ShippingCompanies as ShippingCompaniesMigration;
use App\Libraries\Accounting\ShippingCompanies;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;

/**
 * The table of shipping companies and the nullable column of a sale that names one: additive, safe to
 * run again, no sale or journal touched, and a company a sale names cannot be deleted from under it.
 *
 * @internal
 */
final class ShippingCompaniesMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
    }

    protected function tearDown(): void
    {
        (new ShippingCompaniesMigration())->up();
        $this->releaseOpenTransactions();
        $this->clearJournals();

        parent::tearDown();
    }

    private function hasColumn(): bool
    {
        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('sales'), 'shipping_company_id'],
        )->getRow() !== null;
    }

    public function testUp_CreatesTheTableAndTheNullableColumnAndWritesNothingToThem(): void
    {
        $this->assertTrue($this->accountingDb()->tableExists($this->accountingDb()->prefixTable('shipping_companies'), false));
        $this->assertTrue($this->hasColumn());
        $this->assertSame(0, $this->countRows('shipping_companies'));
        $this->assertSame(0, $this->accountingDb()->table('sales')->where('shipping_company_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testUp_CanRunAgainAndKeepsTheCompaniesThatAreThere(): void
    {
        $id = (new ShippingCompanies())->create(['name' => 'Fast Ship']);

        (new ShippingCompaniesMigration())->up();
        (new ShippingCompaniesMigration())->up();

        $this->assertSame('Fast Ship', (new ShippingCompanies())->find($id)->name);
        $this->assertSame(1, $this->countRows('shipping_companies'));
    }

    public function testDown_RefusesWhileASaleNamesACompanyAndDropsTheTableAndTheColumnWhenNone(): void
    {
        $id = (new ShippingCompanies())->create(['name' => 'Fast Ship']);

        $this->accountingDb()->table('sales')->insert(['sale_time' => date('Y-m-d H:i:s'), 'employee_id' => 1, 'sale_status' => 0, 'sale_type' => 0, 'shipping_company_id' => $id]);
        $saleId = (int) $this->accountingDb()->insertID();

        try {
            (new ShippingCompaniesMigration())->down();
            $this->fail('The migration was rolled back over a sale that names a company.');
        } catch (RuntimeException $refusal) {
            $this->assertStringContainsString('backup', $refusal->getMessage());
        }

        $this->assertTrue($this->hasColumn());

        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['shipping_company_id' => null]);
        (new ShippingCompaniesMigration())->down();

        $this->assertFalse($this->hasColumn());
        $this->assertFalse($this->accountingDb()->tableExists($this->accountingDb()->prefixTable('shipping_companies'), false));

        (new ShippingCompaniesMigration())->up();

        $this->assertTrue($this->hasColumn());
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
    }

    public function testCompanies_CannotBeDeletedWhileASaleNamesThem(): void
    {
        $id = (new ShippingCompanies())->create(['name' => 'Fast Ship']);

        $this->accountingDb()->table('sales')->insert(['sale_time' => date('Y-m-d H:i:s'), 'employee_id' => 1, 'sale_status' => 0, 'sale_type' => 0, 'shipping_company_id' => $id]);
        $saleId = (int) $this->accountingDb()->insertID();

        try {
            $this->accountingDb()->table('shipping_companies')->where('shipping_company_id', $id)->delete();
            $this->fail('A company a sale names was deleted.');
        } catch (DatabaseException) {
            $this->addToAssertionCount(1);
        } finally {
            $this->accountingDb()->table('sales')->where('sale_id', $saleId)->delete();
        }

        $this->assertNotNull((new ShippingCompanies())->find($id));
    }

    public function testCompanies_NameTheAccountOwedToThemAndKeepItFromBeingDeleted(): void
    {
        $account = $this->customAccount('2212', '22121', 'Owed to Fast Ship');

        (new ShippingCompanies())->create(['name' => 'Fast Ship', 'account_id' => $account]);

        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $account)->delete();
    }
}
