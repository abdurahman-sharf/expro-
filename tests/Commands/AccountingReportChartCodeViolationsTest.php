<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingReportChartCodeViolationsTest extends CIUnitTestCase
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
        $this->reinstallChart();

        parent::tearDown();
    }

    private function runReport(): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:report_chart_code_violations');

        return $this->getStreamFilterBuffer();
    }

    public function testRun_AcceptsAChartWhoseCodesAllStartWithTheCodeOfTheirParent(): void
    {
        $this->assertStringContainsString('The code of every account starts with the code of its parent.', $this->runReport());
    }

    public function testRun_ListsTheAccountsOutsideTheCodeOfTheirParentAndChangesNothing(): void
    {
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'payables')->update(['code' => '2211']);

        $output = $this->runReport();

        $this->assertStringContainsString('2211', $output);
        $this->assertStringContainsString('1 account(s) have a code that does not start with the code of their parent. Nothing was changed.', $output);
        $this->assertSame('2211', $this->accountingDb()->table('accounting_accounts')->where('system_key', 'payables')->get()->getRow()->code);
    }
}
