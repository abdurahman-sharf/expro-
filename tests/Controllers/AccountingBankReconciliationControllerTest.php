<?php

namespace Tests\Controllers;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Bank\BankDirectory;
use App\Libraries\Accounting\Bank\BankMatches;
use App\Libraries\Accounting\Bank\StatementMapping;
use App\Libraries\Accounting\Bank\StatementParser;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BankFixtureTrait;
use Tests\Support\DatabaseFingerprintTrait;
use Tests\Support\EmployeeFixtureTrait;

/**
 * The screens of the reconciliation of bank accounts: who may open them and what they show, the import
 * of a statement from a file or pasted text, the matches, the suggestions, the reconciliation statement
 * and its closing, and that a bank account out of the scope of the employee is a page that is not there.
 */
class AccountingBankReconciliationControllerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use BankFixtureTrait;
    use DatabaseFingerprintTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const BASE = '/accounting_treasuries/bank';

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

    /**
     * @param list<string>   $extra
     * @param list<int>|null $branchIds
     */
    private function actAsReconciler(array $extra = [], ?array $branchIds = null): int
    {
        $personId = $this->employeeWithGrants(['accounting_treasuries', AccountingGuard::BANK_RECONCILE, ...$extra], $branchIds);

        $this->withSession(['person_id' => $personId, 'menu_group' => 'home']);

        return $personId;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $url, array $data = []): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            $value = (string) $value;
        });

        return json_decode($this->post($url, $data)->getJSON(), true);
    }

    private function accountUrl(string $path = '', ?int $treasuryId = null): string
    {
        return self::BASE . '/' . ($treasuryId ?? $this->bankId) . $path;
    }

    private function importPasted(string $text): array
    {
        return $this->json($this->accountUrl('/import'), ['statement_text' => $text, 'delimiter' => 'comma', 'skip_first_row' => '1', 'date_column' => '1', 'description_column' => '2', 'deposit_column' => '3', 'withdrawal_column' => '4', 'reference_column' => '5', 'date_order' => 'dmy']);
    }

    /**
     * @return array<string, int>
     */
    private function scenario(): array
    {
        $lines = [
            'opening' => $this->bookLine('1000.00', '2026-01-15', 'Opening funds'),
            'paid_in' => $this->bookLine('200.00', '2026-03-01', 'Customer A'),
            'cheque'  => $this->bookLine('-300.00', '2026-03-20', 'Cheque 501'),
            'transit' => $this->bookLine('75.00', '2026-03-28', 'Deposit in transit'),
        ];

        $this->importStatement($this->statementText([['2026-03-02', 'Customer A transfer', '200.00', '', 'INV-1'], ['2026-03-05', 'Bank fee', '', '50.00', 'FEE-3']]));
        $this->bank->match($this->bankId, $this->statementLineId('Customer A transfer'), [$lines['paid_in']], $this->bankClerk);

        return $lines;
    }

    public function testGetIndex_ListsTheBankAccountsToWhoHoldsTheModuleAndThePermission(): void
    {
        $this->actAsReconciler();
        $this->bankTreasuries->create(['name' => 'Cash box', 'kind' => 'cash', 'branch_id' => 0], $this->bankClerk);

        $response = $this->get(self::BASE);

        $response->assertStatus(200);
        $response->assertSee(lang('Accounting_bank.title'));
        $response->assertSee('Bank 1');
        $response->assertSee(BankDirectory::nameOf('SA_RIYAD', service('language')->getLocale()));
        $response->assertSee(base_url('accounting_treasuries/bank/' . $this->bankId));
        $response->assertDontSee('Cash box');
        $response->assertDontSee('bank_accounts_empty');
    }

    public function testGetIndex_SaysThereIsNothingToReconcileWhenNoBankAccountIsInScope(): void
    {
        $second = $this->createBranch('B2', 'Second branch');
        $this->actAsReconciler([], [$second]);

        $response = $this->get(self::BASE);

        $response->assertStatus(200);
        $response->assertSee('bank_accounts_empty');
        $response->assertDontSee('Bank 1');
    }

    public function testGetIndex_IsNotFoundToWhoHoldsTheModuleButNotThePermissionToReconcile(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_treasuries', AccountingGuard::TREASURIES_MANAGE]), 'menu_group' => 'home']);

        $this->expectException(PageNotFoundException::class);

        $this->get(self::BASE);
    }

    public function testGetIndex_RedirectsAUserWithoutTheModuleGrant(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants([AccountingGuard::BANK_RECONCILE]), 'menu_group' => 'home']);

        $this->get(self::BASE)->assertRedirect();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function pageProvider(): array
    {
        return [
            'accounts'    => [''],
            'account'     => ['/{bank}'],
            'import'      => ['/{bank}/import'],
            'lines'       => ['/{bank}/lines'],
            'matches'     => ['/{bank}/matches'],
            'suggestions' => ['/{bank}/suggestions'],
            'report'      => ['/{bank}/report'],
            'sample'      => ['/sample'],
        ];
    }

    #[DataProvider('pageProvider')]
    public function testGet_IsNotFoundWhileAccountingIsOff(string $path): void
    {
        $this->actAsReconciler();
        $this->setConfigValue('accounting_enabled', '0');

        $this->expectException(PageNotFoundException::class);

        $this->get(self::BASE . str_replace('{bank}', (string) $this->bankId, $path));
    }

    public function testPosts_AreNotFoundWhileAccountingIsOffAndChangeNothing(): void
    {
        $this->actAsReconciler();
        $this->setConfigValue('accounting_enabled', '0');
        $before = $this->databaseFingerprint();

        $this->expectException(PageNotFoundException::class);

        try {
            $this->post($this->accountUrl('/import'), ['statement_text' => $this->statementText([['2026-03-01', 'Row', '1.00', '', 'R']])]);
        } finally {
            $this->assertDatabaseUnchanged($before);
        }
    }

    public function testGetIndex_ListsOnlyTheBankAccountsOfTheBranchesOfTheEmployee(): void
    {
        $second = $this->createBranch('B2', 'Second branch');
        $this->openBank('Elsewhere bank', $second);
        $this->actAsReconciler([], [1]);

        $response = $this->get(self::BASE);

        $response->assertSee('Bank 1');
        $response->assertDontSee('Elsewhere bank');
    }

    public function testGetIndex_FlagsABankAccountThatLacksItsDetailsAndRewritesNothing(): void
    {
        $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bankId)->update(['bank_name' => null, 'bank_code' => null, 'account_number' => null]);
        $before = $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bankId)->get()->getRowArray();
        $this->actAsReconciler();

        $index   = $this->get(self::BASE);
        $account = $this->get($this->accountUrl());

        $index->assertSee('bank-details-missing');
        $index->assertSee(lang('Accounting_treasuries.bank_details_missing'));
        $account->assertSee('bank-details-missing');
        $this->assertSame($before, $this->accountingDb()->table('treasuries')->where('treasury_id', $this->bankId)->get()->getRowArray());
    }

    public function testGetIndex_DoesNotFlagABankAccountThatHasItsDetails(): void
    {
        $this->actAsReconciler();

        $this->get(self::BASE)->assertDontSee('bank-details-missing');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountPageProvider(): array
    {
        return ['account' => [''], 'import' => ['/import'], 'lines' => ['/lines'], 'matches' => ['/matches'], 'suggestions' => ['/suggestions'], 'report' => ['/report'], 'match' => ['/match/1']];
    }

    #[DataProvider('accountPageProvider')]
    public function testGet_IsNotFoundForACashTreasuryAnUnknownIdAndABankAccountOutOfScope(string $path): void
    {
        $second   = $this->createBranch('B2', 'Second branch');
        $outside  = $this->openBank('Elsewhere bank', $second);
        $cash     = $this->bankTreasuries->create(['name' => 'Cash box', 'kind' => 'cash', 'branch_id' => 0], $this->bankClerk);
        $this->actAsReconciler([], [1]);
        $notFound = 0;

        foreach ([$cash, 987654, $outside] as $id) {
            try {
                $this->get(self::BASE . '/' . $id . $path);
            } catch (PageNotFoundException) {
                $notFound++;
            }
        }

        $this->assertSame(3, $notFound);
    }

    public function testPosts_AreRefusedForABankAccountOutOfScopeAndChangeNothing(): void
    {
        $second  = $this->createBranch('B2', 'Second branch');
        $outside = $this->openBank('Elsewhere bank', $second);
        $line    = $this->bookLine('100.00', '2026-03-10', 'Deposit', $outside);
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]), $outside);
        $statementLine = $this->statementLineId('Deposit A', $outside);
        $match         = $this->bank->match($outside, $statementLine, [$line], $this->bankClerk);
        $this->actAsReconciler([], [1]);
        $before = $this->databaseFingerprint();

        $results = [
            $this->json($this->accountUrl('/match', $outside), ['line_id' => $statementLine, 'journal_line_ids' => [$line]]),
            $this->json($this->accountUrl('/unmatch/' . $match, $outside)),
            $this->json($this->accountUrl('/close', $outside), ['through' => '2026-03-31', 'statement_balance' => '100.00']),
            $this->json(self::BASE . '/statement_delete/' . (int) $this->accountingDb()->table('bank_statements')->get()->getRow()->statement_id),
            $this->json(self::BASE . '/reopen/1', ['reason' => 'Because']),
        ];

        foreach ($results as $index => $result) {
            $this->assertFalse($result['success'], (string) $index);
            $this->assertNotSame('', $result['message']);
        }

        $this->assertSame(lang('Accounting.error_bank_account_not_found'), $results[0]['message']);
        $this->assertDatabaseUnchanged($before);
    }

    public function testPostImport_IsNotFoundForABankAccountOutOfScopeAndStoresNothing(): void
    {
        $second  = $this->createBranch('B2', 'Second branch');
        $outside = $this->openBank('Elsewhere bank', $second);
        $this->actAsReconciler([], [1]);
        $before = $this->databaseFingerprint();

        try {
            $this->post($this->accountUrl('/import', $outside), ['statement_text' => $this->statementText([['2026-03-12', 'Intruder', '9.00', '', 'Z']]), 'date_column' => '1', 'deposit_column' => '3', 'withdrawal_column' => '4']);
            $this->fail('The import was not refused.');
        } catch (PageNotFoundException) {
            $this->assertDatabaseUnchanged($before);
        }
    }

    public function testPosts_AreNotFoundToWhoLacksThePermissionToReconcileAndChangeNothing(): void
    {
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_treasuries', AccountingGuard::TREASURIES_MANAGE]), 'menu_group' => 'home']);
        $before = $this->databaseFingerprint();
        $caught = 0;

        foreach ([[$this->accountUrl('/import'), ['statement_text' => 'x']], [$this->accountUrl('/match'), []], [$this->accountUrl('/unmatch/1'), []], [$this->accountUrl('/close'), []], [self::BASE . '/reopen/1', []], [self::BASE . '/statement_delete/1', []], [self::BASE . '/window', ['days' => '3']]] as [$url, $data]) {
            try {
                $this->post($url, $data);
            } catch (PageNotFoundException) {
                $caught++;
            }
        }

        $this->assertSame(7, $caught);
        $this->assertDatabaseUnchanged($before);
    }

    public function testGetAccount_ShowsTheBankTheStatementsTheClosingsAndTheWindow(): void
    {
        $this->scenario();
        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl());

        $response->assertStatus(200);
        $response->assertSee('Bank 1');
        $response->assertSee(BankDirectory::nameOf('SA_RIYAD', service('language')->getLocale()));
        $response->assertSee('statement.csv');
        $response->assertSee('bank_closings');
        $response->assertSee(lang('Accounting_bank.status_closed'));
        $response->assertSee('bank_window_days');
        $response->assertSee('value="7"');
        $response->assertSee('bank-reopen');
        $response->assertSee('bank_import_link');
        $response->assertSee('975.00');
    }

    public function testGetAccount_OffersTheReopenButtonOnlyOnTheLatestClosing(): void
    {
        $this->scenario();
        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->importStatement($this->statementText([['2026-04-10', 'April line', '10.00', '', 'AP']]), name: 'april.csv');
        $this->bookLine('10.00', '2026-04-10', 'April book');
        $this->bank->match($this->bankId, $this->statementLineId('April line'), [(int) $this->accountingDb()->table('accounting_journal_lines')->where('debit', '10.0000')->get()->getRow()->line_id], $this->bankClerk);
        $this->bank->close($this->bankId, '2026-04-30', '1160.00', $this->bankClerk);
        $this->actAsReconciler();

        $this->assertSame(1, substr_count($this->get($this->accountUrl())->getBody(), 'class="btn btn-outline-secondary btn-sm bank-reopen"'));
    }

    public function testGetAccount_FlagsAClosingWhoseBooksHaveChangedSince(): void
    {
        $this->scenario();
        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->actAsReconciler();

        $this->get($this->accountUrl())->assertDontSee(lang('Accounting_bank.drifted'));

        $this->bookLine('5.00', '2026-03-15', 'Posted late');

        $this->get($this->accountUrl())->assertSee(lang('Accounting_bank.drifted'));
    }

    public function testGetImport_ShowsTheFormWithTheLimitsTheSeparatorsAndTheColumns(): void
    {
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/import'));

        $response->assertStatus(200);
        $response->assertSee('multipart/form-data');
        $response->assertSee('name="statement_file"');
        $response->assertSee('name="statement_text"');
        $response->assertSee('value="semicolon"');
        $response->assertSee('value="tab"');
        $response->assertSee('name="skip_first_row"');

        foreach (['date_column', 'description_column', 'deposit_column', 'withdrawal_column', 'reference_column'] as $field) {
            $response->assertSee('name="' . $field . '"');
        }

        $response->assertSee(lang('Accounting_bank.import_note', [StatementParser::MAX_BYTES / 1048576, StatementParser::MAX_ROWS]));
    }

    public function testGetSample_GivesTheFiveColumnsOfTheSampleFileAndItImportsWithTheStandardMapping(): void
    {
        $this->actAsReconciler();

        $response = $this->get(self::BASE . '/sample');

        $response->assertStatus(200);
        $this->assertStringContainsString('text/csv', $response->response()->getHeaderLine('Content-Type'));

        $result = $this->bank->import($this->bankId, 'sample.csv', (string) $response->response()->getBody(), StatementMapping::standard(), $this->bankClerk);

        $this->assertSame(2, $result->imported);
        $this->assertSame(['1500.0000', '-12.5000'], array_column($this->accountingDb()->table('bank_statement_lines')->orderBy('line_id')->get()->getResultArray(), 'amount'));
    }

    public function testPostImport_ImportsPastedTextAndSaysWhatItDidThenSaysNothingWasCreatedTheSecondTime(): void
    {
        $this->actAsReconciler();
        $text = $this->statementText([['2026-03-01', 'Deposit', '10.00', '', 'A'], ['2026-03-02', 'Withdrawal', '', '3.00', 'B']]);

        $first  = $this->importPasted($text);
        $before = $this->databaseFingerprint();
        $second = $this->importPasted($text);

        $this->assertTrue($first['success']);
        $this->assertTrue($first['created']);
        $this->assertSame([2, 0], [$first['imported'], $first['duplicates']]);
        $this->assertSame(lang('Accounting_bank.import_done', [2, 0]), $first['message']);
        $this->assertGreaterThan(0, $first['id']);
        $this->assertTrue($second['success']);
        $this->assertFalse($second['created']);
        $this->assertSame('duplicate_file', $second['status']);
        $this->assertSame(lang('Accounting_bank.import_same_file'), $second['message']);
        $this->assertDatabaseUnchanged($before);
    }

    public function testPostImport_SaysAllTheLinesAreKnownWhenAnotherFileHoldsOnlyKnownLines(): void
    {
        $this->actAsReconciler();
        $this->importPasted($this->statementText([['2026-03-01', 'Deposit', '10.00', '', 'A'], ['2026-03-02', 'Withdrawal', '', '3.00', 'B']]));

        $result = $this->importPasted("Date,Text,In,Out,Ref\n2026-03-01,Deposit,10.00,,A\n");

        $this->assertTrue($result['success']);
        $this->assertFalse($result['created']);
        $this->assertSame('duplicate_lines', $result['status']);
        $this->assertSame(lang('Accounting_bank.import_same_lines', [1]), $result['message']);
        $this->assertSame(2, $this->accountingDb()->table('bank_statement_lines')->countAllResults());
    }

    public function testPostImport_ReadsAnUploadedFileInTheDelimiterChosen(): void
    {
        $this->actAsReconciler();
        $file = tempnam(sys_get_temp_dir(), 'stmt');

        file_put_contents($file, $this->statementText([['2026-03-01', 'Uploaded deposit', '25.50', '', 'U1']], ';'));
        service('superglobals')->setFilesArray(['statement_file' => ['name' => 'march.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]]);

        try {
            $result = $this->json($this->accountUrl('/import'), ['delimiter' => 'semicolon', 'skip_first_row' => '1', 'date_column' => '1', 'description_column' => '2', 'deposit_column' => '3', 'withdrawal_column' => '4', 'reference_column' => '5', 'date_order' => 'dmy']);
        } finally {
            service('superglobals')->setFilesArray([]);
            unlink($file);
        }

        $this->assertTrue($result['success']);
        $this->assertSame('march.csv', $this->accountingDb()->table('bank_statements')->get()->getRow()->file_name);
        $this->assertSame('25.5000', ltrim($this->accountingDb()->table('bank_statement_lines')->get()->getRow()->amount, '0'));
    }

    public function testPostImport_RefusesAnUploadedFileOverTheSizeLimitWithoutReadingIt(): void
    {
        $this->actAsReconciler();
        $file = tempnam(sys_get_temp_dir(), 'stmt');

        file_put_contents($file, 'x');
        service('superglobals')->setFilesArray(['statement_file' => ['name' => 'huge.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => StatementParser::MAX_BYTES + 1]]);

        try {
            $result = $this->json($this->accountUrl('/import'), ['date_column' => '1', 'deposit_column' => '3', 'withdrawal_column' => '4']);
        } finally {
            service('superglobals')->setFilesArray([]);
            unlink($file);
        }

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_bank_statement_too_large', [2]), $result['message']);
        $this->assertSame(0, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testPostImport_NamesTheRowsThatAreNotValidAndStoresNothing(): void
    {
        $this->actAsReconciler();

        $result = $this->importPasted($this->statementText([['2026-03-01', 'Good', '10.00', '', 'A'], ['31/02/2026', 'Bad date', '10.00', '', 'B'], ['2026-03-03', 'Both', '5.00', '5.00', 'C']]));

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_bank_statement_rows_invalid', [2]), $result['message']);
        $this->assertSame(
            [
                lang('Accounting_bank.row_error', [3, lang('Accounting_bank.row_error_date_invalid'), '31/02/2026']),
                lang('Accounting_bank.row_error', [4, lang('Accounting_bank.row_error_amount_both'), '5.00 / 5.00']),
            ],
            $result['errors'],
        );
        $this->assertSame(0, $this->accountingDb()->table('bank_statement_lines')->countAllResults());
    }

    public function testPostImport_RefusesNothingToImportAndAMappingThatIsNotValid(): void
    {
        $this->actAsReconciler();

        $empty   = $this->json($this->accountUrl('/import'), ['date_column' => '1', 'deposit_column' => '3', 'withdrawal_column' => '4']);
        $mapping = $this->json($this->accountUrl('/import'), ['statement_text' => 'x', 'date_column' => '1', 'deposit_column' => '1', 'withdrawal_column' => '4']);
        $missing = $this->json($this->accountUrl('/import'), ['statement_text' => 'x', 'date_column' => '1', 'deposit_column' => '3']);

        $this->assertSame(lang('Accounting.error_bank_statement_empty'), $empty['message']);
        $this->assertSame(lang('Accounting.error_bank_statement_mapping_invalid'), $mapping['message']);
        $this->assertSame(lang('Accounting.error_bank_statement_mapping_invalid'), $missing['message']);
        $this->assertSame(0, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testPostImport_RefusesAnUploadedFileThatIsNotUtf8Text(): void
    {
        $this->actAsReconciler();
        $file = tempnam(sys_get_temp_dir(), 'stmt');

        file_put_contents($file, "Date,Description,Deposit,Withdrawal,Reference\n2026-03-01,caf\xE9,1.00,,R\n");
        service('superglobals')->setFilesArray(['statement_file' => ['name' => 'latin.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]]);

        try {
            $result = $this->json($this->accountUrl('/import'), ['date_column' => '1', 'description_column' => '2', 'deposit_column' => '3', 'withdrawal_column' => '4']);
        } finally {
            service('superglobals')->setFilesArray([]);
            unlink($file);
        }

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_bank_statement_encoding_invalid'), $result['message']);
        $this->assertSame(0, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testGetLines_FiltersByStateAndDateAndPages(): void
    {
        $rows = [];

        for ($day = 1; $day <= 120; $day++) {
            $rows[] = [date('Y-m-d', strtotime('2026-01-01 +' . ($day - 1) . ' days')), 'Line ' . $day, '1.00', '', 'R' . $day];
        }

        $this->importStatement($this->statementText($rows));
        $journalLine = $this->bookLine('1.00', '2026-01-01', 'Book one');
        $this->bank->match($this->bankId, $this->statementLineId('Line 1'), [$journalLine], $this->bankClerk);
        $this->actAsReconciler();

        $first   = $this->get($this->accountUrl('/lines'));
        $second  = $this->get($this->accountUrl('/lines?page=2'));
        $matched = $this->get($this->accountUrl('/lines?state=matched'));
        $window  = $this->get($this->accountUrl('/lines?state=unmatched&from=2026-01-10&to=2026-01-12'));

        $first->assertSee('Line 50');
        $first->assertDontSee('Line 51');
        $first->assertSee(lang('Accounting_bank.lines_count', [120]));
        $second->assertSee('Line 51');
        $second->assertDontSee('Line 50<');
        $matched->assertSee('Line 1<');
        $matched->assertDontSee('Line 2<');
        $matched->assertSee('data-match=');
        $window->assertSee('Line 10<');
        $window->assertSee('Line 12<');
        $window->assertDontSee('Line 9<');
        $window->assertDontSee('Line 13<');
        $window->assertSee('/match/');
    }

    public function testGetLines_OffersNoUnmatchOnAMatchAClosedReconciliationHolds(): void
    {
        $this->scenario();
        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/lines?state=matched'));

        $response->assertSee('bi-lock-fill');
        $response->assertDontSee('data-match=');
    }

    public function testGetMatch_ListsTheOpenLinesOfTheDirectionOfTheStatementLineWithTheNearOnesMarked(): void
    {
        $this->bookLine('200.00', '2026-03-08', 'Near deposit');
        $this->bookLine('200.00', '2026-05-30', 'Far deposit');
        $this->bookLine('-200.00', '2026-03-10', 'Payment out');
        $this->importStatement($this->statementText([['2026-03-10', 'Wire from Alpha', '200.00', '', 'INV-77']]));
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/match/' . $this->statementLineId('Wire from Alpha')));

        $response->assertStatus(200);
        $response->assertSee('Near deposit');
        $response->assertSee('Far deposit');
        $response->assertDontSee('Payment out');
        $response->assertSee('table-success');
        $response->assertSee('bank_match_submit');
        $response->assertSee('journal_line_ids[]');
    }

    public function testGetMatch_IsNotFoundForALineOfAnotherBankAccountAndAnUnknownLine(): void
    {
        $this->importStatement($this->statementText([['2026-03-10', 'Wire', '200.00', '', 'W']]));
        $line  = $this->statementLineId('Wire');
        $other = $this->openBank();
        $this->actAsReconciler();
        $caught = 0;

        foreach ([$this->accountUrl('/match/' . $line, $other), $this->accountUrl('/match/987654')] as $url) {
            try {
                $this->get($url);
            } catch (PageNotFoundException) {
                $caught++;
            }
        }

        $this->assertSame(2, $caught);
    }

    public function testGetMatches_ShowsTheJournalLinesOfEachMatchAndMarksTheOnesAClosedReconciliationHolds(): void
    {
        $this->scenario();
        $this->actAsReconciler(['accounting_journals']);

        $open = $this->get($this->accountUrl('/matches'));

        $open->assertStatus(200);
        $open->assertSee('Customer A transfer');
        $open->assertSee('accounting_journals/view/');
        $open->assertSee('data-match=');

        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $closed = $this->get($this->accountUrl('/matches'));

        $closed->assertSee(lang('Accounting_bank.locked'));
        $closed->assertDontSee('data-match=');
    }

    public function testGetMatches_ShowsNoLinkToTheJournalToWhoCannotOpenJournals(): void
    {
        $this->scenario();
        $this->actAsReconciler();

        $this->get($this->accountUrl('/matches'))->assertDontSee('accounting_journals/view/');
    }

    public function testGetSuggestions_ListsTheCandidatesWithTheirHintsAndAnAcceptButton(): void
    {
        $this->bookLine('200.00', '2026-03-08', 'Invoice INV-77 payment');
        $this->bookLine('200.00', '2026-03-12', 'Other customer');
        $this->importStatement($this->statementText([['2026-03-10', 'Wire from Alpha', '200.00', '', 'INV-77']]));
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/suggestions'));

        $response->assertStatus(200);
        $response->assertSee('Wire from Alpha');
        $response->assertSee('Invoice INV-77 payment');
        $response->assertSee('Other customer');
        $response->assertSee(lang('Accounting_bank.hint_reference'));
        $response->assertSee(lang('Accounting_bank.confidence_reference'));
        $response->assertSee('bank-accept');
        $response->assertSee(lang('Accounting_bank.days_apart', [2]));
    }

    public function testGetSuggestions_SaysThereIsNothingToSuggest(): void
    {
        $this->actAsReconciler();

        $this->get($this->accountUrl('/suggestions'))->assertSee('bank_suggestions_empty');
    }

    public function testGetReport_ShowsBothSidesTheNoDifferenceAndTheCloseButtonWhenTheBalanceIsRight(): void
    {
        $this->scenario();
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/report?as_of=2026-03-31&statement_balance=1150.00'));

        $response->assertStatus(200);
        $response->assertSee('bank_report_books');
        $response->assertSee('bank_report_bank');
        $response->assertSee('975.00');
        $response->assertSee('925.00');
        $response->assertSee('Bank fee');
        $response->assertSee('Cheque 501');
        $response->assertSee('Deposit in transit');
        $response->assertSee(lang('Accounting_bank.reconciled'));
        $response->assertSee('bank_close_submit');
        $response->assertSee('bank_report_carried_items');
        $response->assertDontSee('bank_report_error');
    }

    public function testGetReport_ShowsTheDifferenceAndNoCloseButtonWhenTheBalanceIsWrong(): void
    {
        $this->scenario();
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/report?as_of=2026-03-31&statement_balance=1140.00'));

        $response->assertSee('-10.00');
        $response->assertSee(lang('Accounting_bank.difference_hint'));
        $response->assertDontSee('bank_close_submit');
        $response->assertDontSee(lang('Accounting_bank.reconciled'));
    }

    public function testGetReport_AsksForTheBalanceWhenItWasNotEntered(): void
    {
        $this->scenario();
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/report?as_of=2026-03-31'));

        $response->assertSee(lang('Accounting_bank.not_entered'));
        $response->assertSee(lang('Accounting_bank.not_computed'));
        $response->assertDontSee('bank_close_submit');
    }

    public function testGetReport_ShowsTheRefusalOfABalanceThatIsNotANumberAndStillTheFigures(): void
    {
        $this->scenario();
        $this->actAsReconciler();

        $response = $this->get($this->accountUrl('/report?as_of=2026-03-31&statement_balance=abc'));

        $response->assertStatus(200);
        $response->assertSee('bank_report_error');
        $response->assertSee('975.00');
    }

    public function testGetReport_TakesTheDateOfTodayWhenNoneIsGiven(): void
    {
        $this->actAsReconciler();

        $this->get($this->accountUrl('/report'))->assertSee('value="' . date('Y-m-d') . '"');
    }

    public function testPostMatch_MatchesTheLinesAndTheTreasuryStatementFlagsTheJournalLine(): void
    {
        $journalLine = $this->bookLine('100.00', '2026-03-10', 'Customer deposit');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $this->actAsReconciler(['accounting_journals']);

        $this->get('/accounting_treasuries/statement/' . $this->bankId)->assertDontSee('bank-matched');

        $result = $this->json($this->accountUrl('/match'), ['line_id' => $this->statementLineId('Deposit A'), 'journal_line_ids' => [$journalLine]]);

        $this->assertTrue($result['success']);
        $this->assertSame(lang('Accounting_bank.matched'), $result['message']);
        $this->assertSame(1, $this->accountingDb()->table('bank_matches')->countAllResults());
        $this->assertSame('manual', $this->accountingDb()->table('bank_matches')->get()->getRow()->method);
        $this->get('/accounting_treasuries/statement/' . $this->bankId)->assertSee('bank-matched');
    }

    public function testGetStatement_DoesNotShowTheMatchFlagToWhoCannotReconcile(): void
    {
        $journalLine = $this->bookLine('100.00', '2026-03-10', 'Customer deposit');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $this->bank->match($this->bankId, $this->statementLineId('Deposit A'), [$journalLine], $this->bankClerk);
        $this->withSession(['person_id' => $this->employeeWithGrants(['accounting_treasuries']), 'menu_group' => 'home']);

        $this->get('/accounting_treasuries/statement/' . $this->bankId)->assertDontSee('bank-matched');
    }

    public function testPostMatch_RefusesLinesThatDoNotAddUpAndMatchesNothing(): void
    {
        $journalLine = $this->bookLine('99.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $this->actAsReconciler();

        $result = $this->json($this->accountUrl('/match'), ['line_id' => $this->statementLineId('Deposit A'), 'journal_line_ids' => [$journalLine]]);

        $this->assertFalse($result['success']);
        $this->assertSame(lang('Accounting.error_bank_match_amount_mismatch', ['1.0000']), $result['message']);
        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testPostMatch_RefusesNoLinesAndAJournalLineThatIsNotAnArrayOfIds(): void
    {
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $this->actAsReconciler();

        $none = $this->json($this->accountUrl('/match'), ['line_id' => $this->statementLineId('Deposit A')]);
        $text = $this->json($this->accountUrl('/match'), ['line_id' => $this->statementLineId('Deposit A'), 'journal_line_ids' => 'abc']);

        $this->assertSame(lang('Accounting.error_bank_journal_lines_required'), $none['message']);
        $this->assertSame(lang('Accounting.error_bank_journal_lines_required'), $text['message']);
    }

    public function testPostMatch_RecordsAnAcceptedSuggestionAsSuggested(): void
    {
        $journalLine = $this->bookLine('100.00', '2026-03-10');
        $this->importStatement($this->statementText([['2026-03-10', 'Deposit A', '100.00', '', 'A']]));
        $this->actAsReconciler();

        $result = $this->json($this->accountUrl('/match'), ['line_id' => $this->statementLineId('Deposit A'), 'journal_line_ids' => [$journalLine], 'method' => BankMatches::SUGGESTED]);

        $this->assertTrue($result['success']);
        $this->assertSame('suggested', $this->accountingDb()->table('bank_matches')->get()->getRow()->method);
    }

    public function testPostUnmatch_TakesTheMatchBackAndRefusesOneAClosedReconciliationHolds(): void
    {
        $this->scenario();
        $this->actAsReconciler();
        $matchId = (int) $this->accountingDb()->table('bank_matches')->get()->getRow()->match_id;

        $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);

        $locked = $this->json($this->accountUrl('/unmatch/' . $matchId));

        $this->assertFalse($locked['success']);
        $this->assertSame(lang('Accounting.error_bank_match_locked'), $locked['message']);

        $this->bank->reopen((int) $this->accountingDb()->table('bank_reconciliations')->get()->getRow()->reconciliation_id, 'Wrong line', $this->bankClerk);

        $done = $this->json($this->accountUrl('/unmatch/' . $matchId));

        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_bank.unmatched'), $done['message']);
        $this->assertSame(0, $this->accountingDb()->table('bank_matches')->countAllResults());
    }

    public function testPostClose_ClosesTheReconciliationAndRefusesADifference(): void
    {
        $this->scenario();
        $this->actAsReconciler();

        $refused = $this->json($this->accountUrl('/close'), ['through' => '2026-03-31', 'statement_balance' => '1100.00']);
        $done    = $this->json($this->accountUrl('/close'), ['through' => '2026-03-31', 'statement_balance' => '1150.00']);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_bank_reconciliation_difference', ['-50.0000']), $refused['message']);
        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_bank.closed'), $done['message']);
        $this->assertSame(1, $this->accountingDb()->table('bank_reconciliations')->countAllResults());
    }

    public function testPostReopen_ReopensTheLatestClosingWithAReasonAndRefusesWithout(): void
    {
        $this->scenario();
        $closing = $this->bank->close($this->bankId, '2026-03-31', '1150.00', $this->bankClerk);
        $this->actAsReconciler();

        $refused = $this->json(self::BASE . '/reopen/' . $closing, ['reason' => ' ']);
        $done    = $this->json(self::BASE . '/reopen/' . $closing, ['reason' => 'Matched the wrong deposit']);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_reason_required'), $refused['message']);
        $this->assertTrue($done['success']);
        $this->assertSame(lang('Accounting_bank.reopened'), $done['message']);
        $this->assertSame('reopened', $this->accountingDb()->table('bank_reconciliations')->get()->getRow()->status);
    }

    public function testPostWindow_SavesTheWindowOfTheSuggestionsAndRefusesOneOutOfRange(): void
    {
        $this->actAsReconciler();

        $done    = $this->json(self::BASE . '/window', ['days' => '14']);
        $refused = $this->json(self::BASE . '/window', ['days' => '500']);

        $this->assertTrue($done['success']);
        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_bank_match_window_invalid', [60, 0]), $refused['message']);
        $this->assertSame(14, $this->bank->windowDays());
    }

    public function testPostDeleteStatement_DeletesAnUnmatchedStatementAndRefusesOneWithMatchedLines(): void
    {
        $this->scenario();
        $this->importStatement($this->statementText([['2026-04-01', 'Spare', '5.00', '', 'S']]), name: 'spare.csv');
        $this->actAsReconciler();
        $statements = $this->accountingDb()->table('bank_statements')->orderBy('statement_id')->get()->getResultArray();

        $refused = $this->json(self::BASE . '/statement_delete/' . $statements[0]['statement_id']);
        $done    = $this->json(self::BASE . '/statement_delete/' . $statements[1]['statement_id']);

        $this->assertFalse($refused['success']);
        $this->assertSame(lang('Accounting.error_bank_statement_has_matches'), $refused['message']);
        $this->assertTrue($done['success']);
        $this->assertSame(1, $this->accountingDb()->table('bank_statements')->countAllResults());
    }

    public function testGet_ShowsNoSecretOrAddressToolOrExternalAssetOnAnyOfTheScreens(): void
    {
        $this->actAsReconciler();

        foreach (['', '/' . $this->bankId, '/' . $this->bankId . '/import', '/' . $this->bankId . '/lines', '/' . $this->bankId . '/matches', '/' . $this->bankId . '/suggestions', '/' . $this->bankId . '/report'] as $path) {
            $body = $this->get(self::BASE . $path)->getBody();

            $this->assertStringNotContainsString('cdn.', $body, $path);
            $this->assertDoesNotMatchRegularExpression('/<(script|link)[^>]+(src|href)="https?:\/\/(?!' . preg_quote(parse_url(base_url(), PHP_URL_HOST) ?: 'localhost', '/') . ')/i', $body, $path);
        }
    }

    public function testViews_ReferenceNoExternalUrl(): void
    {
        foreach (glob(APPPATH . 'Views/accounting_bank/*.php') as $file) {
            $this->assertDoesNotMatchRegularExpression('/https?:\/\//', (string) file_get_contents($file), basename($file));
        }
    }

    public function testPermissionLabel_IsDefinedInEveryTranslatedLocale(): void
    {
        foreach (['en', 'en-GB', 'ar-EG', 'ar-LB'] as $locale) {
            $messages = include APPPATH . "Language/{$locale}/Accounting_treasuries.php";

            $this->assertNotSame('', $messages['bank_reconcile'] ?? '', $locale);
        }

        $this->assertSame('Accounting_treasuries', explode('.', 'Accounting_treasuries.bank_reconcile')[0]);
        $this->assertSame(AccountingError::BankAccountNotFound->value, 'bank_account_not_found');
    }
}
