<?php

namespace Tests\Database;

use App\Database\Migrations\BankReconciliation;
use App\Libraries\Accounting\AccountingGuard;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BankFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;

class BankReconciliationMigrationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use BankFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const TABLES = ['bank_statements', 'bank_statement_lines', 'bank_reconciliations', 'bank_matches', 'bank_match_lines'];

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBanking();
    }

    protected function tearDown(): void
    {
        (new BankReconciliation())->up();

        $this->stopBanking();

        parent::tearDown();
    }

    private function hasTable(string $table): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$this->accountingDb()->prefixTable($table)])->getRow()->found > 0;
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->accountingDb()->query('SELECT COUNT(*) AS found FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$this->accountingDb()->prefixTable($table), $column])->getRow()->found > 0;
    }

    private function refused(callable $action): bool
    {
        try {
            $action();
        } catch (DatabaseException) {
            return true;
        }

        return false;
    }

    public function testUp_CreatesTheTablesTheBankCodeAndThePermissionWithoutGrantingIt(): void
    {
        (new BankReconciliation())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        $this->assertFalse($this->hasColumn('treasuries', 'bank_code'));
        $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::BANK_RECONCILE)->countAllResults());

        (new BankReconciliation())->up();

        foreach (self::TABLES as $table) {
            $this->assertTrue($this->hasTable($table), $table);
        }

        $this->assertTrue($this->hasColumn('treasuries', 'bank_code'));

        $permission = $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::BANK_RECONCILE)->get()->getRow();

        $this->assertSame('accounting_treasuries', $permission->module_id);
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::BANK_RECONCILE)->countAllResults());
    }

    public function testUp_IsSafeToRunAgainAndChangesNothingTheSecondTime(): void
    {
        $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $before = $this->databaseFingerprint();

        (new BankReconciliation())->up();
        (new BankReconciliation())->up();

        $this->assertDatabaseUnchanged($before);
    }

    public function testUp_TouchesNoJournalNoLineNoAccountAndNoColumnOfATreasuryRow(): void
    {
        $this->bookLine('100.00', '2026-03-10');
        $legacy = $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bankId)->get()->getRowArray();

        $before = $this->databaseFingerprint();

        (new BankReconciliation())->down();
        (new BankReconciliation())->up();

        $after = $this->databaseFingerprint();

        foreach (['accounting_journals', 'accounting_journal_lines', 'accounting_accounts', 'accounting_routing', 'accounting_audit_log'] as $table) {
            $this->assertSame($before[$this->accountingDb()->prefixTable($table)], $after[$this->accountingDb()->prefixTable($table)], $table);
        }

        $row = $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bankId)->get()->getRowArray();

        unset($legacy['bank_code'], $row['bank_code']);

        $this->assertSame($legacy, $row);
    }

    public function testUp_LeavesABankAccountOpenedBeforeTheDirectoryWithItsNameAndNoCode(): void
    {
        $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bankId)->update(['bank_name' => 'My local bank', 'bank_code' => null]);

        (new BankReconciliation())->down();
        (new BankReconciliation())->up();

        $row = $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bankId)->get()->getRow();

        $this->assertSame('My local bank', $row->bank_name);
        $this->assertNull($row->bank_code);
    }

    public function testDown_RemovesTheTablesTheColumnAndThePermissionWithItsGrants(): void
    {
        $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE]);

        (new BankReconciliation())->down();

        foreach (self::TABLES as $table) {
            $this->assertFalse($this->hasTable($table), $table);
        }

        $this->assertFalse($this->hasColumn('treasuries', 'bank_code'));
        $this->assertSame(0, $this->accountingDb()->table('permissions')->where('permission_id', AccountingGuard::BANK_RECONCILE)->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('grants')->where('permission_id', AccountingGuard::BANK_RECONCILE)->countAllResults());
    }

    public function testTables_RefuseAStatementLineMatchedTwiceAndAJournalLineInTwoMatches(): void
    {
        $line = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A'], ['2026-03-10', 'Deposit B', '100.00', '', 'B']]));
        $first  = $this->statementLineId('Deposit A');
        $second = $this->statementLineId('Deposit B');
        $match  = $this->bank->match($this->bankId, $first, [$line], $this->bankClerk);
        $db     = $this->accountingDb();

        $this->assertTrue($this->refused(fn () => $db->table('bank_matches')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $this->bankId, 'statement_line_id' => $first, 'amount' => '100.0000'])));

        $db->table('bank_matches')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $this->bankId, 'statement_line_id' => $second, 'amount' => '100.0000']);
        $other = (int) $db->insertID();

        $this->assertTrue($this->refused(fn () => $db->table('bank_match_lines')->insert(['match_id' => $other, 'journal_line_id' => $line, 'amount' => '100.0000'])));
        $this->assertSame(1, $db->table('bank_match_lines')->where('match_id', $match)->countAllResults());
    }

    public function testTables_RefuseTheSameFileTwiceAndTheSameLineTwiceInOneAccount(): void
    {
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $db        = $this->accountingDb();
        $statement = $db->table('bank_statements')->get()->getRowArray();
        $line      = $db->table('bank_statement_lines')->get()->getRowArray();

        unset($statement['statement_id'], $line['line_id']);

        $this->assertTrue($this->refused(fn () => $db->table('bank_statements')->insert($statement)));

        $db->table('bank_statements')->insert(['checksum' => str_repeat('a', 64)] + $statement);
        $line['statement_id'] = (int) $db->insertID();

        $this->assertTrue($this->refused(fn () => $db->table('bank_statement_lines')->insert($line)));
        $this->assertSame(1, $db->table('bank_statement_lines')->countAllResults());
    }

    public function testTables_RefuseADeleteOfAJournalLineAMatchPointsAtAndOfAStatementWithLines(): void
    {
        $line = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$line], $this->bankClerk);
        $db = $this->accountingDb();

        $this->assertTrue($this->refused(fn () => $db->table('accounting_journal_lines')->where('line_id', $line)->delete()));
        $this->assertTrue($this->refused(fn () => $db->table('bank_statements')->delete()));
        $this->assertTrue($this->refused(fn () => $db->table('bank_statement_lines')->delete()));
        $this->assertTrue($this->refused(fn () => $db->table('treasuries')->where('treasury_id', $this->bankId)->delete()));
        $this->assertSame(1, $db->table('accounting_journal_lines')->where('line_id', $line)->countAllResults());
    }

    public function testTables_RefuseAZeroAmountALineOfNoDirectionAndAnUnknownStatus(): void
    {
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $db        = $this->accountingDb();
        $statement = $db->table('bank_statements')->get()->getRowArray();
        $line      = $db->table('bank_statement_lines')->get()->getRowArray();

        unset($line['line_id']);

        $this->assertTrue($this->refused(fn () => $db->table('bank_statement_lines')->insert(['amount' => '0.0000', 'dedupe_key' => str_repeat('b', 64)] + $line)));
        $this->assertTrue($this->refused(fn () => $db->table('bank_reconciliations')->insert(['company_id' => 1, 'branch_id' => 1, 'treasury_id' => $this->bankId, 'closed_through' => '2026-03-31', 'statement_balance' => '0', 'book_balance' => '0', 'status' => 'open'])));
        $this->assertTrue($this->refused(fn () => $db->table('bank_statements')->insert(['checksum' => str_repeat('c', 64), 'delimiter_name' => 'pipe'] + array_diff_key($statement, ['statement_id' => 1]))));
    }
}
