<?php

namespace Tests\Commands;

use App\Libraries\Accounting\EntityAccountService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingFillAccountsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use StreamFilterTrait;
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
        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function runFill(): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:fill_accounts');

        return $this->getStreamFilterBuffer();
    }

    public function testRun_OpensTheMissingAccountsAndReportsTheCounts(): void
    {
        $customerId = $this->insertCustomer();
        $this->insertSupplier();

        $output = $this->runFill();

        $this->assertStringContainsString('customer: 1 account(s) opened.', $output);
        $this->assertStringContainsString('supplier: 1 account(s) opened.', $output);
        $this->assertNotNull((new EntityAccountService())->accountFor(EntityAccountService::CUSTOMER, $customerId));
    }

    public function testRun_CanBeRepeatedAndOpensNothingTheSecondTime(): void
    {
        $this->insertCustomer();
        $this->runFill();

        $this->assertStringContainsString('customer: 0 account(s) opened.', $this->runFill());
    }

    public function testRun_ExplainsWhenTheMainAccountIsNotUsable(): void
    {
        $this->insertCustomer();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => null]);

        $output = $this->runFill();

        $this->assertStringContainsString('The accounts could not be opened', $output);
        $this->assertSame(0, $this->accountingDb()->table('accounting_accounts')->where('entity_type', 'customer')->countAllResults());
    }

    public function testRun_SaysSoWhenNoRoutingRowIsAutomatic(): void
    {
        foreach (['sales.customers', 'purchases.suppliers', 'inventory.locations'] as $key) {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', $key)->update(['routing_type' => 'per_item']);
        }

        $this->assertStringContainsString('No routing row is automatic', $this->runFill());
    }
}
