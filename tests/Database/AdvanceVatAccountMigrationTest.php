<?php

namespace Tests\Database;

use App\Database\Migrations\AdvanceVatAccount;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AdvanceVatAccountMigrationTest extends CIUnitTestCase
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
        (new AdvanceVatAccount())->up();
        $this->reinstallChart();

        parent::tearDown();
    }

    private function account(): ?object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('system_key', 'tax_advances')->get()->getRow();
    }

    private function routedTo(): ?string
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', 'tax.advances')->get()->getRow();

        return $row === null || $row->account_id === null ? null : (string) $row->account_id;
    }

    public function testDefaultChart_HasTheAccountOfTheVatOfAdvancesAndItsRoutingForANewCompany(): void
    {
        $account = $this->account();

        $this->assertSame(['215010', 'liability', '0', '1'], [$account->code, $account->account_type, (string) $account->is_group, (string) $account->is_system]);
        $this->assertSame((string) $account->account_id, $this->routedTo());
    }

    public function testUp_OpensTheAccountAmongTheVatAccountsInAChartThatHasNoneAndRoutesIt(): void
    {
        (new AdvanceVatAccount())->down();

        $this->assertNull($this->account());
        $this->assertNull($this->routedTo());

        (new AdvanceVatAccount())->up();

        $account = $this->account();

        $this->assertSame('215010', $account->code);
        $this->assertSame('215', $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code);
        $this->assertSame((string) $account->account_id, $this->routedTo());
    }

    public function testUp_TakesTheNextFreeCodeWhenTheFirstIsTaken(): void
    {
        (new AdvanceVatAccount())->down();
        $this->customAccount('215', '215010', 'Taken');

        (new AdvanceVatAccount())->up();

        $this->assertSame('215011', $this->account()->code);
    }

    public function testUp_OpensTheAccountOnceWhenItRunsTwice(): void
    {
        (new AdvanceVatAccount())->up();
        (new AdvanceVatAccount())->up();

        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('system_key', 'tax_advances')->countAllResults());
    }
}
