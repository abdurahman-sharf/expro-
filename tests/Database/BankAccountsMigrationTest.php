<?php

namespace Tests\Database;

use App\Database\Migrations\BankAccounts;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class BankAccountsMigrationTest extends CIUnitTestCase
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
        $this->setConfigValue('accounting_base_currency', 'SAR');
        $this->setConfigValue('accounting_start_date', '2020-01-01');
        $this->setConfigValue('accounting_enabled', '1');
    }

    protected function tearDown(): void
    {
        $this->clearJournals();
        $this->reinstallChart();

        (new BankAccounts())->up();

        parent::tearDown();
    }

    private function hasColumn(string $column): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$this->accountingDb()->prefixTable('treasuries'), $column],
        )->getRow()->found > 0;
    }

    private function hasUniqueIndex(): bool
    {
        return $this->accountingDb()->query(
            'SELECT COUNT(*) AS found FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? AND NON_UNIQUE = 0',
            [$this->accountingDb()->prefixTable('treasuries'), $this->accountingDb()->prefixTable('treasuries_bank_account')],
        )->getRow()->found > 0;
    }

    private function treasury(string $name, string $kind = 'cash', ?string $bankName = null, ?string $number = null): int
    {
        $this->accountingDb()->table('treasuries')->insert(['company_id' => 1, 'branch_id' => 1, 'name' => $name, 'kind' => $kind, 'bank_name' => $bankName, 'account_number' => $number]);

        return (int) $this->accountingDb()->insertID();
    }

    private function legacyBank(string $name, string $holds): int
    {
        $treasuryId = $this->treasury($name, 'bank');
        $accountId  = (new EntityAccountService())->ensure(EntityAccountService::TREASURY, $treasuryId, $name);

        (new PostingService())->post(new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($accountId, debit: $holds), new JournalLineDraft($this->accountId('opening_balance'), credit: $holds)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));

        return $treasuryId;
    }

    private function accountOf(int $treasuryId): object
    {
        return $this->accountingDb()->table('accounting_accounts')->where(['entity_type' => 'treasury', 'entity_id' => $treasuryId])->get()->getRow();
    }

    private function routingRow(): ?object
    {
        return $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->get()->getRow();
    }

    public function testUp_AddsTheNameOfTheBankAndTheNumberOfTheAccountAsOptionalColumns(): void
    {
        $this->assertTrue($this->hasColumn('bank_name'));
        $this->assertTrue($this->hasColumn('account_number'));

        $cash = $this->treasury('Drawer');
        $row  = $this->accountingDb()->table('treasuries')->where('treasury_id', $cash)->get()->getRow();

        $this->assertNull($row->bank_name);
        $this->assertNull($row->account_number);
    }

    public function testUp_RefusesTheSameAccountOfTheSameBankTwiceButNotCashTreasuriesWithoutOne(): void
    {
        $this->treasury('Drawer');
        $this->treasury('Second drawer');
        $this->treasury('Old bank', 'bank');
        $this->treasury('Older bank', 'bank');
        $this->treasury('Gulf', 'bank', 'Gulf Bank', '1234567890');
        $this->treasury('Delta', 'bank', 'Delta Bank', '1234567890');

        $this->assertTrue($this->hasUniqueIndex());

        $this->expectException(DatabaseException::class);

        $this->treasury('Gulf again', 'bank', 'Gulf Bank', '1234567890');
    }

    public function testUp_PointsTheRoutingRowOfTheBanksAtTheAccountOfTheBanks(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->delete();

        (new BankAccounts())->up();

        $this->assertSame($this->accountId('banks'), (int) $this->routingRow()->account_id);
    }

    public function testUp_TakesTheBankAccountsOpenedEarlierToTheBanksWithoutChangingTheirBalances(): void
    {
        $first  = $this->legacyBank('Old bank', '120.00');
        $second = $this->legacyBank('Older bank', '33.50');

        $linesBefore = $this->countRows('accounting_journal_lines');
        $idsBefore   = [(int) $this->accountOf($first)->account_id, (int) $this->accountOf($second)->account_id];

        (new BankAccounts())->up();

        $banks = $this->accountId('banks');

        foreach ([[$first, '120.0000'], [$second, '33.5000']] as $index => [$treasuryId, $balance]) {
            $account = $this->accountOf($treasuryId);

            $this->assertSame($idsBefore[$index], (int) $account->account_id);
            $this->assertSame($banks, (int) $account->parent_id);
            $this->assertMatchesRegularExpression('/^1202\d{3}$/', (string) $account->code);

            $held = $this->accountingDb()->table('accounting_journal_lines')->select('SUM(debit) - SUM(credit) AS balance', false)->where('account_id', $account->account_id)->get()->getRow()->balance;

            $this->assertSame($balance, bcadd((string) $held, '0', 4));
        }

        $this->assertSame($linesBefore, $this->countRows('accounting_journal_lines'));
    }

    public function testUp_KeepsTheBankAccountsOpenedEarlierWithoutDetailsUntilSomebodyCompletesThem(): void
    {
        $bank = $this->legacyBank('Old bank', '10.00');

        (new BankAccounts())->up();

        $row = $this->accountingDb()->table('treasuries')->where('treasury_id', $bank)->get()->getRow();

        $this->assertNull($row->bank_name);
        $this->assertNull($row->account_number);
        $this->assertSame('Old bank', $row->name);
    }

    public function testUp_LeavesCashTreasuriesUnderTheTreasuries(): void
    {
        $drawer = $this->treasury('Drawer');

        (new EntityAccountService())->ensure(EntityAccountService::TREASURY, $drawer, 'Drawer');
        (new BankAccounts())->up();

        $this->assertSame($this->accountId('treasuries'), (int) $this->accountOf($drawer)->parent_id);
    }

    public function testUp_IsSafeToRunTwice(): void
    {
        $bank = $this->legacyBank('Old bank', '10.00');

        (new BankAccounts())->up();

        $code = (string) $this->accountOf($bank)->code;
        $rows = $this->countRows('accounting_routing');

        (new BankAccounts())->up();

        $this->assertSame($code, (string) $this->accountOf($bank)->code);
        $this->assertSame($rows, $this->countRows('accounting_routing'));
        $this->assertTrue($this->hasColumn('bank_name'));
    }

    public function testUp_StillRunsOnAChartWithoutAnAccountForTheBanks(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'treasuries.banks')->delete();
        $this->accountingDb()->table('accounting_accounts')->where('system_key', 'banks')->update(['system_key' => null, 'code' => '1299']);

        $bank = $this->legacyBank('Old bank', '10.00');
        $code = (string) $this->accountOf($bank)->code;

        (new BankAccounts())->up();

        $this->assertNull($this->routingRow());
        $this->assertSame($code, (string) $this->accountOf($bank)->code, 'Nothing was moved with no account to move it to');
    }

    public function testDown_TakesTheColumnsTheIndexAndTheRoutingRowAway(): void
    {
        $migration = new BankAccounts();

        try {
            $migration->down();

            $this->assertFalse($this->hasColumn('bank_name'));
            $this->assertFalse($this->hasColumn('account_number'));
            $this->assertFalse($this->hasUniqueIndex());
            $this->assertNull($this->routingRow());
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->hasColumn('bank_name'));
        $this->assertTrue($this->hasColumn('account_number'));
        $this->assertTrue($this->hasUniqueIndex());
        $this->assertNotNull($this->routingRow());
    }

    public function testDown_IsSafeToRunWhenTheColumnsAreAlreadyGone(): void
    {
        $migration = new BankAccounts();

        try {
            $migration->down();
            $migration->down();

            $this->assertFalse($this->hasColumn('bank_name'));
        } finally {
            $migration->up();
        }

        $this->assertTrue($this->hasColumn('bank_name'));
    }
}
