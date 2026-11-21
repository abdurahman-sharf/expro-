<?php

namespace Tests\Database;

use App\Database\Migrations\TaxRoundingAccount;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class TaxRoundingAccountMigrationTest extends CIUnitTestCase
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
        (new TaxRoundingAccount())->up();
        $this->reinstallChart();

        parent::tearDown();
    }

    private function account(): ?object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('system_key', 'tax_rounding')->get()->getRow();
    }

    private function routedTo(): ?string
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', 'tax.rounding')->get()->getRow();

        return $row === null || $row->account_id === null ? null : (string) $row->account_id;
    }

    public function testDefaultChart_HasTheAccountOfTaxRoundingAndItsRoutingForANewCompany(): void
    {
        $account = $this->account();

        $this->assertSame(['4213', 'revenue', '0', '1'], [$account->code, $account->account_type, (string) $account->is_group, (string) $account->is_system]);
        $this->assertSame((string) $account->account_id, $this->routedTo());
    }

    public function testUp_OpensTheAccountUnderOtherRevenuesInAChartThatHasNoneAndRoutesIt(): void
    {
        (new TaxRoundingAccount())->down();

        $this->assertNull($this->account());
        $this->assertNull($this->routedTo());

        (new TaxRoundingAccount())->up();

        $account = $this->account();

        $this->assertSame('4213', $account->code);
        $this->assertSame('42', $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code);
        $this->assertSame((string) $account->account_id, $this->routedTo());
    }

    public function testUp_TakesTheNextFreeCodeWhenTheFirstIsTaken(): void
    {
        (new TaxRoundingAccount())->down();
        $this->customAccount('42', '4213', 'Taken');

        (new TaxRoundingAccount())->up();

        $this->assertSame('4214', $this->account()->code);
    }

    public function testUp_OpensTheAccountOnceWhenItRunsTwice(): void
    {
        (new TaxRoundingAccount())->up();
        (new TaxRoundingAccount())->up();

        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('system_key', 'tax_rounding')->countAllResults());
    }
}
