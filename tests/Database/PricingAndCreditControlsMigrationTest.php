<?php

namespace Tests\Database;

use App\Database\Migrations\PricingAndCreditControls;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class PricingAndCreditControlsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;

    private const COLUMNS = [
        ['customers', 'price_list_id'],
        ['customers', 'credit_limit'],
        ['customers', 'credit_period_days'],
        ['sales', 'price_list_id'],
        ['items', 'minimum_price'],
        ['items', 'max_discount_percent'],
    ];

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
        (new PricingAndCreditControls())->up();

        $db = $this->accountingDb();

        $db->table('customers')->update(['price_list_id' => null, 'credit_limit' => 0, 'credit_period_days' => 0]);
        $db->table('sales')->update(['price_list_id' => null]);
        $db->table('items')->update(['minimum_price' => 0, 'max_discount_percent' => null]);
        $db->query('DELETE FROM ' . $db->prefixTable('price_list_items'));
        $db->query('DELETE FROM ' . $db->prefixTable('price_lists'));

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

        if ($row === null) {
            return null;
        }

        $type = (string) preg_replace('/^((?:small|medium|big)?int)\(\d+\)/', '$1', strtolower((string) $row->type));

        return ['type' => $type, 'nullable' => (string) $row->nullable, 'default' => in_array($row->dflt, [null, 'NULL'], true) ? null : $row->dflt];
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow() !== null;
    }

    private function hasForeignKey(string $table, string $constraint): bool
    {
        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->accountingDb()->prefixTable($table), $this->accountingDb()->prefixTable($constraint), 'FOREIGN KEY'],
        )->getRow() !== null;
    }

    private function hasNoneOfIt(): bool
    {
        foreach (self::COLUMNS as [$table, $column]) {
            if ($this->column($table, $column) !== null) {
                return false;
            }
        }

        return ! $this->hasTable('price_lists') && ! $this->hasTable('price_list_items');
    }

    public function testUp_AddsColumnsThatChangeNothingForACustomerASaleAndAnItemThatExist(): void
    {
        $this->assertSame(['type' => 'int unsigned', 'nullable' => 'YES', 'default' => null], $this->column('customers', 'price_list_id'));
        $this->assertSame(['type' => 'decimal(15,4)', 'nullable' => 'NO', 'default' => '0.0000'], $this->column('customers', 'credit_limit'));
        $this->assertSame(['type' => 'smallint unsigned', 'nullable' => 'NO', 'default' => '0'], $this->column('customers', 'credit_period_days'));
        $this->assertSame(['type' => 'int unsigned', 'nullable' => 'YES', 'default' => null], $this->column('sales', 'price_list_id'));
        $this->assertSame(['type' => 'decimal(15,4)', 'nullable' => 'NO', 'default' => '0.0000'], $this->column('items', 'minimum_price'));
        $this->assertSame(['type' => 'decimal(5,2)', 'nullable' => 'YES', 'default' => null], $this->column('items', 'max_discount_percent'));
        $this->assertTrue($this->hasForeignKey('customers', 'customers_fk_price_list'));
        $this->assertTrue($this->hasForeignKey('sales', 'sales_fk_price_list'));
    }

    public function testUp_LeavesACustomerThatExistsWithNoListNoLimitAndNoDays(): void
    {
        $migration = new PricingAndCreditControls();

        $migration->down();

        $customerId = $this->insertCustomer();
        $before     = $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRowArray();

        $migration->up();

        $after = $this->accountingDb()->table('customers')->where('person_id', $customerId)->get()->getRowArray();

        $this->assertNull($after['price_list_id']);
        $this->assertSame('0.0000', $after['credit_limit']);
        $this->assertSame('0', (string) $after['credit_period_days']);
        $this->assertSame($before, array_diff_key($after, array_flip(['price_list_id', 'credit_limit', 'credit_period_days'])));
    }

    public function testUp_CreatesTheListsAndTheirPricesWithAPriceThatIsNotNegativeAndAUniqueName(): void
    {
        $db = $this->accountingDb();

        $db->table('price_lists')->insert(['name' => 'Wholesale']);
        $listId = (int) $db->insertID();

        $this->assertSame('1', (string) $db->table('price_lists')->where('price_list_id', $listId)->get()->getRow()->status);

        $threw = false;

        try {
            $db->table('price_lists')->insert(['name' => 'wholesale']);
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'the name of a list is unique');
        $this->assertSame(1, $db->table('price_lists')->countAllResults());

        $itemId = $this->createTestItem();

        $db->table('price_list_items')->insert(['price_list_id' => $listId, 'item_id' => $itemId, 'price' => '12.5000']);

        $threw = false;

        try {
            $db->table('price_list_items')->insert(['price_list_id' => $listId, 'item_id' => $this->createTestItem(), 'price' => '-1']);
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'a price is not negative');
    }

    public function testUp_RegistersTheScreenAndTheTwoOverridePermissionsAndGrantsThemToNobody(): void
    {
        $db = $this->accountingDb();

        $this->assertSame('price_lists', $db->table('modules')->where('module_id', 'price_lists')->get()->getRow()->module_id);
        $this->assertSame('sales', $db->table('permissions')->where('permission_id', 'sales_price_override')->get()->getRow()->module_id);
        $this->assertSame('sales', $db->table('permissions')->where('permission_id', 'sales_credit_override')->get()->getRow()->module_id);
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', ['price_lists', 'sales_price_override', 'sales_credit_override'])->countAllResults());
    }

    public function testUp_IsSafeToRunAgainAndKeepsWhatWasGranted(): void
    {
        $employeeId = $this->createEmployee(grants: [['permission_id' => 'sales_credit_override', 'menu_group' => 'home']]);

        (new PricingAndCreditControls())->up();
        (new PricingAndCreditControls())->up();

        $db = $this->accountingDb();

        $this->assertSame(1, $db->table('permissions')->where('permission_id', 'sales_credit_override')->countAllResults());
        $this->assertSame(1, $db->table('modules')->where('module_id', 'price_lists')->countAllResults());
        $this->assertSame(1, $db->table('grants')->where(['permission_id' => 'sales_credit_override', 'person_id' => $employeeId])->countAllResults());
    }

    public function testUp_FinishesWhatWasStoppedHalfWay(): void
    {
        $customers = $this->accountingDb()->prefixTable('customers');

        $this->accountingDb()->query("ALTER TABLE `{$customers}` DROP COLUMN `credit_period_days`");

        $this->assertNull($this->column('customers', 'credit_period_days'));

        (new PricingAndCreditControls())->up();

        $this->assertNotNull($this->column('customers', 'credit_period_days'));
    }

    public function testDown_RemovesEverythingWhenNothingWasEntered(): void
    {
        (new PricingAndCreditControls())->down();

        $this->assertTrue($this->hasNoneOfIt());
        $this->assertNull($this->accountingDb()->table('modules')->where('module_id', 'price_lists')->get()->getRow());
        $this->assertNull($this->accountingDb()->table('permissions')->where('permission_id', 'sales_price_override')->get()->getRow());

        (new PricingAndCreditControls())->down();

        $this->assertTrue($this->hasNoneOfIt());
    }

    public function testDown_RefusesWhileAListExists(): void
    {
        $this->accountingDb()->table('price_lists')->insert(['name' => 'Retail']);

        $this->expectException(RuntimeException::class);

        try {
            (new PricingAndCreditControls())->down();
        } finally {
            $this->assertNotNull($this->column('customers', 'price_list_id'));
        }
    }

    public function testDown_RefusesWhileACustomerHoldsACreditLimit(): void
    {
        $this->accountingDb()->table('customers')->where('person_id', $this->insertCustomer())->update(['credit_limit' => '500']);

        $this->expectException(RuntimeException::class);

        try {
            (new PricingAndCreditControls())->down();
        } finally {
            $this->assertNotNull($this->column('customers', 'credit_limit'));
        }
    }

    public function testDown_RefusesWhileAnItemHoldsAMinimumPriceOrAHighestDiscount(): void
    {
        $this->accountingDb()->table('items')->where('item_id', $this->createTestItem())->update(['max_discount_percent' => '10']);

        $this->expectException(RuntimeException::class);

        try {
            (new PricingAndCreditControls())->down();
        } finally {
            $this->assertNotNull($this->column('items', 'max_discount_percent'));
        }
    }
}
