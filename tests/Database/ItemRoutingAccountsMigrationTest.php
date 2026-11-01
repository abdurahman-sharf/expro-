<?php

namespace Tests\Database;

use App\Database\Migrations\ItemRoutingAccounts;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class ItemRoutingAccountsMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
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
        (new ItemRoutingAccounts())->up();
        $this->accountingDb()->table('item_routing_accounts')->truncate();

        parent::tearDown();
    }

    private function hasTable(): bool
    {
        return $this->accountingDb()->query('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable('item_routing_accounts')])->getRow() !== null;
    }

    private function hasForeignKey(string $constraint): bool
    {
        return $this->accountingDb()->query(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ? AND CONSTRAINT_TYPE = ?',
            [$this->accountingDb()->prefixTable('item_routing_accounts'), $this->accountingDb()->prefixTable($constraint), 'FOREIGN KEY'],
        )->getRow() !== null;
    }

    private function insertRow(int $itemId, string $key, int $accountId): void
    {
        $this->accountingDb()->table('item_routing_accounts')->insert(['item_id' => $itemId, 'routing_key' => $key, 'account_id' => $accountId]);
    }

    public function testUp_CreatesTheTableOfTheAccountsItemsNameWithoutTouchingTheItems(): void
    {
        $this->assertTrue($this->hasTable());
        $this->assertTrue($this->hasForeignKey('item_routing_accounts_fk_item'));
        $this->assertTrue($this->hasForeignKey('item_routing_accounts_fk_account'));

        $columns = array_column($this->accountingDb()->query('SHOW COLUMNS FROM ' . $this->accountingDb()->prefixTable('items'))->getResultArray(), 'Field');

        $this->assertNotContains('routing_key', $columns);
        $this->assertNotContains('revenue_account_id', $columns);
    }

    public function testUp_KeepsOneAccountForAnItemAndARoutingKey(): void
    {
        $this->reinstallChart();
        $itemId  = $this->createTestItem();
        $account = $this->customAccount('41', '4101', 'Item revenue');

        $this->insertRow($itemId, 'sales.revenue', $account);
        $this->insertRow($itemId, 'sales.returns', $account);

        $threw = false;

        try {
            $this->insertRow($itemId, 'sales.revenue', $account);
        } catch (Throwable) {
            $threw = true;
        }

        $this->assertTrue($threw, 'an item names one account for a row');
        $this->assertSame(2, $this->accountingDb()->table('item_routing_accounts')->countAllResults());
    }

    public function testUp_RefusesAnAccountOrAnItemThatDoesNotExist(): void
    {
        $this->reinstallChart();
        $itemId = $this->createTestItem();

        foreach ([[$itemId, 987654321], [987654321, $this->accountId('sales_revenue')]] as [$item, $account]) {
            $threw = false;

            try {
                $this->insertRow($item, 'sales.revenue', $account);
            } catch (Throwable) {
                $threw = true;
            }

            $this->assertTrue($threw);
        }

        $this->assertSame(0, $this->accountingDb()->table('item_routing_accounts')->countAllResults());
    }

    public function testUp_IsSafeToRunAgainAndKeepsWhatItemsNamed(): void
    {
        $this->reinstallChart();
        $itemId = $this->createTestItem();
        $this->insertRow($itemId, 'sales.revenue', $this->accountId('sales_revenue'));

        (new ItemRoutingAccounts())->up();
        (new ItemRoutingAccounts())->up();

        $this->assertSame(1, $this->accountingDb()->table('item_routing_accounts')->countAllResults());
    }

    public function testDown_RefusesWhileItemsNameAccountsAndKeepsThem(): void
    {
        $this->reinstallChart();
        $itemId = $this->createTestItem();
        $this->insertRow($itemId, 'sales.revenue', $this->accountId('sales_revenue'));

        try {
            (new ItemRoutingAccounts())->down();
            $this->fail('Taking the migration down would drop what items named.');
        } catch (RuntimeException $failure) {
            $this->assertStringContainsString('Items name accounts', $failure->getMessage());
        }

        $this->assertTrue($this->hasTable());
        $this->assertSame(1, $this->accountingDb()->table('item_routing_accounts')->countAllResults());
    }

    public function testDown_DropsTheEmptyTableAndUpBringsItBack(): void
    {
        (new ItemRoutingAccounts())->down();

        $this->assertFalse($this->hasTable());

        (new ItemRoutingAccounts())->down();
        (new ItemRoutingAccounts())->up();

        $this->assertTrue($this->hasTable());
    }
}
