<?php

namespace Tests\Database;

use App\Database\Migrations\CurrenciesAndRates;
use App\Database\Migrations\DocumentCurrencyColumns;
use App\Database\Migrations\FxDifferenceAccounts;
use App\Libraries\Accounting\CurrencyCatalog;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;
use Throwable;

/**
 * @internal
 */
final class MultiCurrencyMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    private const DOCUMENT_TABLES = ['sales', 'sales_payments', 'receivings', 'receivings_payments', 'expenses', 'revenues'];

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;

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
        (new FxDifferenceAccounts())->up();
        (new DocumentCurrencyColumns())->up();
        (new CurrenciesAndRates())->up();

        $this->reinstallChart();

        parent::tearDown();
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->accountingDb()->prefixTable($table), $column])->getRow()->found > 0;
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function accountOf(string $systemKey): ?object
    {
        return $this->accountingDb()->table('accounting_accounts')->where('system_key', $systemKey)->get()->getRow();
    }

    private function routedTo(string $routingKey): ?string
    {
        $row = $this->accountingDb()->table('accounting_routing')->where('routing_key', $routingKey)->get()->getRow();

        return $row === null || $row->account_id === null ? null : (string) $row->account_id;
    }

    public function testUpCreatesTheTablesSeedsTheCatalogSwitchedOffAndRegistersTheModuleWithoutGrantingIt(): void
    {
        (new CurrenciesAndRates())->down();

        $this->assertFalse($this->hasTable('accounting_currencies'));
        $this->assertFalse($this->hasTable('accounting_currency_rates'));

        $this->setConfigValue('accounting_base_currency', 'SAR');
        (new CurrenciesAndRates())->up();

        $db = $this->accountingDb();

        $this->assertSame(count(CurrencyCatalog::CURRENCIES), $db->table('accounting_currencies')->countAllResults());
        $this->assertSame(['SAR'], array_column($db->table('accounting_currencies')->where('active', 1)->get()->getResultArray(), 'currency_code'));
        $this->assertSame(['0', '3', '2'], [(string) $db->table('accounting_currencies')->where('currency_code', 'JPY')->get()->getRow()->decimals, (string) $db->table('accounting_currencies')->where('currency_code', 'KWD')->get()->getRow()->decimals, (string) $db->table('accounting_currencies')->where('currency_code', 'USD')->get()->getRow()->decimals]);
        $this->assertSame(['accounting_currencies'], array_column($db->table('modules')->where('module_id', 'accounting_currencies')->get()->getResultArray(), 'module_id'));
        $this->assertSame(['acc_currencies_manage', 'accounting_currencies'], array_column($db->table('permissions')->whereIn('permission_id', ['acc_currencies_manage', 'accounting_currencies'])->orderBy('permission_id')->get()->getResultArray(), 'permission_id'));
        $this->assertSame(0, $db->table('grants')->whereIn('permission_id', ['acc_currencies_manage', 'accounting_currencies'])->countAllResults());
        $this->assertSame('accounting_currencies', $db->table('permissions')->where('permission_id', 'acc_currencies_manage')->get()->getRow()->module_id);
    }

    public function testUpKeepsEveryRowItFindsWhenItRunsAgain(): void
    {
        $db = $this->accountingDb();
        $db->table('accounting_currencies')->where('currency_code', 'USD')->update(['active' => 1, 'name_en' => 'Dollar']);
        $count = $db->table('accounting_currencies')->countAllResults();

        (new CurrenciesAndRates())->up();
        (new CurrenciesAndRates())->up();

        $this->assertSame($count, $db->table('accounting_currencies')->countAllResults());
        $row = $db->table('accounting_currencies')->where('currency_code', 'USD')->get()->getRow();
        $this->assertSame(['1', 'Dollar'], [(string) $row->active, $row->name_en]);
        $this->assertSame(1, $db->table('modules')->where('module_id', 'accounting_currencies')->countAllResults());
        $this->assertSame(2, $db->table('permissions')->whereIn('permission_id', ['acc_currencies_manage', 'accounting_currencies'])->countAllResults());
    }

    public function testUpKeepsABaseCurrencyThatIsNotInTheCatalogSwitchedOn(): void
    {
        (new CurrenciesAndRates())->down();
        $this->setConfigValue('accounting_base_currency', 'ZZZ');

        (new CurrenciesAndRates())->up();

        $row = $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'ZZZ')->get()->getRow();
        $this->assertSame(['1', 2], [(string) $row->active, (int) $row->decimals]);

        $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'ZZZ')->delete();
        $this->setConfigValue('accounting_base_currency', 'SAR');
    }

    public function testRatesAreRefusedWhenNotAboveZeroOrWhenTwoShareADay(): void
    {
        $db = $this->accountingDb();

        $db->table('accounting_currency_rates')->insert(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10']);

        foreach ([['USD', '0', '2026-10-11'], ['USD', '-1', '2026-10-12'], ['USD', '3.8', '2026-10-10'], ['QQQ', '1', '2026-10-10']] as [$code, $rate, $date]) {
            try {
                $db->table('accounting_currency_rates')->insert(['currency_code' => $code, 'rate' => $rate, 'effective_date' => $date]);
                $this->fail("{$code} {$rate} {$date} was accepted.");
            } catch (Throwable) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(1, $db->table('accounting_currency_rates')->countAllResults());
    }

    public function testDecimalsAreRefusedOutsideZeroTwoAndThree(): void
    {
        $this->expectException(Throwable::class);

        $this->accountingDb()->table('accounting_currencies')->insert(['currency_code' => 'XXX', 'decimals' => 1]);
    }

    public function testColumnsAreAddedNullableToEveryDocumentTableAndKeepTheRowsAsTheyWere(): void
    {
        $this->assertSame('sales', self::DOCUMENT_TABLES[0]);

        foreach (self::DOCUMENT_TABLES as $table) {
            $this->assertTrue($this->hasColumn($table, 'currency_code'), $table);
            $this->assertTrue($this->hasColumn($table, 'currency_rate'), $table);
        }

        foreach ([['treasuries', 'currency_code'], ['treasury_transfers', 'to_amount'], ['treasury_transfers', 'from_currency'], ['treasury_transfers', 'to_currency'], ['accounting_journal_lines', 'currency_code']] as [$table, $column]) {
            $this->assertTrue($this->hasColumn($table, $column), "{$table}.{$column}");
        }

        $this->assertSame(0, $this->accountingDb()->table('sales')->where('currency_code IS NOT NULL', null, false)->countAllResults());
    }

    public function testColumnsAreSafeToAddAgainAndToDropWhenNothingCarriesACurrency(): void
    {
        (new DocumentCurrencyColumns())->up();
        (new DocumentCurrencyColumns())->up();

        $this->assertTrue($this->hasColumn('sales', 'currency_rate'));

        (new DocumentCurrencyColumns())->down();

        foreach (self::DOCUMENT_TABLES as $table) {
            $this->assertFalse($this->hasColumn($table, 'currency_code'), $table);
        }

        $this->assertFalse($this->hasColumn('accounting_journal_lines', 'currency_code'));

        (new DocumentCurrencyColumns())->down();
        (new DocumentCurrencyColumns())->up();

        $this->assertTrue($this->hasColumn('accounting_journal_lines', 'currency_code'));
    }

    public function testDownRefusesToDropTheColumnsWhileARowCarriesACurrency(): void
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => 'USD box', 'kind' => 'cash', 'currency_code' => 'USD']);

        try {
            (new DocumentCurrencyColumns())->down();
            $this->fail('The columns were dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('treasuries', $exception->getMessage());
        } finally {
            $this->accountingDb()->table('treasuries')->where('name', 'USD box')->delete();
        }

        $this->assertTrue($this->hasColumn('treasuries', 'currency_code'));
    }

    public function testDefaultChartHasTheTwoExchangeAccountsAndTheirRoutingForANewCompany(): void
    {
        foreach (['fx_gain' => ['4209', 'revenue', 'credit', 'fx.gain'], 'fx_loss' => ['5410', 'expense', 'debit', 'fx.loss']] as $systemKey => [$code, $type, $normal, $routing]) {
            $account = $this->accountOf($systemKey);

            $this->assertSame([$code, $type, $normal, '0', '1'], [$account->code, $account->account_type, $account->normal_balance, (string) $account->is_group, (string) $account->is_system], $systemKey);
            $this->assertSame((string) $account->account_id, $this->routedTo($routing), $systemKey);
        }
    }

    public function testFxAccountsAreOpenedInAChartThatHasNoneAndRouted(): void
    {
        (new FxDifferenceAccounts())->down();

        $this->assertNull($this->accountOf('fx_gain'));
        $this->assertNull($this->accountOf('fx_loss'));
        $this->assertNull($this->routedTo('fx.gain'));

        (new FxDifferenceAccounts())->up();

        foreach (['fx_gain' => ['4209', '42', 'fx.gain'], 'fx_loss' => ['5410', '54', 'fx.loss']] as $systemKey => [$code, $parent, $routing]) {
            $account = $this->accountOf($systemKey);

            $this->assertSame($code, $account->code, $systemKey);
            $this->assertSame($parent, $this->accountingDb()->table('accounting_accounts')->where('account_id', $account->parent_id)->get()->getRow()->code, $systemKey);
            $this->assertSame((string) $account->account_id, $this->routedTo($routing), $systemKey);
        }
    }

    public function testFxAccountsTakeTheNextFreeCodeWhenTheFirstIsTaken(): void
    {
        (new FxDifferenceAccounts())->down();
        $this->customAccount('42', '4209', 'Taken');

        (new FxDifferenceAccounts())->up();

        $this->assertSame('4210', $this->accountOf('fx_gain')->code);
        $this->assertSame('4209', $this->accountingDb()->table('accounting_accounts')->where('name', 'Taken')->get()->getRow()->code);
    }

    public function testFxAccountsAreOpenedOnceWhenTheMigrationRunsTwiceAndKeepAnAccountTheCompanyRoutedElsewhere(): void
    {
        (new FxDifferenceAccounts())->down();
        (new FxDifferenceAccounts())->up();
        $first = $this->accountOf('fx_gain')->account_id;
        $other = $this->accountIdByCode('1209');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'fx.loss')->update(['account_id' => $other]);

        (new FxDifferenceAccounts())->up();

        $this->assertSame($first, $this->accountOf('fx_gain')->account_id);
        $this->assertSame(1, $this->accountingDb()->table('accounting_accounts')->where('system_key', 'fx_gain')->countAllResults());
        $this->assertSame((string) $other, $this->routedTo('fx.loss'));
    }

    public function testFxAccountsDoNothingWhenThereIsNoChartYet(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_routing')->truncate();
        $db->table('accounting_accounts')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new FxDifferenceAccounts())->up();

        $this->assertSame(0, $db->table('accounting_accounts')->countAllResults());
    }

    public function testFxAccountsDownKeepsAnAccountThatAJournalLineUses(): void
    {
        $gain = (int) $this->accountOf('fx_gain')->account_id;
        $this->postedLineOn($gain);

        (new FxDifferenceAccounts())->down();

        $this->assertNotNull($this->accountOf('fx_gain'));
        $this->assertNull($this->accountOf('fx_loss'));
    }

    private function postedLineOn(int $accountId): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '1.00'), new JournalLineDraft($accountId, credit: '1.00')],
            sourceType: 'sale',
            sourceId: 1,
            employeeId: 1,
            currencyCode: 'SAR',
        ));
    }
}
