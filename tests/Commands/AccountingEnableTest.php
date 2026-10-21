<?php

namespace Tests\Commands;

use App\Libraries\Accounting\AccountingSettings;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\StreamFilterTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class AccountingEnableTest extends CIUnitTestCase
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
        $this->setConfigValue('accounting_enabled', '0');
        $this->setConfigValue('accounting_base_currency', '');
        $this->setConfigValue('accounting_start_date', '');
    }

    private function runEnable(string $arguments): string
    {
        $this->resetStreamFilterBuffer();
        command('accounting:enable ' . $arguments);

        return $this->getStreamFilterBuffer();
    }

    public function testRun_ListsWhatIsMissingAndStaysDisabled(): void
    {
        $output = $this->runEnable('');

        $this->assertStringContainsString('base_currency_missing', $output);
        $this->assertStringContainsString('start_date_missing', $output);
        $this->assertStringContainsString('no_fiscal_year', $output);
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testRun_EnablesAccountingAndCreatesTheFirstFiscalYear(): void
    {
        $output = $this->runEnable('--currency SAR --start-date 2026-01-01');

        $this->assertStringContainsString('Accounting is enabled', $output);
        $this->assertTrue((new AccountingSettings())->isEnabled());

        $year = $this->accountingDb()->table('accounting_fiscal_years')->get()->getRow();
        $this->assertSame('2026', $year->name);
        $this->assertSame('2026-01-01', $year->start_date);
        $this->assertSame('2026-12-31', $year->end_date);
    }

    public function testRun_UsesTheGivenEndOfTheFirstFiscalYear(): void
    {
        $this->runEnable('--currency SAR --start-date 2026-07-01 --fiscal-year-end 2027-06-30');

        $year = $this->accountingDb()->table('accounting_fiscal_years')->get()->getRow();
        $this->assertSame('2027-06-30', $year->end_date);
    }

    public function testRun_KeepsAnExistingFiscalYear(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->insert(['name' => 'FY', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->runEnable('--currency SAR --start-date 2026-03-01');

        $this->assertSame(1, $this->countRows('accounting_fiscal_years'));
        $this->assertTrue((new AccountingSettings())->isEnabled());
    }

    public function testRun_RejectsAMalformedCurrencyAndStartDate(): void
    {
        $this->assertStringContainsString('three capital letters', $this->runEnable('--currency sar'));
        $this->assertStringContainsString('valid YYYY-MM-DD', $this->runEnable('--currency SAR --start-date 01/01/2026'));
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testRun_RejectsAFiscalYearEndBeforeTheStart(): void
    {
        $output = $this->runEnable('--currency SAR --start-date 2026-06-01 --fiscal-year-end 2026-05-31');

        $this->assertStringContainsString('on or after the start date', $output);
        $this->assertSame(0, $this->countRows('accounting_fiscal_years'));
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }
}
