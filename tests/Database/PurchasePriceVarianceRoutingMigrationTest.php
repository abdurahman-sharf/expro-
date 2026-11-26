<?php

namespace Tests\Database;

use App\Database\Migrations\PurchasePriceVarianceRouting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class PurchasePriceVarianceRoutingMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const ROUTING_KEY = 'purchases.price_variance';

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
        $this->reinstallChart();

        parent::tearDown();
    }

    private function routedTo(): ?string
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', self::ROUTING_KEY)->get()->getRow();

        return $row === null || $row->account_id === null ? null : (string) $row->account_id;
    }

    public function testDefaultChart_RoutesThePriceVarianceOfPurchasesToTheCostOfSalesForANewCompany(): void
    {
        $this->assertSame((string) $this->accountId('cogs'), $this->routedTo());
    }

    public function testUp_AddsTheRowOnTheAccountTheCostOfSalesIsRoutedToAndTouchesNoAccount(): void
    {
        $db       = $this->accountingDb();
        $routed   = $this->accountId('expenses_default');
        $accounts = $this->countRows('accounting_accounts');

        $db->table('accounting_routing')->where('routing_key', self::ROUTING_KEY)->delete();
        $db->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['account_id' => $routed]);

        (new PurchasePriceVarianceRouting())->up();

        $this->assertSame((string) $routed, $this->routedTo());
        $this->assertSame($accounts, $this->countRows('accounting_accounts'));
    }

    public function testUp_FallsBackToTheCostOfSalesAccountWhenItsRowIsCancelled(): void
    {
        $db = $this->accountingDb();

        $db->table('accounting_routing')->where('routing_key', self::ROUTING_KEY)->delete();
        $db->table('accounting_routing')->where('routing_key', 'sales.cogs')->update(['routing_type' => 'cancelled', 'account_id' => null]);

        (new PurchasePriceVarianceRouting())->up();

        $this->assertSame((string) $this->accountId('cogs'), $this->routedTo());
    }

    public function testUp_KeepsARowThatIsAlreadyThere(): void
    {
        $routed = $this->accountId('expenses_default');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', self::ROUTING_KEY)->update(['account_id' => $routed]);

        (new PurchasePriceVarianceRouting())->up();

        $this->assertSame((string) $routed, $this->routedTo());
    }

    public function testDown_RemovesOnlyTheRow(): void
    {
        $rows = $this->countRows('accounting_routing');

        (new PurchasePriceVarianceRouting())->down();

        $this->assertNull($this->routedTo());
        $this->assertSame($rows - 1, $this->countRows('accounting_routing'));
    }
}
