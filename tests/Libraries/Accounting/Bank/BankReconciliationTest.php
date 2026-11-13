<?php

namespace Tests\Libraries\Accounting\Bank;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\AccountLedger;
use App\Libraries\Accounting\Bank\BankMatches;
use App\Libraries\Accounting\Bank\BankReconciliation;
use App\Libraries\Accounting\Bank\BankSuggestions;
use App\Libraries\Accounting\Bank\ImportResult;
use App\Libraries\Accounting\Bank\StatementMapping;
use App\Libraries\Accounting\Bank\StatementParser;
use App\Libraries\Accounting\Bank\StatementRejected;
use App\Libraries\Accounting\Bank\StatementRowError;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Models\Employee;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\Services;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BankFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ParityCaseTrait;

/**
 * The reconciliation of a bank account, end to end through the real services: the statements imported
 * from files once and only once, the lines matched with the lines of the books and the matches taken
 * back, what is suggested, the reconciliation statement that ties to the treasury and to the ledger,
 * and the closing of a reconciliation up to a date. The books are only read.
 */
class BankReconciliationTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use BankFixtureTrait;
    use DatabaseFingerprintTrait;
    use ParityCaseTrait;

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

        $this->startBanking();
    }

    protected function tearDown(): void
    {
        $this->stopBanking();

        parent::tearDown();
    }

    private function refusal(callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    /**
     * @param array<string, array{rows: int, checksum: string|null}> $before
     *
     * @return list<string>
     */
    private function tablesChangedSince(array $before): array
    {
        $changed = [];

        $prefix = $this->accountingDb()->getPrefix();

        foreach ($this->databaseFingerprint() as $table => $print) {
            if (($before[$table] ?? null) !== $print) {
                $changed[] = str_starts_with($table, $prefix) ? substr($table, strlen($prefix)) : $table;
            }
        }

        return $changed;
    }

    private function lineCount(?int $treasuryId = null): int
    {
        return $this->accountingDb()->table('bank_statement_lines')->where('treasury_id', $treasuryId ?? $this->bankId)->countAllResults();
    }

    /**
     * @return array{int, int, int} The statement line, the line of the books and the match of a deposit of 100.
     */
    private function matchedDeposit(string $date = '2026-03-10', string $amount = '100.00', string $description = 'Deposit A'): array
    {
        $this->importStatement($this->statementText([[$date, $description, $amount, '', 'REF-' . $description]]), name: $description . '.csv');
        $statementLine = $this->statementLineId($description);
        $journalLine   = $this->bookLine($amount, $date, $description);

        return [$statementLine, $journalLine, $this->bank->match($this->bankId, $statementLine, [$journalLine], $this->bankClerk)];
    }

    public function testImport_StoresTheLinesOfAFileWithTheirSignedAmountsAndWritesAnAuditEntry(): void
    {
        $result = $this->importStatement($this->statementText([
            ['2026-03-01', 'Cash deposit', '1,500.50', '', 'D-1'],
            ['2026-03-02', 'Supplier transfer', '', '320.00', 'W-1'],
            ['2026-03-03', 'Card settlement', '75', '0', ''],
        ]));

        $this->assertSame(ImportResult::IMPORTED, $result->status);
        $this->assertTrue($result->created());
        $this->assertSame([3, 3, 0], [$result->rowCount, $result->imported, $result->duplicates]);

        $lines = $this->accountingDb()->table('bank_statement_lines')->orderBy('line_id')->get()->getResultArray();

        $this->assertSame(['1500.5000', '-320.0000', '75.0000'], array_column($lines, 'amount'));
        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_column($lines, 'line_date'));
        $this->assertSame(['D-1', 'W-1', ''], array_column($lines, 'reference'));

        $statement = $this->accountingDb()->table('bank_statements')->get()->getRow();

        $this->assertSame(64, strlen($statement->checksum));
        $this->assertSame(['statement.csv', 'comma', '1', '3', '3', '0', '2026-03-01', '2026-03-03', (string) $this->bankClerk], [$statement->file_name, $statement->delimiter_name, $statement->skipped_first_row, $statement->row_count, $statement->line_count, $statement->duplicate_count, $statement->date_from, $statement->date_to, $statement->imported_by]);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where('action', 'bank_statement_imported')->get()->getRow();

        $this->assertSame((int) $result->statementId, (int) $audit->entity_id);
        $this->assertSame($this->bankClerk, (int) $audit->employee_id);
        $this->assertSame(3, json_decode($audit->details, true)['imported']);
    }

    public function testImport_ReadsSemicolonAndTabSeparatedFiles(): void
    {
        $rows = [['2026-03-01', 'Semicolon row', '10.00', '', 'S1']];

        $this->importStatement($this->statementText($rows, ';'), mapping: StatementMapping::standard(StatementMapping::SEMICOLON));
        $this->importStatement($this->statementText([['2026-03-02', 'Tab row', '', '4.25', 'T1']], "\t"), mapping: StatementMapping::standard(StatementMapping::TAB));

        $this->assertSame(['10.0000', '-4.2500'], array_column($this->accountingDb()->table('bank_statement_lines')->orderBy('line_id')->get()->getResultArray(), 'amount'));
    }

    public function testImport_SkipsTheFirstRowOnlyWhenAsked(): void
    {
        $text = $this->statementText([['2026-03-01', 'First data row', '10.00', '', 'R1']], heading: false);

        $this->importStatement($text, mapping: StatementMapping::standard(skipFirstRow: false));

        $this->assertSame(1, $this->lineCount());

        $refusal = $this->refusal(fn () => $this->importStatement($this->statementText([['2026-03-05', 'Another', '10.00', '', 'R2']], heading: false), mapping: StatementMapping::standard()));

        $this->assertSame(AccountingError::BankStatementEmpty, $refusal->error);
        $this->assertSame(1, $this->lineCount());
    }

    public function testImport_ReadsTheColumnsWhereTheMappingSaysTheyAre(): void
    {
        $text = "Ref,Out,In,Text,When\nX9,,500.00,Rent received,15/03/2026\nX10,20.00,,Bank fee,16/03/2026\n";

        $this->importStatement($text, mapping: new StatementMapping(StatementMapping::COMMA, true, 5, 4, 3, 2, 1));

        $lines = $this->accountingDb()->table('bank_statement_lines')->orderBy('line_id')->get()->getResultArray();

        $this->assertSame(['2026-03-15', '2026-03-16'], array_column($lines, 'line_date'));
        $this->assertSame(['500.0000', '-20.0000'], array_column($lines, 'amount'));
        $this->assertSame(['Rent received', 'Bank fee'], array_column($lines, 'description'));
        $this->assertSame(['X9', 'X10'], array_column($lines, 'reference'));
    }

    public function testImport_CreatesNothingTheSecondTimeForTheSameFileAndSaysSo(): void
    {
        $text = $this->statementText([['2026-03-01', 'Deposit', '10.00', '', 'A'], ['2026-03-02', 'Withdrawal', '', '3.00', 'B']]);

        $first  = $this->importStatement($text);
        $before = $this->databaseFingerprint();
        $second = $this->importStatement($text, name: 'renamed.csv');

        $this->assertSame(ImportResult::DUPLICATE_FILE, $second->status);
        $this->assertFalse($second->created());
        $this->assertNull($second->statementId);
        $this->assertSame([2, 0, 2], [$second->rowCount, $second->imported, $second->duplicates]);
        $this->assertSame([], $this->tablesChangedSince($before));
        $this->assertSame(1, $this->accountingDb()->table('bank_statements')->countAllResults());
        $this->assertNotNull($first->statementId);
    }

    public function testImport_GivesTheSameChecksumWhateverTheLineEndsTheByteOrderMarkOrTheEmptyLinesAtTheEnd(): void
    {
        $text = $this->statementText([['2026-03-01', 'Deposit', '10.00', '', 'A']]);

        $this->importStatement($text);

        $this->assertSame(ImportResult::DUPLICATE_FILE, $this->importStatement("\xEF\xBB\xBF" . str_replace("\n", "\r\n", $text) . "\r\n\r\n")->status);
        $this->assertSame(1, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testImport_PutsInOnlyTheNewLinesOfAnOverlappingStatement(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'One', '10.00', '', 'A'], ['2026-03-10', 'Two', '', '4.00', 'B']]));

        $result = $this->importStatement($this->statementText([['2026-03-10', 'Two', '', '4.00', 'B'], ['2026-03-20', 'Three', '7.00', '', 'C']]), name: 'april.csv');

        $this->assertSame([2, 1, 1], [$result->rowCount, $result->imported, $result->duplicates]);
        $this->assertSame(3, $this->lineCount());
        $this->assertSame(['One', 'Two', 'Three'], array_column($this->accountingDb()->table('bank_statement_lines')->orderBy('line_id')->get()->getResultArray(), 'description'));

        $second = $this->accountingDb()->table('bank_statements')->where('statement_id', $result->statementId)->get()->getRow();

        $this->assertSame([1, 1], [(int) $second->line_count, (int) $second->duplicate_count]);
    }

    public function testImport_CreatesNothingWhenEveryLineOfAnotherFileIsKnownAlready(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'One', '10.00', '', 'A'], ['2026-03-10', 'Two', '', '4.00', 'B']]));
        $before = $this->databaseFingerprint();

        $result = $this->importStatement($this->statementText([['2026-03-10', 'Two', '', '4.00', 'B']]), name: 'subset.csv');

        $this->assertSame(ImportResult::DUPLICATE_LINES, $result->status);
        $this->assertSame([1, 0, 1], [$result->rowCount, $result->imported, $result->duplicates]);
        $this->assertSame([], $this->tablesChangedSince($before));
    }

    public function testImport_KeepsTwoIdenticalLinesOfOneFileAndKnowsBothAgainInAnotherFile(): void
    {
        $twice = [['2026-03-05', 'Card fee', '', '1.50', 'FEE'], ['2026-03-05', 'Card fee', '', '1.50', 'FEE']];

        $this->importStatement($this->statementText($twice));

        $this->assertSame(2, $this->lineCount());

        $again = $this->importStatement($this->statementText([...$twice, ['2026-03-06', 'Other', '1.00', '', 'O']]), name: 'again.csv');

        $this->assertSame([ImportResult::IMPORTED, 3, 1, 2], [$again->status, $again->rowCount, $again->imported, $again->duplicates]);
        $this->assertSame(3, $this->lineCount());

        $third = $this->importStatement($this->statementText([...$twice, ['2026-03-05', 'Card fee', '', '1.50', 'FEE']]), name: 'three.csv');

        $this->assertSame([3, 1, 2], [$third->rowCount, $third->imported, $third->duplicates]);
        $this->assertSame(4, $this->lineCount());
    }

    public function testImport_RejectsAFileWithRowsThatAreNotValidNamingEachRowAndStoresNothing(): void
    {
        $text = $this->statementText([
            ['2026-03-01', 'Good', '10.00', '', 'A'],
            ['31/02/2026', 'Bad date', '10.00', '', 'B'],
            ['2026-03-03', 'Bad amount', 'ten', '', 'C'],
            ['2026-03-04', 'Both', '5.00', '5.00', 'D'],
            ['2026-03-05', 'Neither', '', '', 'E'],
            ['2026-03-06', 'Negative', '-5.00', '', 'F'],
            ['2026-03-07', 'Too precise', '1.23456', '', 'G'],
            ['', 'No date', '1.00', '', 'H'],
        ]);

        try {
            $this->importStatement($text);
            $this->fail('The file was not rejected.');
        } catch (StatementRejected $rejected) {
            $this->assertSame(AccountingError::BankStatementRowsInvalid, $rejected->error);
            $this->assertSame(7, $rejected->errorCount);
            $this->assertSame([3, 4, 5, 6, 7, 8, 9], array_map(static fn (StatementRowError $error): int => $error->rowNumber, $rejected->errors));
            $this->assertSame(
                [StatementRowError::DATE_INVALID, StatementRowError::AMOUNT_INVALID, StatementRowError::AMOUNT_BOTH, StatementRowError::AMOUNT_MISSING, StatementRowError::AMOUNT_NEGATIVE, StatementRowError::AMOUNT_PRECISION, StatementRowError::DATE_MISSING],
                array_map(static fn (StatementRowError $error): string => $error->reason, $rejected->errors),
            );
        }

        $this->assertSame(0, $this->lineCount());
        $this->assertSame(0, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testImport_RejectsAFileOverTheSizeLimitTheRowLimitOrThatIsNotUtf8TextAndStoresNothing(): void
    {
        $tooLarge = $this->refusal(fn () => $this->importStatement(str_repeat('a', StatementParser::MAX_BYTES + 1)));
        $tooMany  = $this->refusal(fn () => $this->importStatement($this->statementText(array_fill(0, StatementParser::MAX_ROWS + 1, ['2026-03-01', 'Row', '1.00', '', 'R']))));
        $latin    = $this->refusal(fn () => $this->importStatement("Date,Description,Deposit,Withdrawal,Reference\n2026-03-01,caf\xE9,1.00,,R\n"));
        $binary   = $this->refusal(fn () => $this->importStatement("PK\x03\x04\x00\x00binary"));
        $empty    = $this->refusal(fn () => $this->importStatement("\n\n  \n"));

        $this->assertSame(AccountingError::BankStatementTooLarge, $tooLarge->error);
        $this->assertSame(AccountingError::BankStatementTooManyRows, $tooMany->error);
        $this->assertSame(AccountingError::BankStatementEncodingInvalid, $latin->error);
        $this->assertSame(AccountingError::BankStatementEncodingInvalid, $binary->error);
        $this->assertSame(AccountingError::BankStatementEmpty, $empty->error);
        $this->assertSame(0, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testImport_RefusesNewLinesDatedInsideAClosedReconciliationButKnowsTheOldOnes(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'Opening deposit', '100.00', '', 'A']]));
        $this->bookLine('100.00', '2026-03-01');
        $this->bank->match($this->bankId, $this->statementLineId('Opening deposit'), [$this->accountingDb()->table('accounting_journal_lines')->selectMax('line_id', 'id')->where('account_id', $this->bankTreasuries->accountOf($this->bankId))->get()->getRow()->id], $this->bankClerk);
        $this->bank->close($this->bankId, '2026-03-31', '100.00', $this->bankClerk);

        $known = $this->importStatement("Date,Text,In,Out,Ref\n2026-03-01,Opening deposit,100.00,,A\n", name: 'again.csv');
        $late  = $this->refusal(fn () => $this->importStatement($this->statementText([['2026-03-20', 'Late line', '5.00', '', 'L']]), name: 'late.csv'));
        $next  = $this->importStatement($this->statementText([['2026-04-02', 'April line', '5.00', '', 'M']]), name: 'april.csv');

        $this->assertSame(ImportResult::DUPLICATE_LINES, $known->status);
        $this->assertSame(AccountingError::BankStatementClosedRange, $late->error);
        $this->assertSame('2026-03-31', $late->context['date']);
        $this->assertTrue($next->created());
    }

    public function testImport_ChangesNoJournalNoLineAndNoBalance(): void
    {
        $this->bookLine('250.00', '2026-03-01');
        $before = $this->databaseFingerprint();

        $this->importStatement($this->statementText([['2026-03-01', 'Deposit', '250.00', '', 'A']]));

        $this->assertSame(['accounting_audit_log', 'bank_statement_lines', 'bank_statements'], $this->tablesChangedSince($before));
    }

    public function testMatch_FlagsTheJournalLineAndLeavesTheJournalAsItWas(): void
    {
        $journalLine = $this->bookLine('100.00', '2026-03-10', 'Customer deposit');
        $journalId   = $this->journalOfLine($journalLine);
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'REF-A']]));

        $journals = $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRowArray();
        $lines    = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_id')->get()->getResultArray();
        $before   = $this->databaseFingerprint();

        $matchId = $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$journalLine], $this->bankClerk);

        $this->assertSame(['accounting_audit_log', 'bank_match_lines', 'bank_matches'], $this->tablesChangedSince($before));
        $this->assertSame($journals, $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRowArray());
        $this->assertSame($lines, $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->orderBy('line_id')->get()->getResultArray());

        $match = $this->accountingDb()->table('bank_matches')->where('match_id', $matchId)->get()->getRow();

        $this->assertSame(['100.0000', 'manual', (string) $this->bankClerk, null], [$match->amount, $match->method, $match->matched_by, $match->reconciliation_id]);
        $this->assertSame([$journalLine], array_map('intval', array_column($this->accountingDb()->table('bank_match_lines')->where('match_id', $matchId)->get()->getResultArray(), 'journal_line_id')));
        $this->assertSame([$journalLine => $matchId], (new BankMatches())->flagged([$journalLine, 99999]));
    }

    public function testMatch_AcceptsSeveralLinesOfTheBooksThatAddUpToTheStatementLine(): void
    {
        $first  = $this->bookLine('60.00', '2026-03-10', 'Cheque 1');
        $second = $this->bookLine('40.25', '2026-03-11', 'Cheque 2');
        $this->importStatement($this->statementText([['2026-03-12', 'Batch deposit', '100.25', '', 'BATCH']]));

        $matchId = $this->bank->match($this->bankId, $this->statementLineId('Batch deposit'), [$second, $first], $this->bankClerk);

        $this->assertSame(2, $this->accountingDb()->table('bank_match_lines')->where('match_id', $matchId)->countAllResults());
    }

    public function testMatch_RejectsLinesThatDoNotAddUpToTheStatementLineNamingTheDifference(): void
    {
        $line = $this->bookLine('99.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));

        $refusal = $this->refusal(fn () => $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$line], $this->bankClerk));

        $this->assertSame(AccountingError::BankMatchAmountMismatch, $refusal->error);
        $this->assertSame('1.0000', $refusal->context['difference']);
        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testMatch_RejectsADirectionThatIsNotTheDirectionOfTheStatementLine(): void
    {
        $credit = $this->bookLine('-100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));

        $refusal = $this->refusal(fn () => $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$credit], $this->bankClerk));

        $this->assertSame(AccountingError::BankMatchDirectionMismatch, $refusal->error);
    }

    public function testMatch_RejectsAStatementLineThatIsMatchedAlready(): void
    {
        [$statementLine] = $this->matchedDeposit();
        $other           = $this->bookLine('100.00', '2026-03-11');

        $refusal = $this->refusal(fn () => $this->bank->match($this->bankId, $statementLine, [$other], $this->bankClerk));

        $this->assertSame(AccountingError::BankLineMatched, $refusal->error);
        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testMatch_RejectsALineOfTheBooksThatIsMatchedAlready(): void
    {
        [, $journalLine] = $this->matchedDeposit();
        $this->importStatement($this->statementText([['2026-03-12', 'Deposit B', '100.00', '', 'B']]), name: 'b.csv');

        $refusal = $this->refusal(fn () => $this->bank->match($this->bankId, $this->statementLineId('Deposit B'), [$journalLine], $this->bankClerk));

        $this->assertSame(AccountingError::BankJournalLineMatched, $refusal->error);
        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testMatch_RejectsLinesThatAreNotOnTheAccountOfThisBankAccountOrDoNotExist(): void
    {
        $otherBank = $this->openBank();
        $elsewhere = $this->bookLine('100.00', '2026-03-10', 'Other bank', $otherBank);
        $draft     = (new JournalService())->saveDraft(['date' => '2026-03-10', 'description' => 'Draft', 'lines' => [
            ['account_id' => (int) $this->bankTreasuries->accountOf($this->bankId), 'debit' => '100.00', 'credit' => ''],
            ['account_id' => $this->accountId('opening_balance'), 'debit' => '', 'credit' => '100.00'],
        ]], null);
        $draftLine = $this->lineOf($draft, (int) $this->bankTreasuries->accountOf($this->bankId));
        $cashLine  = (int) $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $this->journalOfLine($elsewhere))->where('account_id', $this->accountId('opening_balance'))->get()->getRow()->line_id;
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $statementLine = $this->statementLineId('Deposit A');

        foreach ([[$elsewhere], [$draftLine], [$cashLine], [987654]] as $lines) {
            $this->assertSame(AccountingError::BankJournalLineInvalid, $this->refusal(fn () => $this->bank->match($this->bankId, $statementLine, $lines, $this->bankClerk))->error);
        }

        $this->assertSame(AccountingError::BankJournalLinesRequired, $this->refusal(fn () => $this->bank->match($this->bankId, $statementLine, [], $this->bankClerk))->error);
        $this->assertSame(AccountingError::BankLineNotFound, $this->refusal(fn () => $this->bank->match($this->bankId, 987654, [$elsewhere], $this->bankClerk))->error);
        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testMatch_WritesAnAuditEntryWithBothSidesOfTheMatch(): void
    {
        [$statementLine, $journalLine, $matchId] = $this->matchedDeposit();

        $audit   = $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'bank_matched', 'entity_id' => $matchId])->get()->getRow();
        $details = json_decode($audit->details, true);

        $this->assertSame('bank_match', $audit->entity_type);
        $this->assertSame($this->bankClerk, (int) $audit->employee_id);
        $this->assertSame([$statementLine, [$journalLine], '100.0000', 'manual'], [$details['statement_line_id'], $details['journal_line_ids'], $details['amount'], $details['method']]);
    }

    public function testMatch_WorksInAClosedAccountingPeriodBecauseItPostsNothing(): void
    {
        $journalLine = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $this->closeThrough('2026-12-31');
        $journals = $this->countRows('accounting_journals');

        $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$journalLine], $this->bankClerk);

        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testMatch_AcceptsALineOfAReversedJournalAndLeavesItsReversalOpen(): void
    {
        $journalLine = $this->bookLine('100.00', '2026-03-10');
        $journalId   = $this->journalOfLine($journalLine);
        $reversalId  = (new JournalService())->reverse($journalId, '2026-03-12', 'Entered twice');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));

        $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$journalLine], $this->bankClerk);

        $report = $this->bank->report($this->bankId, '2026-03-31', '100.00', $this->bankClerk);

        $this->assertSame('reversed', $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow()->status);
        $this->assertSame([-100.0], array_map(static fn (object $line): float => (float) $line->amount, $report->bookLines));
        $this->assertSame($reversalId, (int) $report->bookLines[0]->journal_id);
    }

    public function testUnmatch_ReleasesBothSidesAndWritesAnAuditEntry(): void
    {
        [$statementLine, $journalLine, $matchId] = $this->matchedDeposit();

        $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk);

        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('bank_match_lines')->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journal_lines')->where('line_id', $journalLine)->countAllResults());

        $audit = $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'bank_unmatched', 'entity_id' => $matchId])->get()->getRow();

        $this->assertSame([$statementLine, [$journalLine]], [json_decode($audit->details, true)['statement_line_id'], json_decode($audit->details, true)['journal_line_ids']]);

        $again = $this->bank->match($this->bankId, $statementLine, [$journalLine], $this->bankClerk);

        $this->assertGreaterThan($matchId, $again);
    }

    public function testUnmatch_RejectsAMatchThatAClosedReconciliationHoldsUntilItIsReopened(): void
    {
        [, , $matchId] = $this->matchedDeposit();
        $closing       = $this->bank->close($this->bankId, '2026-03-31', '100.00', $this->bankClerk);

        $refusal = $this->refusal(fn () => $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk));

        $this->assertSame(AccountingError::BankMatchLocked, $refusal->error);
        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());

        $this->bank->reopen($closing, 'Matched the wrong line', $this->bankClerk);
        $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk);

        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testUnmatch_RejectsAnUnknownMatchAndOneOfAnotherBankAccount(): void
    {
        [, , $matchId] = $this->matchedDeposit();
        $other         = $this->openBank();

        $this->assertSame(AccountingError::BankMatchNotFound, $this->refusal(fn () => $this->bank->unmatch($this->bankId, 987654, $this->bankClerk))->error);
        $this->assertSame(AccountingError::BankMatchNotFound, $this->refusal(fn () => $this->bank->unmatch($other, $matchId, $this->bankClerk))->error);
        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    /**
     * The books carry 1,000.00 from before the statement, the bank paid in 200.00 that the books have
     * (matched), took a fee of 50.00 that the books do not have, and the books have a cheque of 300.00
     * that is not presented and a deposit of 75.00 that is in transit. The bank says 1,150.00.
     *
     * @return array<string, int> The lines of the books by name.
     */
    private function reconciliationScenario(): array
    {
        $lines = [
            'opening' => $this->bookLine('1000.00', '2026-01-15', 'Opening funds'),
            'paid_in' => $this->bookLine('200.00', '2026-03-01', 'Customer A'),
            'cheque'  => $this->bookLine('-300.00', '2026-03-20', 'Cheque 501'),
            'transit' => $this->bookLine('75.00', '2026-03-28', 'Deposit in transit'),
        ];

        $this->importStatement($this->statementText([
            ['2026-03-02', 'Customer A transfer', '200.00', '', 'INV-1'],
            ['2026-03-05', 'Bank fee', '', '50.00', 'FEE-3'],
        ]));
        $this->bank->match($this->bankId, $this->statementLineId('Customer A transfer'), [$lines['paid_in']], $this->bankClerk);

        return $lines;
    }

    public function testReport_TiesTheBookBalanceToTheTreasuryAndToTheAccountInTheLedger(): void
    {
        $this->reconciliationScenario();
        $cancelled = $this->bookLine('40.00', '2026-03-03', 'Entered by mistake');
        (new JournalService())->reverse($this->journalOfLine($cancelled), '2026-03-04', 'Entered by mistake');

        $report   = $this->bank->report($this->bankId, '2026-12-31', '1150.00', $this->bankClerk);
        $position = $this->bankTreasuries->position($this->bankId);
        $ledger   = (new AccountLedger())->build(ReportScope::resolve(null, null), (int) $this->bankTreasuries->accountOf($this->bankId), ReportPeriod::of(null, '2026-12-31'));

        $this->assertSame('975.0000', $report->bookBalance);
        $this->assertSame($position->balance, $report->bookBalance);
        $this->assertSame($ledger->closing, $report->bookBalance);
        $this->assertSame((int) $position->accountId, $report->accountId);
    }

    public function testReport_ListsWhatIsOutstandingOnEachSideAndHasNoDifferenceWhenTheBalanceOnTheStatementIsRight(): void
    {
        $this->reconciliationScenario();

        $report = $this->bank->report($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $this->assertSame('2026-03-02', $report->coverageStart);
        $this->assertSame('150.0000', $report->statementLinesTotal);
        $this->assertSame([1, '-50.0000', '0.0000', '-50.0000'], [$report->unmatchedStatement['count'], $report->unmatchedStatement['total'], $report->unmatchedStatement['in'], $report->unmatchedStatement['out']]);
        $this->assertSame(['Bank fee'], array_column($report->statementLines, 'description'));
        $this->assertSame([2, '-225.0000', '75.0000', '-300.0000'], [$report->outstandingBook['count'], $report->outstandingBook['total'], $report->outstandingBook['in'], $report->outstandingBook['out']]);
        $this->assertSame(['Cheque 501', 'Deposit in transit'], array_column($report->bookLines, 'description'));
        $this->assertSame([1, '1000.0000'], [$report->carriedBook['count'], $report->carriedBook['total']]);
        $this->assertSame('925.0000', $report->adjustedBook);
        $this->assertSame('925.0000', $report->adjustedBank);
        $this->assertSame('0.0000', $report->difference);
        $this->assertTrue($report->reconciled());
        $this->assertSame(1, $report->matchesMade);
    }

    public function testReport_ShowsTheDifferenceOfAWrongStatementBalanceAsTheBalanceLessTheLinesAndTheCarriedLines(): void
    {
        $this->reconciliationScenario();

        $report = $this->bank->report($this->bankId, '2026-03-31', '1140.00', $this->bankClerk);

        $this->assertSame('-10.0000', $report->difference);
        $this->assertFalse($report->reconciled());
        $this->assertSame(bcsub(bcsub('1140.00', $report->statementLinesTotal, 4), $report->carriedBook['total'], 4), $report->difference);
        $this->assertSame('1140.0000', $report->statementBalance);
    }

    public function testReport_GivesNoDifferenceWithoutAStatementBalance(): void
    {
        $this->reconciliationScenario();

        $report = $this->bank->report($this->bankId, '2026-03-31', null, $this->bankClerk);

        $this->assertNull($report->statementBalance);
        $this->assertNull($report->adjustedBank);
        $this->assertNull($report->difference);
        $this->assertFalse($report->reconciled());
    }

    public function testReport_CountsAMatchAsMadeOnlyOnceTheBankAndTheBooksAreBothDatedByTheDate(): void
    {
        $this->reconciliationScenario();
        $this->importStatement($this->statementText([['2026-04-02', 'Deposit presented', '75.00', '', 'DEP']]), name: 'april.csv');
        $this->bank->match($this->bankId, $this->statementLineId('Deposit presented'), [(int) $this->accountingDb()->table('accounting_journal_lines')->where('debit', '75.0000')->get()->getRow()->line_id], $this->bankClerk);

        $march = $this->bank->report($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $april = $this->bank->report($this->bankId, '2026-04-30', '1225.00', $this->bankClerk);

        $this->assertSame(1, $march->matchesMade);
        $this->assertSame(['Cheque 501', 'Deposit in transit'], array_column($march->bookLines, 'description'));
        $this->assertSame('0.0000', $march->difference);
        $this->assertSame(2, $april->matchesMade);
        $this->assertSame(['Cheque 501'], array_column($april->bookLines, 'description'));
        $this->assertSame('0.0000', $april->difference);
    }

    public function testReport_LeavesOutAJournalThatWasReversedAndItsReversalWhenNeitherIsMatched(): void
    {
        $this->reconciliationScenario();
        $mistake = $this->bookLine('40.00', '2026-03-03', 'Entered by mistake');
        (new JournalService())->reverse($this->journalOfLine($mistake), '2026-03-04', 'Entered by mistake');

        $report = $this->bank->report($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $this->assertSame(2, $report->outstandingBook['count']);
        $this->assertSame(['Cheque 501', 'Deposit in transit'], array_column($report->bookLines, 'description'));
        $this->assertSame('0.0000', $report->difference);
    }

    public function testReport_ShowsAReversalThatDatesAfterTheDateOfTheReportAsTheOriginalStillOpen(): void
    {
        $this->reconciliationScenario();
        $mistake = $this->bookLine('40.00', '2026-03-03', 'Entered by mistake');
        (new JournalService())->reverse($this->journalOfLine($mistake), '2026-04-04', 'Entered by mistake');

        $march = $this->bank->report($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $this->assertSame(3, $march->outstandingBook['count']);
        $this->assertContains('Entered by mistake', array_column($march->bookLines, 'description'));
        $this->assertSame('40.0000', bcsub($march->bookBalance, '975.0000', 4));
    }

    public function testReport_RejectsADateThatIsNotADateAndABalanceThatIsNotANumber(): void
    {
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->bank->report($this->bankId, '2026-02-30', null, $this->bankClerk))->error);
        $this->assertSame(AccountingError::BankReconciliationBalanceInvalid, $this->refusal(fn () => $this->bank->report($this->bankId, '2026-03-31', '12abc', $this->bankClerk))->error);
        $this->assertSame(AccountingError::BankReconciliationBalanceInvalid, $this->refusal(fn () => $this->bank->report($this->bankId, '2026-03-31', '1.23456', $this->bankClerk))->error);
    }

    public function testReport_TakesACommaForTheDecimalPointAndANegativeBalance(): void
    {
        $this->assertSame('-1250.5000', $this->bank->report($this->bankId, '2026-03-31', '-1250,5', $this->bankClerk)->statementBalance);
    }

    public function testReport_WritesNothing(): void
    {
        $this->reconciliationScenario();
        $before = $this->databaseFingerprint();

        $this->bank->report($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->bank->suggestions($this->bankId, $this->bankClerk);
        $this->bank->lines($this->bankId, $this->bankClerk);
        $this->bank->matches($this->bankId, $this->bankClerk);
        $this->bank->closings($this->bankId, $this->bankClerk);

        $this->assertSame([], $this->tablesChangedSince($before));
    }

    public function testSuggestions_OffersTheLinesOfTheSameAmountAndDirectionInsideTheWindowAndNoOthers(): void
    {
        $other     = $this->bookLine('200.00', '2026-03-08', 'Other customer');
        $near      = $this->bookLine('200.00', '2026-03-12', 'Invoice INV-77 payment');
        $this->bookLine('200.00', '2026-03-30', 'Too far');
        $this->bookLine('201.00', '2026-03-10', 'Other amount');
        $this->bookLine('-200.00', '2026-03-10', 'Other direction');
        $matched = $this->bookLine('200.00', '2026-03-10', 'Matched elsewhere');
        $this->importStatement($this->statementText([['2026-03-10', 'Wire from Alpha', '200.00', '', 'INV-77'], ['2026-03-09', 'Another wire', '200.00', '', 'ZZZ'], ['2026-03-09', 'Nothing like it', '999.00', '', 'NOPE']]));
        $this->bank->match($this->bankId, $this->statementLineId('Another wire'), [$matched], $this->bankClerk);

        $suggestions = $this->bank->suggestions($this->bankId, $this->bankClerk);

        $this->assertCount(1, $suggestions);
        $this->assertSame('Wire from Alpha', $suggestions[0]['line']->description);
        $this->assertSame([$near, $other], array_map(static fn (object $candidate): int => (int) $candidate->line_id, $suggestions[0]['candidates']));
        $this->assertSame([BankSuggestions::REFERENCE, 'none'], array_map(static fn (object $candidate): string => $candidate->hint, $suggestions[0]['candidates']));
        $this->assertSame(BankSuggestions::REFERENCE, $suggestions[0]['confidence']);
    }

    public function testSuggestions_CallsALineWithOneCandidateUniqueAndALineWithSeveralAmbiguous(): void
    {
        $this->bookLine('10.00', '2026-03-10', 'Only one');
        $this->bookLine('20.00', '2026-03-10', 'First twin');
        $this->bookLine('20.00', '2026-03-11', 'Second twin');
        $this->importStatement($this->statementText([['2026-03-10', 'Wire one', '10.00', '', ''], ['2026-03-10', 'Wire twin', '20.00', '', '']]));

        $suggestions = $this->bank->suggestions($this->bankId, $this->bankClerk);

        $this->assertSame([BankSuggestions::UNIQUE, BankSuggestions::AMBIGUOUS], array_column($suggestions, 'confidence'));
        $this->assertSame(['First twin', 'Second twin'], array_map(static fn (object $candidate): string => $candidate->description, $suggestions[1]['candidates']));
    }

    public function testSuggestions_FollowTheWindowOfTheSettingAndNeverMakeAMatch(): void
    {
        $this->bookLine('30.00', '2026-03-08', 'Two days before');
        $this->importStatement($this->statementText([['2026-03-10', 'Wire', '30.00', '', '']]));

        $this->assertSame(7, $this->bank->windowDays());
        $this->assertCount(1, $this->bank->suggestions($this->bankId, $this->bankClerk));

        $this->bank->setWindowDays(1, $this->bankClerk);

        $this->assertSame(1, $this->bank->windowDays());
        $this->assertSame([], $this->bank->suggestions($this->bankId, $this->bankClerk));
        $this->assertCount(1, $this->bank->suggestions($this->bankId, $this->bankClerk, 2));
        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testClose_HoldsTheMatchesMadeByTheDateAndKeepsTheBalancesItClosedWith(): void
    {
        $lines = $this->reconciliationScenario();

        $closingId = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $closing   = $this->accountingDb()->table('bank_reconciliations')->where('reconciliation_id', $closingId)->get()->getRow();
        $match     = $this->accountingDb()->table('bank_matches')->get()->getRow();

        $this->assertSame(['2026-03-31', '1150.0000', '975.0000', '0.0000', '1', 'closed', (string) $this->bankClerk], [$closing->closed_through, $closing->statement_balance, $closing->book_balance, $closing->difference, $closing->match_count, $closing->status, $closing->closed_by]);
        $this->assertSame($closingId, (int) $match->reconciliation_id);
        $this->assertNotContains($lines['cheque'], array_map('intval', array_column($this->accountingDb()->table('bank_match_lines')->get()->getResultArray(), 'journal_line_id')));

        $audit = $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'bank_reconciliation_closed', 'entity_id' => $closingId])->get()->getRow();

        $this->assertSame(['2026-03-31', '1150.0000'], [json_decode($audit->details, true)['closed_through'], json_decode($audit->details, true)['statement_balance']]);
    }

    public function testClose_RejectsAStatementBalanceThatLeavesADifferenceAndHoldsNothing(): void
    {
        $this->reconciliationScenario();

        $refusal = $this->refusal(fn () => $this->bank->close($this->bankId, '2026-03-31', '1100.00', $this->bankClerk));

        $this->assertSame(AccountingError::BankReconciliationDifference, $refusal->error);
        $this->assertSame('-50.0000', $refusal->context['difference']);
        $this->assertSame(0, $this->accountingDb()->table('bank_reconciliations')->countAllResults());
        $this->assertNull($this->accountingDb()->table('bank_matches')->get()->getRow()->reconciliation_id);
    }

    public function testClose_RejectsADateThatIsNotAfterThePreviousClosingInTheFutureBeforeTheFirstLineOrNotADate(): void
    {
        $this->reconciliationScenario();
        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        foreach (['2026-03-31' => AccountingError::BankReconciliationDateInvalid, '2026-03-15' => AccountingError::BankReconciliationDateInvalid, '2099-01-01' => AccountingError::BankReconciliationDateInvalid, '2026-02-30' => AccountingError::InvalidDate, 'soon' => AccountingError::InvalidDate] as $date => $error) {
            $this->assertSame($error, $this->refusal(fn () => $this->bank->close($this->bankId, (string) $date, '1150.00', $this->bankClerk))->error, (string) $date);
        }

        $second = $this->openBank();
        $this->importStatement($this->statementText([['2026-03-10', 'Line', '5.00', '', 'A']]), $second);

        $beforeFirstLine = $this->refusal(fn () => $this->bank->close($second, '2026-03-01', '5.00', $this->bankClerk));
        $withoutLines    = $this->refusal(fn () => $this->bank->close($this->openBank(), '2026-03-01', '0.00', $this->bankClerk));

        $this->assertSame([AccountingError::BankReconciliationDateOutside, AccountingError::BankReconciliationDateOutside], [$beforeFirstLine->error, $withoutLines->error]);
        $this->assertStringNotContainsString('()', AccountingMessage::of($beforeFirstLine));
        $this->assertStringContainsString('2026-03-01', AccountingMessage::of($beforeFirstLine));
        $this->assertStringContainsString('(2026-03-31)', AccountingMessage::of($this->refusal(fn () => $this->bank->close($this->bankId, '2026-03-15', '1150.00', $this->bankClerk))));
        $this->assertSame(1, $this->accountingDb()->table('bank_reconciliations')->countAllResults());
    }

    public function testClose_RefusesAMatchThatFallsInsideTheClosedDatesButAcceptsOneThatReachesBeyondThem(): void
    {
        $lines = $this->reconciliationScenario();
        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->bank->setWindowDays(7, $this->bankClerk);
        $this->importStatement($this->statementText([['2026-04-02', 'Deposit presented', '75.00', '', 'DEP']]), name: 'april.csv');

        $this->bank->match($this->bankId, $this->statementLineId('Deposit presented'), [$lines['transit']], $this->bankClerk);

        $this->assertSame(2, $this->accountingDb()->table('bank_matches')->countAllResults());

        $this->importStatement($this->statementText([['2026-04-03', 'Cheque presented', '', '300.00', 'CHQ']]), name: 'april2.csv');
        $this->bank->match($this->bankId, $this->statementLineId('Cheque presented'), [$lines['cheque']], $this->bankClerk);

        $this->assertNull($this->accountingDb()->table('bank_matches')->orderBy('match_id', 'DESC')->get()->getRow()->reconciliation_id);
    }

    public function testMatch_RefusesAMatchThatWouldChangeAClosedReconciliation(): void
    {
        $this->importStatement($this->statementText([['2026-03-02', 'Customer A transfer', '200.00', '', 'INV-1'], ['2026-03-05', 'Fee', '', '50.00', 'F']]));
        $this->bookLine('200.00', '2026-03-01');
        $fee = $this->bookLine('-50.00', '2026-03-04', 'Fee');
        $this->bank->match($this->bankId, $this->statementLineId('Customer A transfer'), [(int) $this->accountingDb()->table('accounting_journal_lines')->where('debit', '200.0000')->get()->getRow()->line_id], $this->bankClerk);
        $this->bank->close($this->bankId, '2026-03-31', '150.00', $this->bankClerk);

        $refusal = $this->refusal(fn () => $this->bank->match($this->bankId, $this->statementLineId('Fee'), [$fee], $this->bankClerk));

        $this->assertSame(AccountingError::BankMatchClosedRange, $refusal->error);
        $this->assertSame('2026-03-31', $refusal->context['date']);
    }

    public function testReopen_ReleasesTheMatchesWithAReasonAndAnAuditEntry(): void
    {
        $this->reconciliationScenario();
        $closingId = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $this->bank->reopen($closingId, '  A line was matched to the wrong deposit  ', $this->bankClerk);

        $closing = $this->accountingDb()->table('bank_reconciliations')->where('reconciliation_id', $closingId)->get()->getRow();

        $this->assertSame(['reopened', 'A line was matched to the wrong deposit', (string) $this->bankClerk], [$closing->status, $closing->reopen_reason, $closing->reopened_by]);
        $this->assertNotNull($closing->reopened_at);
        $this->assertNull($this->accountingDb()->table('bank_matches')->get()->getRow()->reconciliation_id);

        $audit = $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'bank_reconciliation_reopened', 'entity_id' => $closingId])->get()->getRow();

        $this->assertSame(['A line was matched to the wrong deposit', 1], [json_decode($audit->details, true)['reason'], json_decode($audit->details, true)['matches']]);

        $again = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $this->assertGreaterThan($closingId, $again);
    }

    public function testReopen_RejectsAnEarlierClosingThanTheLatestAReopenedOneAndNoReason(): void
    {
        $this->reconciliationScenario();
        $first = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->importStatement($this->statementText([['2026-04-10', 'April line', '10.00', '', 'AP']]), name: 'april.csv');
        $this->bookLine('10.00', '2026-04-10', 'April book');
        $this->bank->match($this->bankId, $this->statementLineId('April line'), [(int) $this->accountingDb()->table('accounting_journal_lines')->where('debit', '10.0000')->get()->getRow()->line_id], $this->bankClerk);
        $second = $this->bank->close($this->bankId, '2026-04-30', '1160.00', $this->bankClerk);

        $this->assertSame(AccountingError::BankReconciliationNotLatest, $this->refusal(fn () => $this->bank->reopen($first, 'Too early', $this->bankClerk))->error);
        $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->bank->reopen($second, '   ', $this->bankClerk))->error);
        $this->assertSame(AccountingError::BankReconciliationNotFound, $this->refusal(fn () => $this->bank->reopen(987654, 'Missing', $this->bankClerk))->error);

        $this->bank->reopen($second, 'April was wrong', $this->bankClerk);

        $this->assertSame(AccountingError::BankReconciliationReopened, $this->refusal(fn () => $this->bank->reopen($second, 'Twice', $this->bankClerk))->error);

        $this->bank->reopen($first, 'And now March', $this->bankClerk);

        $this->assertSame(['reopened', 'reopened'], array_column($this->accountingDb()->table('bank_reconciliations')->orderBy('reconciliation_id')->get()->getResultArray(), 'status'));
    }

    public function testClosings_FlagsAClosingWhoseBooksHaveChangedSince(): void
    {
        $this->reconciliationScenario();
        $closingId = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $this->assertFalse($this->bank->closings($this->bankId, $this->bankClerk)[0]->drifted);

        $this->bookLine('5.00', '2026-03-15', 'Posted late into March');

        $closings = $this->bank->closings($this->bankId, $this->bankClerk);

        $this->assertSame($closingId, (int) $closings[0]->reconciliation_id);
        $this->assertTrue($closings[0]->drifted);
    }

    public function testDeleteStatement_TakesOutAnUnmatchedStatementWithItsLinesAndAllowsItsImportAgain(): void
    {
        $text   = $this->statementText([['2026-03-01', 'One', '10.00', '', 'A'], ['2026-03-02', 'Two', '', '4.00', 'B']]);
        $result = $this->importStatement($text);

        $this->bank->deleteStatement((int) $result->statementId, $this->bankClerk);

        $this->assertSame(0, $this->lineCount());
        $this->assertSame(0, $this->accountingDb()->table('bank_statements')->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_audit_log')->where('action', 'bank_statement_deleted')->countAllResults());
        $this->assertTrue($this->importStatement($text)->created());
    }

    public function testDeleteStatement_RejectsAStatementWithMatchedLines(): void
    {
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $line = $this->bookLine('100.00', '2026-03-10');
        $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$line], $this->bankClerk);
        $statementId = (int) $this->accountingDb()->table('bank_statements')->get()->getRow()->statement_id;

        $refusal = $this->refusal(fn () => $this->bank->deleteStatement($statementId, $this->bankClerk));

        $this->assertSame(AccountingError::BankStatementHasMatches, $refusal->error);
        $this->assertSame(1, $this->lineCount());
    }

    public function testDeleteStatement_RejectsAStatementDatedInsideAClosedReconciliation(): void
    {
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $statementId = (int) $this->accountingDb()->table('bank_statements')->get()->getRow()->statement_id;
        $this->bank->close($this->bankId, '2026-03-31', '100.00', $this->bankClerk);

        $refusal = $this->refusal(fn () => $this->bank->deleteStatement($statementId, $this->bankClerk));

        $this->assertSame(AccountingError::BankStatementClosedRange, $refusal->error);
        $this->assertSame(1, $this->lineCount());
    }

    public function testDeleteStatement_RejectsAnUnknownStatement(): void
    {
        $this->assertSame(AccountingError::BankStatementNotFound, $this->refusal(fn () => $this->bank->deleteStatement(987654, $this->bankClerk))->error);
    }

    private function holdTheBank(int $treasuryId): BaseConnection
    {
        $other = $this->openSecondConnection(false);
        $other->resetTransStatus();
        $other->transStart();
        $other->query('SELECT treasury_id FROM ' . $other->prefixTable('treasuries') . ' WHERE treasury_id = ? LOCK IN SHARE MODE', [$treasuryId]);

        return $other;
    }

    private function whileTheBankIsHeld(callable $action): void
    {
        $other = $this->holdTheBank($this->bankId);
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertWaitsForLock($action);
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }
    }

    public function testImport_WaitsForARequestThatHoldsTheBankAccountAndStoresNothingWhenItCannotHaveIt(): void
    {
        $this->whileTheBankIsHeld(fn () => $this->importStatement($this->statementText([['2026-03-01', 'Deposit', '10.00', '', 'A']])));

        $this->assertSame(0, $this->lineCount());
        $this->assertSame(0, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testMatch_WaitsForARequestThatHoldsTheBankAccountAndMatchesNothingWhenItCannotHaveIt(): void
    {
        $line = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));

        $this->whileTheBankIsHeld(fn () => $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$line], $this->bankClerk));

        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testUnmatch_WaitsForARequestThatHoldsTheBankAccountAndTakesNothingBackWhenItCannotHaveIt(): void
    {
        [, , $matchId] = $this->matchedDeposit();

        $this->whileTheBankIsHeld(fn () => $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk));

        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testClose_WaitsForARequestThatHoldsTheBankAccountAndClosesNothingWhenItCannotHaveIt(): void
    {
        $this->reconciliationScenario();

        $this->whileTheBankIsHeld(fn () => $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk));

        $this->assertSame(0, $this->accountingDb()->table('bank_reconciliations')->countAllResults());
    }

    public function testReopen_WaitsForARequestThatHoldsTheBankAccountAndReopensNothingWhenItCannotHaveIt(): void
    {
        $this->reconciliationScenario();
        $closing = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $this->whileTheBankIsHeld(fn () => $this->bank->reopen($closing, 'Because', $this->bankClerk));

        $this->assertSame('closed', $this->accountingDb()->table('bank_reconciliations')->get()->getRow()->status);
    }

    public function testDeleteStatement_WaitsForARequestThatHoldsTheBankAccountAndDeletesNothingWhenItCannotHaveIt(): void
    {
        $result = $this->importStatement($this->statementText([['2026-03-01', 'Deposit', '10.00', '', 'A']]));

        $this->whileTheBankIsHeld(fn () => $this->bank->deleteStatement((int) $result->statementId, $this->bankClerk));

        $this->assertSame(1, $this->lineCount());
    }

    private function branchOfTheBank(): int
    {
        return (int) $this->bankTreasuries->find($this->bankId)->branch_id;
    }

    private function closingSql(string $through): string
    {
        return 'INSERT INTO ' . $this->accountingDb()->prefixTable('bank_reconciliations') . ' (company_id, branch_id, treasury_id, closed_through, statement_balance, book_balance, difference, match_count, status) VALUES (1, ' . $this->branchOfTheBank() . ', ' . $this->bankId . ", '{$through}', 0, 0, 0, 0, 'closed')";
    }

    private function matchSql(int $statementLine, int $journalLine, string $amount): array
    {
        $db = $this->accountingDb();

        return [
            'INSERT INTO ' . $db->prefixTable('bank_matches') . ' (company_id, branch_id, treasury_id, statement_line_id, amount, method) VALUES (1, ' . $this->branchOfTheBank() . ', ' . $this->bankId . ", {$statementLine}, {$amount}, 'manual')",
            'INSERT INTO ' . $db->prefixTable('bank_match_lines') . " (match_id, journal_line_id, amount) VALUES (LAST_INSERT_ID(), {$journalLine}, {$amount})",
        ];
    }

    public function testImport_RefusesALineInsideAClosingThatWasCommittedWhileItWaitedForTheBankAccount(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'First', '10.00', '', 'A']]));

        $this->whileAnotherRequestCommits([$this->closingSql('2026-03-31')], function (): void {
            $this->assertSame(AccountingError::BankStatementClosedRange, $this->refusal(fn () => $this->importStatement($this->statementText([['2026-03-20', 'Inside', '20.00', '', 'B']])))->error);
        });

        $this->assertSame(1, $this->lineCount());
    }

    public function testMatch_RefusesAMatchInsideAClosingThatWasCommittedWhileItWaitedForTheBankAccount(): void
    {
        $journalLine = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $statement = $this->statementLineId('Deposit A');

        $this->whileAnotherRequestCommits([$this->closingSql('2026-03-31')], function () use ($statement, $journalLine): void {
            $this->assertSame(AccountingError::BankMatchClosedRange, $this->refusal(fn () => $this->bank->match($this->bankId, $statement, [$journalLine], $this->bankClerk))->error);
        });

        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testUnmatch_RefusesAMatchThatAClosingHeldWhileItWaitedForTheBankAccount(): void
    {
        [, , $matchId] = $this->matchedDeposit();
        $db            = $this->accountingDb();

        $this->whileAnotherRequestCommits([
            $this->closingSql('2026-03-31'),
            'UPDATE ' . $db->prefixTable('bank_matches') . ' SET reconciliation_id = LAST_INSERT_ID() WHERE match_id = ' . $matchId,
        ], function () use ($matchId): void {
            $this->assertSame(AccountingError::BankMatchLocked, $this->refusal(fn () => $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk))->error);
        });

        $this->assertSame(1, $db->table('bank_matches')->countAllResults());
    }

    public function testClose_HoldsAMatchThatWasCommittedWhileItWaitedForTheBankAccount(): void
    {
        $this->bookLine('1000.00', '2026-01-15');
        $this->bookLine('200.00', '2026-03-01');
        $fee = $this->bookLine('-50.00', '2026-03-05', 'Bank fee');
        $this->importStatement($this->statementText([['2026-03-02', 'Customer A transfer', '200.00', '', 'INV-1'], ['2026-03-05', 'Bank fee', '', '50.00', 'FEE-3']]));
        $this->bank->match($this->bankId, $this->statementLineId('Customer A transfer'), [(int) $this->accountingDb()->table('accounting_journal_lines')->where('debit', '200.0000')->get()->getRow()->line_id], $this->bankClerk);

        $closing = 0;

        $this->whileAnotherRequestCommits($this->matchSql($this->statementLineId('Bank fee'), $fee, '-50.0000'), function () use (&$closing): void {
            $closing = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        });

        $this->assertSame(2, (int) $this->accountingDb()->table('bank_reconciliations')->where('reconciliation_id', $closing)->get()->getRow()->match_count);
        $this->assertSame([$closing, $closing], array_map('intval', array_column($this->accountingDb()->table('bank_matches')->orderBy('match_id')->get()->getResultArray(), 'reconciliation_id')));
    }

    public function testClose_RefusesTheDateOfAClosingThatWasCommittedWhileItWaitedForTheBankAccount(): void
    {
        $this->reconciliationScenario();

        $this->whileAnotherRequestCommits([$this->closingSql('2026-03-31')], function (): void {
            $refusal = $this->refusal(fn () => $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk));

            $this->assertSame(AccountingError::BankReconciliationDateInvalid, $refusal->error);
            $this->assertSame('2026-03-31', $refusal->context['until']);
        });

        $this->assertSame(1, $this->accountingDb()->table('bank_reconciliations')->countAllResults());
    }

    public function testReopen_RefusesAClosingThatWasReopenedWhileItWaitedForTheBankAccount(): void
    {
        $this->reconciliationScenario();
        $closing = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $db      = $this->accountingDb();

        $this->whileAnotherRequestCommits([
            'UPDATE ' . $db->prefixTable('bank_matches') . ' SET reconciliation_id = NULL WHERE reconciliation_id = ' . $closing,
            'UPDATE ' . $db->prefixTable('bank_reconciliations') . " SET status = 'reopened', reopen_reason = 'First' WHERE reconciliation_id = " . $closing,
        ], function () use ($closing): void {
            $this->assertSame(AccountingError::BankReconciliationReopened, $this->refusal(fn () => $this->bank->reopen($closing, 'Second', $this->bankClerk))->error);
        });

        $this->assertSame('First', $db->table('bank_reconciliations')->get()->getRow()->reopen_reason);
        $this->assertSame(0, $db->table('accounting_audit_log')->where('action', 'bank_reconciliation_reopened')->countAllResults());
    }

    public function testUnmatch_IsRefusedToWhoMayNotSeeTheAccountWithoutWaitingForTheLock(): void
    {
        [, , $matchId] = $this->matchedDeposit();
        $second        = $this->createBranch('B2', 'Second branch');
        $outsider      = $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE], [$second]);
        $other         = $this->holdTheBank($this->bankId);

        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');

        try {
            $this->assertSame(AccountingError::BankAccountNotFound, $this->refusal(fn () => $this->bank->unmatch($this->bankId, $matchId, $outsider))->error);
            $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->bank->unmatch($this->bankId, $matchId, $this->employeeWithGrants([])))->error);
        } finally {
            $other->transRollback();
            $other->close();
            $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 50');
        }

        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testOperations_LookAtTheAccountBeforeTheyLookAtWhatTheyWereGiven(): void
    {
        $second   = $this->createBranch('B2', 'Second branch');
        $outsider = $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE], [$second]);
        $nobody   = $this->employeeWithGrants([]);

        foreach ([[$outsider, AccountingError::BankAccountNotFound, AccountingError::BankReconciliationNotFound], [$nobody, AccountingError::PermissionDenied, AccountingError::PermissionDenied]] as [$employee, $account, $closing]) {
            $this->assertSame($account, $this->refusal(fn () => $this->bank->import($this->bankId, 'x.csv', "PK\x03\x04\0", StatementMapping::standard(), $employee))->error);
            $this->assertSame($account, $this->refusal(fn () => $this->bank->match($this->bankId, 1, [], $employee))->error);
            $this->assertSame($account, $this->refusal(fn () => $this->bank->close($this->bankId, 'not a date', 'not a balance', $employee))->error);
            $this->assertSame($closing, $this->refusal(fn () => $this->bank->reopen(987654, '', $employee))->error);
            $this->assertSame($account === AccountingError::PermissionDenied ? AccountingError::PermissionDenied : AccountingError::BankStatementNotFound, $this->refusal(fn () => $this->bank->deleteStatement(987654, $employee))->error);
            $this->assertSame($account, $this->refusal(fn () => $this->bank->report($this->bankId, 'not a date', null, $employee))->error);
        }
    }

    public function testMatch_TakesAtMostFiftyLinesAndTakesALineRepeatedOnce(): void
    {
        $line = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $statementLine = $this->statementLineId('Deposit A');

        $tooMany = $this->refusal(fn () => $this->bank->match($this->bankId, $statementLine, range($line, $line + BankMatches::MAX_LINES), $this->bankClerk));

        $this->assertSame(AccountingError::BankJournalLineInvalid, $tooMany->error);
        $this->assertSame(BankMatches::MAX_LINES, $tooMany->context['max']);

        $matchId = $this->bank->match($this->bankId, $statementLine, [$line, $line, (string) $line], $this->bankClerk);

        $this->assertSame(1, $this->accountingDb()->table('bank_match_lines')->where('match_id', $matchId)->countAllResults());
    }

    public function testMatch_TakesFiftyLinesThatAddUp(): void
    {
        $lines = [];

        for ($number = 1; $number <= BankMatches::MAX_LINES; $number++) {
            $lines[] = $this->bookLine('1.00', '2026-03-10', 'Small ' . $number);
        }

        $this->importStatement($this->statementText([['2026-03-10', 'Fifty deposits', '50.00', '', 'A']]));

        $matchId = $this->bank->match($this->bankId, $this->statementLineId('Fifty deposits'), $lines, $this->bankClerk);

        $this->assertSame(BankMatches::MAX_LINES, $this->accountingDb()->table('bank_match_lines')->where('match_id', $matchId)->countAllResults());
    }

    public function testMatch_KeepsAnAcceptedSuggestionAsSuggestedAndAnyOtherMethodAsManual(): void
    {
        $first  = $this->bookLine('100.00', '2026-03-10');
        $second = $this->bookLine('200.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A'], ['2026-03-10', 'Deposit B', '200.00', '', 'B']]));

        $suggested = $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$first], $this->bankClerk, BankMatches::SUGGESTED);
        $other     = $this->bank->match($this->bankId, $this->statementLineId('Deposit B'), [$second], $this->bankClerk, 'something else');

        $this->assertSame(['suggested', 'manual'], [$this->accountingDb()->table('bank_matches')->where('match_id', $suggested)->get()->getRow()->method, $this->accountingDb()->table('bank_matches')->where('match_id', $other)->get()->getRow()->method]);
    }

    public function testMatch_WritesTheBranchOfTheBankAccountOnTheMatchAndOnTheAuditEntry(): void
    {
        $second = $this->createBranch('B2', 'Second branch');
        $bank   = $this->openBank('Second branch bank', $second);
        $line   = $this->bookLine('100.00', '2026-03-10', 'Deposit', $bank);
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]), $bank);

        $matchId = $this->bank->match($bank, $this->statementLineId('Deposit A', $bank), [$line], $this->bankClerk);

        $this->assertSame($second, (int) $this->accountingDb()->table('bank_matches')->where('match_id', $matchId)->get()->getRow()->branch_id);
        $this->assertSame($second, (int) $this->accountingDb()->table('accounting_audit_log')->where(['action' => 'bank_matched', 'entity_id' => $matchId])->get()->getRow()->branch_id);
        $this->assertSame($second, (int) $this->accountingDb()->table('bank_statements')->get()->getRow()->branch_id);
    }

    public function testLines_FiltersByStateAndDatesNewestLastAndPages(): void
    {
        $line = $this->bookLine('1.00', '2026-03-01');
        $this->importStatement($this->statementText([['2026-03-03', 'Third', '3.00', '', 'C'], ['2026-03-01', 'First', '1.00', '', 'A'], ['2026-03-02', 'Second', '2.00', '', 'B']]));
        $this->bank->match($this->bankId, $this->statementLineId('First'), [$line], $this->bankClerk);

        $all       = $this->bank->lines($this->bankId, $this->bankClerk);
        $matched   = $this->bank->lines($this->bankId, $this->bankClerk, 'matched');
        $unmatched = $this->bank->lines($this->bankId, $this->bankClerk, 'unmatched');
        $window    = $this->bank->lines($this->bankId, $this->bankClerk, 'all', '2026-03-02', '2026-03-02');
        $page      = $this->bank->lines($this->bankId, $this->bankClerk, 'all', null, null, 1, 1);

        $this->assertSame(['First', 'Second', 'Third'], array_column($all['rows'], 'description'));
        $this->assertSame(3, $all['total']);
        $this->assertSame(['First'], array_column($matched['rows'], 'description'));
        $this->assertSame(['Second', 'Third'], array_column($unmatched['rows'], 'description'));
        $this->assertSame(['Second'], array_column($window['rows'], 'description'));
        $this->assertSame([3, ['Second']], [$page['total'], array_column($page['rows'], 'description')]);
        $this->assertSame(3, $this->bank->lines($this->bankId, $this->bankClerk, 'all', 'garbage', 'garbage')['total']);
    }

    public function testStatements_ListsTheStatementsOfTheAccountNewestFirstWithWhoImportedThem(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'One', '1.00', '', 'A']]), name: 'first.csv');
        $this->importStatement($this->statementText([['2026-03-02', 'Two', '2.00', '', 'B']]), name: 'second.csv');
        $other = $this->openBank();
        $this->importStatement($this->statementText([['2026-03-03', 'Three', '3.00', '', 'C']]), $other, 'other.csv');

        $statements = $this->bank->statements($this->bankId, $this->bankClerk);

        $this->assertSame(['second.csv', 'first.csv'], array_column($statements, 'file_name'));
        $clerk = model(Employee::class)->get_info($this->bankClerk);

        $this->assertSame([trim($clerk->first_name . ' ' . $clerk->last_name)], array_unique(array_column($statements, 'imported_by_name')));
    }

    public function testClosings_ListsTheLatestFirstAndFlagsOnlyAClosedOneThatDrifted(): void
    {
        $this->reconciliationScenario();
        $first = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->importStatement($this->statementText([['2026-04-10', 'April line', '10.00', '', 'AP']]), name: 'april.csv');
        $this->bookLine('10.00', '2026-04-10', 'April book');
        $this->bank->match($this->bankId, $this->statementLineId('April line'), [(int) $this->accountingDb()->table('accounting_journal_lines')->where('debit', '10.0000')->get()->getRow()->line_id], $this->bankClerk);
        $second = $this->bank->close($this->bankId, '2026-04-30', '1160.00', $this->bankClerk);
        $this->bank->reopen($second, 'April again', $this->bankClerk);
        $this->bookLine('3.00', '2026-04-15', 'Late April');

        $closings = $this->bank->closings($this->bankId, $this->bankClerk);

        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->reconciliation_id, $closings));
        $this->assertSame(['reopened', 'closed'], array_column($closings, 'status'));
        $this->assertSame([false, false], array_map(static fn (object $row): bool => $row->drifted, $closings));
    }

    public function testImport_RefusesALineDatedOnTheDayTheReconciliationIsClosedUpTo(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'Opening deposit', '100.00', '', 'A']]));
        $this->bank->close($this->bankId, '2026-03-31', '100.00', $this->bankClerk);

        $refusal = $this->refusal(fn () => $this->importStatement($this->statementText([['2026-03-31', 'On the day', '5.00', '', 'L']]), name: 'edge.csv'));

        $this->assertSame(AccountingError::BankStatementClosedRange, $refusal->error);
        $this->assertTrue($this->importStatement($this->statementText([['2026-04-01', 'The day after', '5.00', '', 'M']]), name: 'next.csv')->created());
    }

    public function testMatch_RefusesALineOfTheBooksAndALineOfTheBankDatedOnTheDayTheReconciliationIsClosedUpTo(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'Opening deposit', '100.00', '', 'A'], ['2026-03-31', 'Last day', '40.00', '', 'B']]));
        $this->bank->close($this->bankId, '2026-03-31', '140.00', $this->bankClerk);
        $line = $this->bookLine('40.00', '2026-03-31', 'Last day book');

        $refusal = $this->refusal(fn () => $this->bank->match($this->bankId, $this->statementLineId('Last day'), [$line], $this->bankClerk));

        $this->assertSame(AccountingError::BankMatchClosedRange, $refusal->error);
    }

    public function testMatch_AcceptsAStatementLineInsideTheClosedDatesWhenTheLineOfTheBooksIsDatedAfterThem(): void
    {
        $this->importStatement($this->statementText([['2026-03-01', 'Opening deposit', '100.00', '', 'A'], ['2026-03-20', 'Fee', '', '5.00', 'B']]));
        $this->bank->close($this->bankId, '2026-03-31', '95.00', $this->bankClerk);
        $late = $this->bookLine('-5.00', '2026-04-02', 'Fee booked late');

        $matchId = $this->bank->match($this->bankId, $this->statementLineId('Fee'), [$late], $this->bankClerk);

        $this->assertNull($this->accountingDb()->table('bank_matches')->where('match_id', $matchId)->get()->getRow()->reconciliation_id);
    }

    public function testReport_CarriesEveryLineOfTheBooksWhileNoStatementLineIsImported(): void
    {
        $this->bookLine('100.00', '2026-03-10', 'Deposit');
        $this->bookLine('-30.00', '2026-03-12', 'Payment');

        $report = $this->bank->report($this->bankId, '2026-03-31', '70.00', $this->bankClerk);

        $this->assertNull($report->coverageStart);
        $this->assertSame([0, 2, '70.0000'], [$report->outstandingBook['count'], $report->carriedBook['count'], $report->carriedBook['total']]);
        $this->assertSame('0.0000', $report->difference);
    }

    public function testReport_TakesALineOfTheBooksDatedOnTheFirstStatementDayAsOutstandingAndOneTheDayBeforeAsCarried(): void
    {
        $this->bookLine('10.00', '2026-03-01', 'The day before');
        $this->bookLine('20.00', '2026-03-02', 'The first day');
        $this->importStatement($this->statementText([['2026-03-02', 'First line', '5.00', '', 'A']]));

        $report = $this->bank->report($this->bankId, '2026-03-31', null, $this->bankClerk);

        $this->assertSame(['The first day'], array_column($report->bookLines, 'description'));
        $this->assertSame(['The day before'], array_column($report->carriedLines, 'description'));
    }

    public function testReport_ShowsAStatementLineAsNotYetInTheBooksUntilTheDateOfTheLineOfTheBooksItIsMatchedWith(): void
    {
        $this->importStatement($this->statementText([['2026-03-05', 'Fee', '', '5.00', 'F']]));
        $late = $this->bookLine('-5.00', '2026-04-02', 'Fee booked late');
        $this->bank->match($this->bankId, $this->statementLineId('Fee'), [$late], $this->bankClerk);

        $march = $this->bank->report($this->bankId, '2026-03-31', null, $this->bankClerk);
        $april = $this->bank->report($this->bankId, '2026-04-30', null, $this->bankClerk);

        $this->assertSame(['Fee'], array_column($march->statementLines, 'description'));
        $this->assertSame('-5.0000', $march->unmatchedStatement['total']);
        $this->assertSame([], $april->statementLines);
        $this->assertSame(1, $april->matchesMade);
    }

    public function testMatch_LeavesOutAnyIdThatIsNotAPositiveWholeNumber(): void
    {
        $line = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));

        $matchId = $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [0, -3, $line, 'x'], $this->bankClerk);

        $this->assertSame([$line], array_map('intval', array_column($this->accountingDb()->table('bank_match_lines')->where('match_id', $matchId)->get()->getResultArray(), 'journal_line_id')));
    }

    public function testReopen_IsNotFoundForAClosingOfABankAccountOutOfScopeAndLeavesItClosed(): void
    {
        $second = $this->createBranch('B2', 'Second branch');
        $bank   = $this->openBank('Second branch bank', $second);
        $this->importStatement($this->statementText([['2026-03-10', 'Line', '5.00', '', 'A']]), $bank);
        $closing  = $this->bank->close($bank, '2026-03-31', '5.00', $this->bankClerk);
        $outsider = $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE], [1]);

        $refusal = $this->refusal(fn () => $this->bank->reopen($closing, 'Because', $outsider));

        $this->assertSame(AccountingError::BankReconciliationNotFound, $refusal->error);
        $this->assertSame('closed', $this->accountingDb()->table('bank_reconciliations')->where('reconciliation_id', $closing)->get()->getRow()->status);
        $this->assertSame(AccountingError::BankAccountNotFound, $this->refusal(fn () => $this->bank->close($bank, '2026-04-30', '5.00', $outsider))->error);
    }

    public function testLinesToMatch_ListsTheDebitsForADepositAndTheCreditsForAWithdrawalAndLeavesOutWhatAMatchHolds(): void
    {
        $debit   = $this->bookLine('50.00', '2026-03-10', 'Debit');
        $credit  = $this->bookLine('-50.00', '2026-03-10', 'Credit');
        $matched = $this->bookLine('50.00', '2026-03-10', 'Debit matched');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit line', '50.00', '', 'D'], ['2026-03-10', 'Withdrawal line', '', '50.00', 'W'], ['2026-03-11', 'Held deposit', '50.00', '', 'H']]));
        $this->bank->match($this->bankId, $this->statementLineId('Held deposit'), [$matched], $this->bankClerk);

        $deposit    = $this->bank->linesToMatch($this->bankId, $this->statementLineId('Deposit line'), $this->bankClerk);
        $withdrawal = $this->bank->linesToMatch($this->bankId, $this->statementLineId('Withdrawal line'), $this->bankClerk);

        $this->assertSame([$debit], array_map(static fn (object $line): int => (int) $line->line_id, $deposit['lines']));
        $this->assertSame([$credit], array_map(static fn (object $line): int => (int) $line->line_id, $withdrawal['lines']));
        $this->assertTrue($deposit['lines'][0]->exact);
        $this->assertTrue($deposit['lines'][0]->near);
    }

    public function testForLine_KeepsTheLinesOfTheAmountAndThenTheNearestInDateWhenTheLimitCutsTheListAndSaysThereAreMore(): void
    {
        foreach (['2026-01-10' => '11.00', '2026-02-10' => '12.00', '2026-03-10' => '13.00', '2026-04-10' => '14.00', '2026-05-10' => '15.00', '2026-06-10' => '16.00'] as $date => $amount) {
            $this->bookLine($amount, $date, 'Deposit ' . $amount);
        }

        $this->importStatement($this->statementText([['2026-01-12', 'Oldest amount', '11.00', '', 'A'], ['2026-02-12', 'Other amount', '99.00', '', 'B']]));

        $suggestions = new BankSuggestions();
        $amounts     = static fn (array $found): array => array_map(static fn (object $line): string => (string) $line->amount, $found['lines']);

        $first = $suggestions->forLine($this->bankId, $this->statementLineId('Oldest amount'), $this->bankClerk, null, 3);
        $other = $suggestions->forLine($this->bankId, $this->statementLineId('Other amount'), $this->bankClerk, null, 3);

        $this->assertSame(['11.0000', '12.0000', '13.0000'], $amounts($first));
        $this->assertTrue($first['more']);
        $this->assertSame(['12.0000', '13.0000', '11.0000'], $amounts($other));
        $this->assertTrue($other['more']);
        $this->assertTrue($suggestions->forLine($this->bankId, $this->statementLineId('Oldest amount'), $this->bankClerk, null, 5)['more']);

        $all = $suggestions->forLine($this->bankId, $this->statementLineId('Oldest amount'), $this->bankClerk, null, 6);

        $this->assertCount(6, $all['lines']);
        $this->assertFalse($all['more']);
    }

    public function testReport_LeavesADraftJournalOutOfTheBalanceTheOpenLinesTheSuggestionsAndTheChoiceList(): void
    {
        $posted  = $this->bookLine('100.00', '2026-03-10', 'Posted deposit');
        $account = (int) $this->bankTreasuries->accountOf($this->bankId);
        $draft   = (new JournalService())->saveDraft(['date' => '2026-03-10', 'description' => 'Draft deposit', 'lines' => [
            ['account_id' => $account, 'debit' => '77.00', 'credit' => ''],
            ['account_id' => $this->accountId('opening_balance'), 'debit' => '', 'credit' => '77.00'],
        ]], null);
        $this->importStatement($this->statementText([['2026-03-10', 'Draft amount', '77.00', '', 'D']]));

        $report = $this->bank->report($this->bankId, '2026-03-31', null, $this->bankClerk);
        $choice = $this->bank->linesToMatch($this->bankId, $this->statementLineId('Draft amount'), $this->bankClerk);
        $ids    = static fn (array $lines): array => array_map(static fn (object $line): int => (int) $line->line_id, $lines);

        $this->assertSame('100.0000', $report->bookBalance);
        $this->assertSame($this->bankTreasuries->position($this->bankId)->balance, $report->bookBalance);
        $this->assertSame([$posted], $ids($report->bookLines));
        $this->assertSame(1, $report->outstandingBook['count']);
        $this->assertSame([$posted], $ids($choice['lines']));
        $this->assertSame([], $this->bank->suggestions($this->bankId, $this->bankClerk));
        $this->assertNotSame($posted, $this->lineOf($draft, $account));
    }

    public function testImport_KeepsTheChecksumAndTheKnownLinesPerBankAccount(): void
    {
        $second = $this->openBank();
        $fee    = ['2026-03-01', 'Monthly fee', '', '5.00', 'FEE'];

        $first = $this->importStatement($this->statementText([$fee]));
        $same  = $this->importStatement($this->statementText([$fee]), $second);

        $this->assertSame([ImportResult::IMPORTED, ImportResult::IMPORTED], [$first->status, $same->status]);

        $wider = $this->importStatement($this->statementText([$fee, ['2026-03-02', 'Transfer', '10.00', '', 'T']]), $this->openBank());

        $this->assertSame([2, 0], [$wider->imported, $wider->duplicates]);

        $overlap = $this->importStatement($this->statementText([$fee, ['2026-03-03', 'Interest', '1.00', '', 'I']]), $second);

        $this->assertSame([1, 1], [$overlap->imported, $overlap->duplicates]);
        $this->assertSame([1, 2], [$this->lineCount(), $this->lineCount($second)]);
        $this->assertSame(1, $this->accountingDb()->table('bank_statements')->where('treasury_id', $this->bankId)->countAllResults());
    }

    /**
     * The file of a case as the importer reads it. The fixture writes the dates as day and month and the
     * importer takes a date only with a four-digit year, so the year of the observation is added.
     *
     * @param array{columns: list<string>, rows: list<array{date: string, amount: string, side: string}>} $file
     */
    private function observedFile(array $file): string
    {
        $lines = [implode(',', $file['columns'])];

        foreach ($file['rows'] as $row) {
            $lines[] = implode(',', [$row['date'] . '/2026', '', $row['side'] === 'deposit' ? $row['amount'] : '', $row['side'] === 'withdrawal' ? $row['amount'] : '', '']);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, mixed> $case
     */
    private function importObservedFile(array $case): ImportResult
    {
        return $this->importStatement($this->observedFile($case['inputs']['file']), name: 'observed.csv');
    }

    /**
     * @return list<int> The ids of the lines of the bank account in the order of the file.
     */
    private function lineIds(): array
    {
        return array_map(static fn (object $row): int => (int) $row->line_id, $this->bank->lines($this->bankId, $this->bankClerk)['rows']);
    }

    /**
     * The status each line of the bank account shows, in the words of the language in use: the state the
     * line falls under when the lines are filtered by state.
     *
     * @return array<int, string>
     */
    private function rowStatuses(): array
    {
        $statuses = [];

        foreach (['unmatched', 'matched'] as $state) {
            foreach ($this->bank->lines($this->bankId, $this->bankClerk, $state)['rows'] as $row) {
                $statuses[(int) $row->line_id] = lang('Accounting_bank.state_' . $state);
            }
        }

        ksort($statuses);

        return $statuses;
    }

    private function inArabic(callable $action): mixed
    {
        $language = Services::language();
        $previous = $language->getLocale();
        $language->setLocale('ar-EG');

        try {
            return $action();
        } finally {
            $language->setLocale($previous);
        }
    }

    /**
     * @return array{int, int} The journals and the lines of journals in the books.
     */
    private function journalCounts(): array
    {
        return [$this->countRows('accounting_journals'), $this->countRows('accounting_journal_lines')];
    }

    public function testLines_GivesARowOneOfTwoStatusesInOtherWordsThanTheReferenceSystem(): void
    {
        $case = $this->referenceCase('T-63', 'bank_statement', 'import-a-bank-statement-and-match-its-rows', 'differs');

        $asWritten = $this->refusal(fn () => $this->importStatement(str_replace('/2026', '', $this->observedFile($case['inputs']['file'])), name: 'as-written.csv'));

        $this->assertInstanceOf(StatementRejected::class, $asWritten);
        $this->assertSame(array_fill(0, 3, StatementRowError::DATE_INVALID), array_map(static fn (StatementRowError $error): string => $error->reason, $asWritten->errors));
        $this->assertSame(0, $this->lineCount());

        $result = $this->importObservedFile($case);
        $rows   = $this->bank->lines($this->bankId, $this->bankClerk)['rows'];
        $ids    = $this->lineIds();

        $this->assertSame([3, 3], [$result->rowCount, $result->imported]);
        $this->assertSame(
            array_map(static fn (array $row): string => ($row['side'] === 'deposit' ? '' : '-') . $row['amount'], $case['inputs']['file']['rows']),
            array_map(static fn (object $row): string => bcadd((string) $row->amount, '0', 2), $rows),
        );
        $this->assertSame(['2026-10-27', '2026-10-27', '2026-11-05'], array_column($rows, 'line_date'));

        $afterImport = $this->inArabic(fn (): array => $this->rowStatuses());

        $this->bank->match($this->bankId, $ids[2], [$this->bookLine('360.00', '2026-11-05')], $this->bankClerk);

        $afterMatch = $this->inArabic(fn (): array => $this->rowStatuses());

        $this->assertSame($ids, array_keys($afterImport));
        $this->assertCount(1, array_unique($afterImport));
        $this->assertSame($ids, array_keys($afterMatch));
        $this->assertSame([$ids[2]], array_keys(array_diff($afterMatch, $afterImport)));
        $this->assertSame(['Accounting_bank.state_unmatched', 'Accounting_bank.state_matched'], $case['expected']['detail']['language_keys']);
        $this->assertSameAsFixture($case['expected'], ['row_statuses' => array_values(array_unique([...$afterImport, ...$afterMatch]))], 'statuses of a row');
    }

    public function testMatch_AcceptsRowsWhoseAmountEqualsTheJournalsChosenAndRejectsEveryOtherSum(): void
    {
        $case     = $this->referenceCase('T-63', 'bank_statement', 'a-row-is-matched-when-its-amount-equals-the-journals-chosen', 'exact');
        $accepted = [];
        $refused  = [];

        foreach ($case['inputs']['probes'] as $number => $probe) {
            $sign      = $probe['side'] === 'deposit' ? '' : '-';
            $isDeposit = $probe['side'] === 'deposit';

            $this->importStatement($this->statementText([['2026-10-27', 'Probe ' . $number, $isDeposit ? $probe['amount'] : '', $isDeposit ? '' : $probe['amount'], '']]), name: 'probe-' . $number . '.csv');

            $journalLines = array_map(fn (string $amount): int => $this->bookLine($sign . $amount, '2026-10-27'), $probe['journals']);
            $total        = array_reduce($probe['journals'], static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0');
            $equal        = bccomp($total, $probe['amount'], 4) === 0;

            try {
                $this->bank->match($this->bankId, $this->statementLineId('Probe ' . $number), $journalLines, $this->bankClerk);
                $accepted[$number] = true;
            } catch (AccountingException $exception) {
                $refused[$number] = $exception->error;
            }

            $this->assertSame($equal, isset($accepted[$number]), 'probe ' . $number);
        }

        $this->assertCount($case['expected']['detail']['accepted'], $accepted);
        $this->assertCount($case['expected']['detail']['refused'], $refused);
        $this->assertSame([AccountingError::BankMatchAmountMismatch], array_values(array_unique($refused, SORT_REGULAR)));
        $this->assertSame('bank_match_amount_mismatch', $case['expected']['detail']['refused_with']);
        $this->assertSame(count($accepted), $this->accountingDb()->table('bank_matches')->countAllResults());
        $this->assertSameAsFixture($case['expected'], ['match_rule' => 'the amount of the row equals the amount of the journals chosen'], 'match rule');
    }

    public function testMatch_RejectsAnAmountThatDiffersWithTheWordsOfTheLanguageInUseWhereTheReferenceSystemUsesOthers(): void
    {
        $case = $this->referenceCase('T-63', 'bank_statement', 'a-row-is-refused-when-its-amount-differs-from-the-journals-chosen', 'differs');
        $this->importObservedFile($case);
        $row         = $this->lineIds()[1];
        $journalLine = $this->bookLine('-' . $case['inputs']['journal']['amount'], '2026-10-27');

        $refusal = $this->refusal(fn () => $this->bank->match($this->bankId, $row, [$journalLine], $this->bankClerk));
        $message = $this->inArabic(static fn (): string => AccountingMessage::of($refusal));

        $this->assertSame(AccountingError::BankMatchAmountMismatch, $refusal->error);
        $this->assertSame($case['expected']['detail']['error'], $refusal->error->value);
        $this->assertSame($case['expected']['detail']['language_key'], 'Accounting.error_' . $refusal->error->value);
        $this->assertSame($case['expected']['detail']['difference'], $refusal->context['difference']);
        $this->assertSame($case['expected']['detail']['matches_made'], $this->accountingDb()->table('bank_matches')->countAllResults());
        $this->assertSameAsFixture($case['expected'], ['refusal_message' => $message], 'refusal of a different amount');
    }

    public function testImport_PostsNoJournalAndNeitherDoMatchUnmatchOrDeletingTheStatement(): void
    {
        $case        = $this->referenceCase('T-63', 'bank_statement', 'import-match-unmatch-and-delete-post-no-journal', 'exact');
        $journalLine = $this->bookLine($case['inputs']['journal']['amount'], '2026-11-05');
        $lastNumber  = (string) $this->accountingDb()->table('accounting_journals')->where('journal_id', $this->journalOfLine($journalLine))->get()->getRow()->number;
        $before      = $this->journalCounts();
        $afterStep   = [];

        $result = $this->importObservedFile($case);
        $row    = $this->lineIds()[2];

        $afterStep['import'] = $this->journalCounts();

        $matchId = $this->bank->match($this->bankId, $row, [$journalLine], $this->bankClerk);

        $afterStep['match'] = $this->journalCounts();

        $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk);

        $afterStep['unmatch'] = $this->journalCounts();

        $this->bank->deleteStatement((int) $result->statementId, $this->bankClerk);

        $afterStep['delete'] = $this->journalCounts();

        $next = $this->bookLine('1.00', '2026-11-06');

        $this->assertSame(['import', 'match', 'unmatch', 'delete'], array_keys($afterStep));
        $this->assertSame(0, $this->lineCount());
        $this->assertSame(sprintf('%06d', (int) $lastNumber + 1), $this->accountingDb()->table('accounting_journals')->where('journal_id', $this->journalOfLine($next))->get()->getRow()->number);
        $this->assertSameAsFixture($case['expected'], ['import_match_unmatch_and_delete_post_a_journal' => array_filter($afterStep, static fn (array $counts): bool => $counts !== $before) !== []], 'journals posted by the statement');
    }

    public function testUnmatch_ReturnsAMatchedRowToNotMatchedAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-63', 'bank_statement', 'a-matched-row-can-be-unmatched', 'exact');
        $this->importObservedFile($case);
        $row         = $this->lineIds()[2];
        $journalLine = $this->bookLine($case['inputs']['journal']['amount'], '2026-11-05');
        $matchId     = $this->bank->match($this->bankId, $row, [$journalLine], $this->bankClerk);

        $this->assertSame([$row], array_map(static fn (object $line): int => (int) $line->line_id, $this->bank->lines($this->bankId, $this->bankClerk, 'matched')['rows']));

        $possible = true;

        try {
            $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk);
        } catch (AccountingException) {
            $possible = false;
        }

        $this->assertSame(0, $this->bank->lines($this->bankId, $this->bankClerk, 'matched')['total']);
        $this->assertSame(3, $this->bank->lines($this->bankId, $this->bankClerk, 'unmatched')['total']);
        $this->assertSame('not matched', $case['expected']['detail']['the_row_goes_back_to']);
        $this->assertTrue($case['expected']['detail']['the_journal_is_free_to_be_matched_again']);
        $this->assertGreaterThan($matchId, $this->bank->match($this->bankId, $row, [$journalLine], $this->bankClerk));
        $this->assertSameAsFixture($case['expected'], ['unmatch_a_row' => $possible], 'unmatch of a row');
    }

    public function testDeleteStatement_TakesOutTheWholeStatementWhereTheReferenceSystemDeletesARowOnItsOwn(): void
    {
        $case   = $this->referenceCase('T-63', 'bank_statement', 'a-row-can-be-deleted-on-its-own', 'differs');
        $result = $this->importObservedFile($case);

        $rowDeleters = array_filter(
            (new ReflectionClass(BankReconciliation::class))->getMethods(ReflectionMethod::IS_PUBLIC),
            static fn (ReflectionMethod $method): bool => preg_match('/delete|remove/i', $method->getName()) === 1
                && array_filter($method->getParameters(), static fn (ReflectionParameter $parameter): bool => stripos($parameter->getName(), 'line') !== false) !== [],
        );

        $matchId = $this->bank->match($this->bankId, $this->lineIds()[2], [$this->bookLine('360.00', '2026-11-05')], $this->bankClerk);
        $refusal = $this->refusal(fn () => $this->bank->deleteStatement((int) $result->statementId, $this->bankClerk));

        $this->assertSame($case['expected']['detail']['refused_with'], $refusal->error->value);
        $this->assertSame(3, $this->lineCount());

        $this->bank->unmatch($this->bankId, $matchId, $this->bankClerk);
        $this->bank->deleteStatement((int) $result->statementId, $this->bankClerk);

        $this->assertSame([], $this->lineIds());
        $this->assertSame($case['expected']['detail']['rows_left_after_the_delete'], $this->lineCount());
        $this->assertSame($case['expected']['detail']['the_file_can_be_imported_again'], $this->importObservedFile($case)->created());
        $this->assertSameAsFixture($case['expected'], ['delete_a_row' => $rowDeleters !== []], 'delete of a row on its own');
    }
}
