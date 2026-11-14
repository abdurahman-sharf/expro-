<?php

namespace Tests\Commands;

use App\Libraries\Accounting\AccountingSettings;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
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
        (new EntityAccountService())->fill();
        $this->accountingDb()->table('accounting_fiscal_years')->where('company_id', 1)->delete();
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

    /**
     * @return list<string> The years from the one given through the year after the current one, as the command names them.
     */
    private function yearsThrough(int $first): array
    {
        return array_map('strval', range($first, max($first, (int) date('Y') + 1)));
    }

    /**
     * @return list<string>
     */
    private function yearNames(): array
    {
        return array_column($this->accountingDb()->table('accounting_fiscal_years')->orderBy('start_date')->get()->getResultArray(), 'name');
    }

    private function runEnableCreated(string $arguments): string
    {
        return preg_match_all('/Created the fiscal year[^\n]*/', $this->runEnable($arguments), $found) > 0 ? implode("\n", $found[0]) : '';
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

    public function testRun_KeepsAnExistingFiscalYearAsItIsAndAddsTheFollowingOnes(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->insert(['name' => 'FY', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $this->runEnable('--currency SAR --start-date 2026-03-01');

        $first = $this->accountingDb()->table('accounting_fiscal_years')->orderBy('start_date')->get()->getRow();

        $this->assertSame(['FY', '2026-01-01', '2026-12-31'], [$first->name, $first->start_date, $first->end_date]);
        $this->assertSame(['FY', ...array_slice($this->yearsThrough(2026), 1)], $this->yearNames());
        $this->assertTrue((new AccountingSettings())->isEnabled());
    }

    public function testRun_CreatesTheFiscalYearsFromTheStartDateThroughTheCurrentYearAndTheNext(): void
    {
        $output = $this->runEnable('--currency SAR --start-date 2024-01-01');

        $this->assertSame($this->yearsThrough(2024), $this->yearNames());
        $this->assertSame((int) date('Y') + 1 . '-12-31', $this->accountingDb()->table('accounting_fiscal_years')->selectMax('end_date', 'last')->get()->getRow()->last);
        $this->assertStringContainsString('Created the fiscal year 2024-01-01 to 2024-12-31.', $output);
        $this->assertStringContainsString('Created the fiscal year 2025-01-01 to 2025-12-31.', $output);
    }

    public function testRun_FollowsAFirstYearThatDoesNotEndInDecemberWithYearsOfTheSameShape(): void
    {
        $this->runEnable('--currency SAR --start-date 2024-07-01 --fiscal-year-end 2025-06-30');

        $years = $this->accountingDb()->table('accounting_fiscal_years')->orderBy('start_date')->get()->getResult();

        $this->assertSame(['2024-07-01', '2025-06-30'], [$years[0]->start_date, $years[0]->end_date]);
        $this->assertSame(['2025-07-01', '2026-06-30'], [$years[1]->start_date, $years[1]->end_date]);
        $this->assertGreaterThanOrEqual((int) date('Y') + 1 . '-12-31', $years[array_key_last($years)]->end_date);
    }

    public function testRun_CreatesTheMissingYearsOfAnAlreadyEnabledBooksWhenRunAgain(): void
    {
        $this->runEnable('--currency SAR --start-date 2024-01-01');
        $this->accountingDb()->table('accounting_fiscal_years')->where('start_date >', '2024-12-31')->delete();

        $this->assertSame(['2024'], $this->yearNames());

        $output = $this->runEnable('');

        $this->assertSame($this->yearsThrough(2024), $this->yearNames());
        $this->assertStringContainsString('Created the fiscal year 2025-01-01 to 2025-12-31.', $output);
        $this->assertSame('', $this->runEnableCreated('--currency SAR --start-date 2024-01-01'));
    }

    public function testRun_CreatesNoYearWhenAccountingCannotBeEnabledYet(): void
    {
        $this->setConfigValue('accounting_base_currency', '');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => null]);

        $this->runEnable('--currency SAR --start-date 2024-01-01');

        $this->assertSame(['2024'], $this->yearNames());
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testRun_RefusesToChangeTheCurrencyOrTheStartDateOnceAJournalExists(): void
    {
        $this->runEnable('--currency SAR --start-date 2026-01-01');
        (new JournalService())->postNew($this->journalInput());

        $output = $this->runEnable('--currency USD --start-date 2026-06-01');

        $settings = new AccountingSettings();

        $this->assertStringContainsString(lang('Accounting.error_settings_locked'), $output);
        $this->assertSame('SAR', $settings->baseCurrency());
        $this->assertSame('2026-01-01', $settings->startDate());
    }

    public function testRun_RefusesToChangeOnlyTheCurrencyOnceAJournalExists(): void
    {
        $this->runEnable('--currency SAR --start-date 2026-01-01');
        (new JournalService())->postNew($this->journalInput());

        $output = $this->runEnable('--currency USD');

        $this->assertStringContainsString(lang('Accounting.error_settings_locked'), $output);
        $this->assertSame('SAR', (new AccountingSettings())->baseCurrency());
    }

    public function testRun_AcceptsTheSameCurrencyAndStartDateOnceAJournalExists(): void
    {
        $this->runEnable('--currency SAR --start-date 2026-01-01');
        (new JournalService())->postNew($this->journalInput());

        $output = $this->runEnable('--currency SAR --start-date 2026-01-01');

        $this->assertStringContainsString('Accounting is enabled', $output);
        $this->assertStringNotContainsString(lang('Accounting.error_settings_locked'), $output);
    }

    public function testRun_RefusesAStartDateOutsideTheFiscalYearsThatExist(): void
    {
        $this->accountingDb()->table('accounting_fiscal_years')->insert(['name' => 'FY', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);

        $output = $this->runEnable('--currency SAR --start-date 2025-03-01');

        $this->assertStringContainsString(lang('Accounting.error_start_date_outside_fiscal_year', ['2025-03-01']), $output);
        $this->assertSame('', (new AccountingSettings())->baseCurrency());
        $this->assertFalse((new AccountingSettings())->isEnabled());
    }

    public function testRun_AcceptsTheEqualsFormOfTheOptions(): void
    {
        $output = $this->runEnable('--currency=SAR --start-date=2026-07-01 --fiscal-year-end=2027-06-30');

        $this->assertStringContainsString('Accounting is enabled', $output);
        $this->assertTrue((new AccountingSettings())->isEnabled());
        $this->assertSame('2027-06-30', $this->accountingDb()->table('accounting_fiscal_years')->get()->getRow()->end_date);
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
