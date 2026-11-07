<?php

namespace Tests\Database;

use App\Database\Migrations\CurrenciesAndRates;
use App\Database\Migrations\DocumentCurrencyColumns;
use App\Database\Migrations\FxDifferenceAccounts;
use App\Libraries\Accounting\CurrencyCatalog;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\AccountingFixtureTrait;

class MultiCurrencyMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const DOCUMENT_TABLES = ['sales', 'sales_payments', 'receivings', 'receivings_payments', 'expenses', 'revenues'];

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

    public function testUp_CreatesTheTablesSeedsTheCatalogSwitchedOffAndRegistersTheModuleWithoutGrantingIt(): void
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

    public function testUp_KeepsEveryRowItFindsWhenItRunsAgain(): void
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

    public function testUp_KeepsABaseCurrencyThatIsNotInTheCatalogSwitchedOn(): void
    {
        (new CurrenciesAndRates())->down();
        $this->setConfigValue('accounting_base_currency', 'ZZZ');

        (new CurrenciesAndRates())->up();

        $row = $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'ZZZ')->get()->getRow();
        $this->assertSame(['1', 2], [(string) $row->active, (int) $row->decimals]);

        $this->accountingDb()->table('accounting_currencies')->where('currency_code', 'ZZZ')->delete();
        $this->setConfigValue('accounting_base_currency', 'SAR');
    }

    public function testRates_AreRefusedWhenNotAboveZeroOrWhenTwoShareADay(): void
    {
        $db = $this->accountingDb();

        $db->table('accounting_currency_rates')->insert(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2026-10-10']);

        foreach ([['USD', '0', '2026-10-11'], ['USD', '-1', '2026-10-12'], ['USD', '3.8', '2026-10-10'], ['QQQ', '1', '2026-10-10']] as [$code, $rate, $date]) {
            $accepted = true;

            try {
                $db->table('accounting_currency_rates')->insert(['currency_code' => $code, 'rate' => $rate, 'effective_date' => $date]);
            } catch (DatabaseException) {
                $accepted = false;
            }

            $this->assertFalse($accepted, "{$code} {$rate} {$date} was accepted.");
        }

        $this->assertSame(1, $db->table('accounting_currency_rates')->countAllResults());
    }

    public function testDecimals_AreRefusedOutsideZeroTwoAndThree(): void
    {
        $this->expectException(DatabaseException::class);

        $this->accountingDb()->table('accounting_currencies')->insert(['currency_code' => 'XXX', 'decimals' => 1]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function currencyColumnProvider(): array
    {
        $columns = [];

        foreach (self::DOCUMENT_TABLES as $table) {
            $columns["{$table}.currency_code"] = [$table, 'currency_code', 'char(3)'];
            $columns["{$table}.currency_rate"] = [$table, 'currency_rate', 'decimal(18,8)'];
        }

        return $columns + [
            'treasuries.currency_code'            => ['treasuries', 'currency_code', 'char(3)'],
            'treasury_transfers.from_currency'    => ['treasury_transfers', 'from_currency', 'char(3)'],
            'treasury_transfers.to_currency'      => ['treasury_transfers', 'to_currency', 'char(3)'],
            'treasury_transfers.to_amount'        => ['treasury_transfers', 'to_amount', 'decimal(15,4)'],
            'accounting_journal_lines.currency_code' => ['accounting_journal_lines', 'currency_code', 'char(3)'],
        ];
    }

    #[DataProvider('currencyColumnProvider')]
    public function testColumns_AreAddedNullableWithNoDefault(string $table, string $column, string $type): void
    {
        $row = $this->accountingDb()->query(
            'SELECT IS_NULLABLE, COLUMN_DEFAULT, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable($table), $column],
        )->getRowArray();

        $this->assertNotNull($row, "{$table}.{$column} does not exist");
        $this->assertSame('YES', $row['IS_NULLABLE'], "{$table}.{$column} must be nullable");
        $this->assertContains($row['COLUMN_DEFAULT'], [null, 'NULL'], "{$table}.{$column} must default to nothing");
        $this->assertSame($type, $row['COLUMN_TYPE'], "{$table}.{$column}");
    }

    public function testColumns_KeepARowOfBeforeAsItWasWithNoCurrencyWhenTheMigrationRunsAgain(): void
    {
        $db = $this->accountingDb();

        (new DocumentCurrencyColumns())->down();

        $db->table('sales')->insert(['sale_time' => '2026-10-20 12:00:00', 'employee_id' => $this->employeeWithGrants([]), 'comment' => 'legacy', 'sale_status' => COMPLETED, 'sale_type' => SALE_TYPE_POS, 'branch_id' => 1]);

        $saleId = (int) $db->insertID();

        try {
            (new DocumentCurrencyColumns())->up();

            $row = $db->table('sales')->where('sale_id', $saleId)->get()->getRow();

            $this->assertSame('legacy', $row->comment);
            $this->assertNull($row->currency_code);
            $this->assertNull($row->currency_rate);
        } finally {
            $db->table('sales')->where('sale_id', $saleId)->delete();
        }
    }

    public function testColumns_AreSafeToAddAgainAndToDropWhenNothingCarriesACurrency(): void
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

    /**
     * @return array<string, array{string}>
     */
    public static function tableThatKeepsACurrencyProvider(): array
    {
        $tables = [];

        foreach ([...self::DOCUMENT_TABLES, 'treasuries', 'accounting_journal_lines'] as $table) {
            $tables[$table] = [$table];
        }

        return $tables;
    }

    #[DataProvider('tableThatKeepsACurrencyProvider')]
    public function testDown_RefusesToDropTheColumnsWhileARowCarriesACurrency(string $table): void
    {
        $db = $this->accountingDb();

        $this->insertRowInDollars($table);

        try {
            (new DocumentCurrencyColumns())->down();
            $this->fail('The columns were dropped.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($table, $exception->getMessage());
        } finally {
            $db->query('SET FOREIGN_KEY_CHECKS = 0');
            $db->table($table)->where('currency_code', 'USD')->delete();
            $db->query('SET FOREIGN_KEY_CHECKS = 1');
        }

        $this->assertTrue($this->hasColumn($table, 'currency_code'));
        $this->assertTrue($this->hasColumn('sales', 'currency_code'), 'Nothing was dropped');
    }

    public function testDown_DropsTheColumnsOnceNoRowCarriesACurrency(): void
    {
        (new DocumentCurrencyColumns())->down();

        foreach ([...self::DOCUMENT_TABLES, 'treasuries', 'accounting_journal_lines'] as $table) {
            $this->assertFalse($this->hasColumn($table, 'currency_code'), $table);
        }

        (new DocumentCurrencyColumns())->up();

        $this->assertTrue($this->hasColumn('sales', 'currency_code'));
    }

    private function insertRowInDollars(string $table): void
    {
        $db      = $this->accountingDb();
        $columns = $db->query(
            'SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND IS_NULLABLE = ? AND COLUMN_DEFAULT IS NULL AND EXTRA NOT LIKE ?',
            [$db->prefixTable($table), 'NO', '%auto_increment%'],
        )->getResultArray();
        $row     = ['currency_code' => 'USD'];

        foreach ($columns as $column) {
            $row[$column['COLUMN_NAME']] ??= match ($column['DATA_TYPE']) {
                'date'                => '2026-10-21',
                'datetime'            => '2026-10-21 10:00:00',
                'timestamp'           => '2026-10-21 10:00:00',
                'enum'                => explode("','", trim(substr((string) $column['COLUMN_TYPE'], 5, -1), "'"))[0],
                'varchar', 'char', 'text' => '',
                default               => 1,
            };
        }

        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table($table)->insert($row);
        $db->query('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function testDefaultChart_HasTheTwoExchangeAccountsAndTheirRoutingForANewCompany(): void
    {
        foreach (['fx_gain' => ['4209', 'revenue', 'credit', 'fx.gain'], 'fx_loss' => ['5410', 'expense', 'debit', 'fx.loss']] as $systemKey => [$code, $type, $normal, $routing]) {
            $account = $this->accountOf($systemKey);

            $this->assertSame([$code, $type, $normal, '0', '1'], [$account->code, $account->account_type, $account->normal_balance, (string) $account->is_group, (string) $account->is_system], $systemKey);
            $this->assertSame((string) $account->account_id, $this->routedTo($routing), $systemKey);
        }
    }

    public function testFxAccounts_AreOpenedInAChartThatHasNoneAndRouted(): void
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

    public function testFxAccounts_TakeTheNextFreeCodeWhenTheFirstIsTaken(): void
    {
        (new FxDifferenceAccounts())->down();
        $this->customAccount('42', '4209', 'Taken');

        (new FxDifferenceAccounts())->up();

        $this->assertSame('4210', $this->accountOf('fx_gain')->code);
        $this->assertSame('4209', $this->accountingDb()->table('accounting_accounts')->where('name', 'Taken')->get()->getRow()->code);
    }

    public function testFxAccounts_AreOpenedOnceWhenTheMigrationRunsTwiceAndKeepAnAccountTheCompanyRoutedElsewhere(): void
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

    public function testFxAccounts_DoNothingWhenThereIsNoChartYet(): void
    {
        $db = $this->accountingDb();
        $db->query('SET FOREIGN_KEY_CHECKS = 0');
        $db->table('accounting_routing')->truncate();
        $db->table('accounting_accounts')->truncate();
        $db->query('SET FOREIGN_KEY_CHECKS = 1');

        (new FxDifferenceAccounts())->up();

        $this->assertSame(0, $db->table('accounting_accounts')->countAllResults());
    }

    public function testFxAccounts_DownKeepsAnAccountThatAJournalLineUses(): void
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
