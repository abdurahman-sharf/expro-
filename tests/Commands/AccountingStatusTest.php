<?php

namespace Tests\Commands;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingStatusTest extends CIUnitTestCase
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
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_base_currency', '');
        $this->setConfigValue('accounting_start_date', '');
    }

    private function runStatus(): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:status');

        return $this->getStreamFilterBuffer();
    }

    public function testRun_ReportsDisabledAndWhatIsMissing(): void
    {
        $output = $this->runStatus();

        $this->assertStringContainsString('Accounting is disabled', $output);
        $this->assertStringContainsString('Missing before it can be enabled', $output);
        $this->assertStringContainsString('base_currency_missing', $output);
    }

    public function testRun_ReportsCompleteSettings(): void
    {
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->accountingDb()->table('accounting_fiscal_years')->insert(['name' => '2026', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->assertStringContainsString('All required settings are complete', $this->runStatus());
    }

    public function testRun_ReportsEnabled(): void
    {
        $this->setConfigValue('accounting_enabled', '1');

        $this->assertStringContainsString('Accounting is enabled', $this->runStatus());
    }
}
