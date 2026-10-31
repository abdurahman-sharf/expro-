<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\YearEndClosing;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;

class OpeningBalanceServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private OpeningBalanceService $service;
    private ?BaseConnection $other = null;

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
        $this->setConfigValue('accounting_start_date', '2026-01-01');
        $this->service = new OpeningBalanceService();
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();

        if ($this->other !== null) {
            $this->other->transRollback();
            $this->other->close();
            $this->other = null;
        }

        $this->removeInsertedEntities();

        parent::tearDown();
    }

    private function assertFailsWith(AccountingError $expected, callable $action): AccountingException
    {
        try {
            $action();
        } catch (AccountingException $exception) {
            $this->assertSame($expected, $exception->error);

            return $exception;
        }

        $this->fail('Expected the failure ' . $expected->value . '.');
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function manager(?array $branchIds = null): int
    {
        return $this->employeeWithGrants([AccountingGuard::OPENING_MANAGE], $branchIds);
    }

    /**
     * @return list<array<string, string>>
     */
    private function balancedLines(string $amount = '1000.00'): array
    {
        return [
            ['account_id' => (string) $this->accountId('cash'), 'description' => 'Cash in the drawer', 'debit' => $amount, 'credit' => ''],
            ['account_id' => (string) $this->accountId('retained_earnings'), 'description' => '', 'debit' => '', 'credit' => $amount],
        ];
    }

    public function testAllowedAccounts_ListsOnlyBalanceSheetAccountsThatAreNotControlOrOpening(): void
    {
        $ids = array_map('intval', array_column($this->service->allowedAccounts(), 'account_id'));

        foreach (['cash', 'retained_earnings', 'giftcard_liability'] as $systemKey) {
            $this->assertContains($this->accountId($systemKey), $ids, $systemKey);
        }

        foreach (['sales_revenue', 'cogs', 'receivables', 'payables', 'inventory', 'tax_output', 'tax_input', 'opening_balance'] as $systemKey) {
            $this->assertNotContains($this->accountId($systemKey), $ids, $systemKey);
        }

        $this->assertNotContains($this->accountIdByCode('11'), $ids);
    }

    public function testAllowedAccounts_FollowsTheRoutingOfTheControlAccounts(): void
    {
        $group = $this->customAccount('12', '1230', 'Custom customers', true);
        $child = $this->customAccount('1230', '123001', 'Customer sub-account');
        $other = $this->customAccount('12', '1231', 'Unrelated asset');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'sales.customers')->update(['account_id' => $group]);

        $ids = array_map('intval', array_column($this->service->allowedAccounts(), 'account_id'));

        $this->assertNotContains($child, $ids);
        $this->assertContains($other, $ids);
    }

    public function testAllowedAccounts_LeavesOutTheSubAccountsOfCustomersSuppliersAndStockLocations(): void
    {
        $entities = new EntityAccountService();
        $blocked  = [
            $entities->ensure(EntityAccountService::CUSTOMER, $this->insertCustomer(), 'Omar Khaled'),
            $entities->ensure(EntityAccountService::SUPPLIER, $this->insertSupplier(), 'Gulf Supplies'),
            $entities->ensure(EntityAccountService::STOCK_LOCATION, $this->insertStockLocation('Back store'), 'Back store'),
        ];

        $ids = array_map('intval', array_column($this->service->allowedAccounts(), 'account_id'));

        foreach ($blocked as $accountId) {
            $this->assertNotContains($accountId, $ids);
        }
    }

    public function testSaveDraft_KeepsTheLinesUntilTheyAreChanged(): void
    {
        $employee = $this->manager();

        $this->service->saveDraft(1, $this->balancedLines('500.00'), $employee);
        $this->service->saveDraft(1, $this->balancedLines('700.00'), $employee);

        $state = $this->service->state(1, $employee);

        $this->assertNull($state['posted']);
        $this->assertSame('700.0000', $state['draft'][0]['debit']);
        $this->assertSame('2026-01-01', $state['start_date']);
        $this->assertSame(1, $this->countRows('accounting_opening_drafts'));
    }

    public function testSaveDraft_SkipsEmptyLinesAndRejectsAnEmptyDraft(): void
    {
        $employee = $this->manager();

        $this->service->saveDraft(1, [...$this->balancedLines(), ['account_id' => '', 'description' => '', 'debit' => '', 'credit' => '']], $employee);

        $this->assertCount(2, $this->service->state(1, $employee)['draft']);
        $this->assertFailsWith(AccountingError::NoLines, fn () => $this->service->saveDraft(1, [['account_id' => '', 'debit' => '', 'credit' => '']], $employee));
    }

    public function testSaveDraft_RejectsAccountsThatCannotTakeAnOpeningBalance(): void
    {
        $employee = $this->manager();

        foreach (['sales_revenue', 'receivables', 'opening_balance'] as $systemKey) {
            $exception = $this->assertFailsWith(AccountingError::OpeningAccountNotAllowed, fn () => $this->service->saveDraft(1, [
                ['account_id' => (string) $this->accountId($systemKey), 'debit' => '10.00', 'credit' => ''],
            ], $employee));

            $this->assertSame(1, $exception->context['line']);
        }

        $this->assertSame(0, $this->countRows('accounting_opening_drafts'));
    }

    public function testSaveDraft_RejectsALineWithBothSidesOrABadAmountOrNoAccount(): void
    {
        $employee = $this->manager();
        $cash     = (string) $this->accountId('cash');

        $this->assertFailsWith(AccountingError::LineOnBothSides, fn () => $this->service->saveDraft(1, [['account_id' => $cash, 'debit' => '5', 'credit' => '5']], $employee));
        $this->assertFailsWith(AccountingError::LineAmountInvalid, fn () => $this->service->saveDraft(1, [['account_id' => $cash, 'debit' => '5x', 'credit' => '']], $employee));
        $this->assertFailsWith(AccountingError::LineAccountRequired, fn () => $this->service->saveDraft(1, [['account_id' => '', 'debit' => '5', 'credit' => '']], $employee));
        $this->assertFailsWith(AccountingError::LineAmountInvalid, fn () => $this->service->saveDraft(1, [['account_id' => $cash, 'debit' => '', 'credit' => '']], $employee));
    }

    public function testSaveDraft_RequiresThePermissionAndTheBranchInScope(): void
    {
        $second = $this->createBranch('B2');

        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->saveDraft(1, $this->balancedLines(), $this->employeeWithGrants([])));

        try {
            $this->service->saveDraft($second, $this->balancedLines(), $this->manager([1]));
            $this->fail('Expected the branch to be refused.');
        } catch (BranchException $exception) {
            $this->assertSame(BranchError::NotAllowed, $exception->error);
        }

        $this->expectException(BranchException::class);

        $this->service->saveDraft(9999, $this->balancedLines(), $this->manager());
    }

    public function testDiscardDraft_RemovesTheDraft(): void
    {
        $employee = $this->manager();
        $this->service->saveDraft(1, $this->balancedLines(), $employee);

        $this->service->discardDraft(1, $employee);

        $this->assertNull($this->service->state(1, $employee)['draft']);
    }

    public function testPost_PostsTheDraftOnTheStartDateInTheBranch(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->manager();
        $this->service->saveDraft($second, $this->balancedLines('1500.00'), $employee);

        $journalId = $this->service->post($second, null, false, $employee);

        $journal = $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow();

        $this->assertSame('posted', $journal->status);
        $this->assertSame('opening', $journal->source_type);
        $this->assertSame((string) $second, (string) $journal->source_id);
        $this->assertSame((string) $second, (string) $journal->branch_id);
        $this->assertSame('2026-01-01', $journal->journal_date);
        $this->assertSame('1500.0000', $journal->total_debit);
        $this->assertSame(0, $this->countRows('accounting_opening_drafts'));

        $state = $this->service->state($second, $employee);

        $this->assertSame($journalId, (int) $state['posted']->journal_id);
        $this->assertCount(2, $state['posted']->lines);
        $this->assertNull($state['draft']);
    }

    public function testPost_AcceptsLinesGivenDirectly(): void
    {
        $journalId = $this->service->post(1, $this->balancedLines('300.00'), false, $this->manager());

        $this->assertSame('300.0000', $this->accountingDb()->table('accounting_journals')->where('journal_id', $journalId)->get()->getRow()->total_debit);
    }

    public function testPost_RejectsAnUnbalancedOpeningUnlessTheDifferenceGoesToTheOpeningAccount(): void
    {
        $employee = $this->manager();
        $lines    = [['account_id' => (string) $this->accountId('cash'), 'debit' => '1000.00', 'credit' => '']];

        $exception = $this->assertFailsWith(AccountingError::OpeningUnbalanced, fn () => $this->service->post(1, $lines, false, $employee));

        $this->assertSame('1000.0000', $exception->context['debit']);
        $this->assertSame('0.0000', $exception->context['credit']);
        $this->assertSame(0, $this->countRows('accounting_journals'));

        $journalId = $this->service->post(1, $lines, true, $employee);

        $balancing = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->where('account_id', $this->accountId('opening_balance'))->get()->getRow();

        $this->assertSame('1000.0000', $balancing->credit);
        $this->assertSame('0.0000', $balancing->debit);
    }

    public function testPost_PutsADebitDifferenceOnTheOpeningAccountToo(): void
    {
        $lines = [['account_id' => (string) $this->accountId('giftcard_liability'), 'debit' => '', 'credit' => '250.00']];

        $journalId = $this->service->post(1, $lines, true, $this->manager());

        $balancing = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journalId)->where('account_id', $this->accountId('opening_balance'))->get()->getRow();

        $this->assertSame('250.0000', $balancing->debit);
    }

    public function testPost_RejectsASecondOpeningWhileTheFirstIsPosted(): void
    {
        $employee = $this->manager();
        $this->service->post(1, $this->balancedLines(), false, $employee);

        $this->assertFailsWith(AccountingError::OpeningAlreadyPosted, fn () => $this->service->post(1, $this->balancedLines(), false, $employee));
        $this->assertFailsWith(AccountingError::OpeningAlreadyPosted, fn () => $this->service->saveDraft(1, $this->balancedLines(), $employee));
    }

    public function testPost_NeedsTheAccountingStartDate(): void
    {
        $this->setConfigValue('accounting_start_date', '');

        $this->assertFailsWith(AccountingError::OpeningStartDateMissing, fn () => $this->service->post(1, $this->balancedLines(), false, $this->manager()));
    }

    public function testPost_KeepsTheDraftWhenTheDateIsInAClosedPeriod(): void
    {
        $employee = $this->manager();
        $this->service->saveDraft(1, $this->balancedLines(), $employee);
        $this->closeThrough('2026-01-31');

        try {
            $this->service->post(1, null, false, $employee);
            $this->fail('A closed period should refuse the opening balances.');
        } catch (PostingException $failure) {
            $this->assertSame(PostingError::PeriodClosed, $failure->error);
        }

        $this->assertNotNull($this->service->state(1, $employee)['draft']);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testPost_NeedsADraftOrLines(): void
    {
        $this->assertFailsWith(AccountingError::NoLines, fn () => $this->service->post(1, null, false, $this->manager()));
    }

    public function testPost_KeepsEachBranchSeparate(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->manager();

        $this->service->post(1, $this->balancedLines('100.00'), false, $employee);
        $this->service->post($second, $this->balancedLines('200.00'), false, $employee);

        $this->assertNotNull($this->service->state(1, $employee)['posted']);
        $this->assertSame('200.0000', $this->service->state($second, $employee)['posted']->total_debit);
    }

    public function testReverse_ReversesTheOpeningAndLetsTheBranchPostAReplacement(): void
    {
        $employee = $this->manager();
        $original = $this->service->post(1, $this->balancedLines('100.00'), false, $employee);

        $reversal = $this->service->reverse(1, '2026-01-02', 'Wrong counts', $employee);

        $this->assertSame('reversed', $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->get()->getRow()->status);
        $this->assertSame((string) $original, (string) $this->accountingDb()->table('accounting_journals')->where('journal_id', $reversal)->get()->getRow()->reverses_journal_id);
        $this->assertNull($this->service->state(1, $employee)['posted']);

        $replacement = $this->service->post(1, $this->balancedLines('150.00'), false, $employee);

        $this->assertSame('2', (string) $this->accountingDb()->table('accounting_journals')->where('journal_id', $replacement)->get()->getRow()->revision);
    }

    public function testReverse_RefusesWhileTheBranchHasClosedOutTheYearOfTheOpeningAndAcceptsOnceTheClosingIsTakenBack(): void
    {
        $employee = $this->manager();
        $original = $this->service->post(1, $this->balancedLines('100.00'), false, $employee);
        (new JournalService())->postNew(['date' => '2026-06-10'] + $this->journalInput());
        $this->closeThrough('2026-12-31');

        $closer  = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $run     = new YearEndClosing();
        $year    = (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2026')->get()->getRow()->fiscal_year_id;
        $preview = $run->preview(1, $year, $closer);
        $closing = $run->approve(1, $year, $preview->fingerprint, $closer)[0];
        $counts  = $this->accountingDb()->table('accounting_journals')->countAllResults();

        $failure = $this->assertFailsWith(AccountingError::YearEndClosedOut, fn () => $this->service->reverse(1, '2027-01-02', 'Wrong counts', $employee));

        $this->assertSame(['name' => '2026', 'date' => '2026-12-31'], $failure->context);
        $this->assertSame('posted', $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->get()->getRow()->status);
        $this->assertSame($counts, $this->accountingDb()->table('accounting_journals')->countAllResults());

        $run->reverse($closing, 'Back', $closer);

        $this->assertGreaterThan(0, $this->service->reverse(1, '2027-01-02', 'Wrong counts', $employee));
        $this->assertSame('reversed', $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->get()->getRow()->status);
    }

    public function testReverse_WaitsForAClosingOfTheYearThatIsBeingWrittenAndIsRefusedOnceItIsThere(): void
    {
        $employee = $this->manager();
        $original = $this->service->post(1, $this->balancedLines('100.00'), false, $employee);
        (new JournalService())->postNew(['date' => '2026-06-10'] + $this->journalInput());
        $this->closeThrough('2026-12-31');

        $closer  = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $year    = (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2026')->get()->getRow()->fiscal_year_id;
        $preview = (new YearEndClosing())->preview(1, $year, $closer);
        $first   = $this->openSecondConnection(false);
        $first->resetTransStatus();
        $first->transStart();

        try {
            (new YearEndClosing($first))->approve(1, $year, $preview->fingerprint, $closer);

            $worker  = $this->startRaceWorker('reverseOpeningBalanceOutcome', ['1', '2027-01-02', (string) $employee]);
            $waiting = $this->raceWorkerIsRunning($worker);
        } finally {
            $first->transComplete();
            $first->close();
        }

        $this->assertTrue($waiting, 'The reversal waits for the closing that holds the branch');
        $this->assertSame('year_end_closed_out', $this->finishRaceWorker($worker), 'And finds the year closed out when it gets the branch');
        $this->assertSame('posted', $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->get()->getRow()->status);
    }

    public function testReverse_AcceptsTheOpeningOfABranchWhoseYearIsNotClosedOutWhenAnotherBranchClosedItsOwn(): void
    {
        $second   = $this->createBranch('B2');
        $employee = $this->manager();
        $this->service->post($second, $this->balancedLines('100.00'), false, $employee);
        (new JournalService())->postNew(['date' => '2026-06-10', 'branch_id' => 1] + $this->journalInput());
        $this->closeThrough('2026-12-31');

        $closer  = $this->employeeWithGrants([AccountingGuard::YEAR_END_CLOSE]);
        $run     = new YearEndClosing();
        $year    = (int) $this->accountingDb()->table('accounting_fiscal_years')->where('name', '2026')->get()->getRow()->fiscal_year_id;
        $preview = $run->preview(1, $year, $closer);
        $run->approve(1, $year, $preview->fingerprint, $closer);

        $this->assertGreaterThan(0, $this->service->reverse($second, '2027-01-02', 'Wrong counts', $employee));
    }

    public function testReverse_DoesNotNeedToBeTheCreatorOrHoldTheJournalPermissions(): void
    {
        $original = $this->service->post(1, $this->balancedLines(), false, $this->manager());
        $other    = $this->manager();

        $this->assertGreaterThan(0, $this->service->reverse(1, '2026-01-02', 'Another person', $other));
        $this->assertSame('reversed', $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->get()->getRow()->status);
    }

    public function testReverse_RequiresAPostedOpeningAndAReasonAndThePermission(): void
    {
        $employee = $this->manager();

        $this->assertFailsWith(AccountingError::OpeningNotPosted, fn () => $this->service->reverse(1, '2026-01-02', 'Reason', $employee));

        $this->service->post(1, $this->balancedLines(), false, $employee);

        $this->assertFailsWith(AccountingError::ReasonRequired, fn () => $this->service->reverse(1, '2026-01-02', ' ', $employee));
        $this->assertFailsWith(AccountingError::PermissionDenied, fn () => $this->service->reverse(1, '2026-01-02', 'Reason', $this->employeeWithGrants([AccountingGuard::JOURNALS_REVERSE])));
    }

    /**
     * @return list<array<string, string>>
     */
    private function linesOnATreasury(string $direction, array $scopeMembers, string $amount = '500.00'): array
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Scoped drawer', 'kind' => 'cash', $direction . '_scope' => 'staff', $direction . '_members' => $scopeMembers]);
        $account    = (string) $treasuries->accountOf($drawer);

        return [
            ['account_id' => $account, 'description' => '', 'debit' => $amount, 'credit' => ''],
            ['account_id' => (string) $this->accountId('retained_earnings'), 'description' => '', 'debit' => '', 'credit' => $amount],
        ];
    }

    public function testPost_RefusesAnEmployeeWhoMayNotDepositIntoATreasuryTheOpeningDebits(): void
    {
        $employee = $this->manager();
        $lines    = $this->linesOnATreasury('deposit', [$this->employeeWithGrants([])]);

        $this->service->saveDraft(1, $lines, $employee);

        $this->assertFailsWith(AccountingError::TreasuryDepositDenied, fn () => $this->service->post(1, null, false, $employee));
        $this->assertNull($this->service->state(1, $employee)['posted']);
        $this->assertNotNull($this->service->state(1, $employee)['draft'], 'The draft stays');
        $this->assertGreaterThan(0, $this->service->post(1, $lines, false, null), 'The books may post it');
    }

    public function testPost_AcceptsAnEmployeeWhoMayDepositIntoThatTreasury(): void
    {
        $employee = $this->manager();

        $this->assertGreaterThan(0, $this->service->post(1, $this->linesOnATreasury('deposit', [$employee]), false, $employee));
    }

    public function testReverse_RefusesAnEmployeeWhoMayNotWithdrawFromATreasuryTheOpeningPutMoneyInto(): void
    {
        $employee = $this->manager();
        $original = $this->service->post(1, $this->linesOnATreasury('withdraw', [$this->employeeWithGrants([])]), false, null);

        $this->assertFailsWith(AccountingError::TreasuryWithdrawDenied, fn () => $this->service->reverse(1, '2026-01-02', 'Wrong counts', $employee));
        $this->assertSame('posted', $this->accountingDb()->table('accounting_journals')->where('journal_id', $original)->get()->getRow()->status);
        $this->assertGreaterThan(0, $this->service->reverse(1, '2026-01-02', 'The books act', null));
    }

    public function testReverseOpening_RefusesAJournalThatIsNotAnOpeningBalance(): void
    {
        $manual = (new JournalService())->postNew($this->journalInput());

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => (new JournalService())->reverseOpening($manual, '2026-10-22', 'Reason', $this->manager()));
    }

    private function holdOpen(callable $work): void
    {
        $db = $this->accountingDb();
        $db->resetTransStatus();
        $db->transStart();
        $work();
    }

    /**
     * @return list<string>
     */
    private function openingRevisions(int $branchId = 1): array
    {
        $rows = $this->accountingDb()->table('accounting_journals')->select('revision')->where('source_type', 'opening')->where('source_id', $branchId)->orderBy('revision')->get()->getResultArray();

        return array_map(static fn (array $row): string => (string) $row['revision'], $rows);
    }

    public function testPost_WaitsForAnotherPostOfTheSameBranchAndThenFindsItsJournal(): void
    {
        $employee    = $this->manager();
        $this->other = $this->openSecondConnection();

        $this->holdOpen(fn () => $this->service->post(1, $this->balancedLines(), false, $employee));

        $this->assertWaitsForLock(fn () => (new OpeningBalanceService($this->other))->post(1, $this->balancedLines('50.00'), false, $employee));

        $this->accountingDb()->transComplete();

        $this->assertFailsWith(AccountingError::OpeningAlreadyPosted, fn () => (new OpeningBalanceService($this->other))->post(1, $this->balancedLines('50.00'), false, $employee));
        $this->assertSame(['1'], $this->openingRevisions());
    }

    public function testPost_DoesNotWaitForAPostOfAnotherBranch(): void
    {
        $second      = $this->createBranch('B2');
        $employee    = $this->manager();
        $this->other = $this->openSecondConnection();

        $this->holdOpen(fn () => $this->service->post(1, $this->balancedLines(), false, $employee));

        (new OpeningBalanceService($this->other))->post($second, $this->balancedLines('50.00'), false, $employee);

        $this->accountingDb()->transComplete();

        $this->assertSame(['1'], $this->openingRevisions(1));
        $this->assertSame(['1'], $this->openingRevisions($second));
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function databaseDebugProvider(): array
    {
        return ['the database throws' => [true], 'the database returns false as in production' => [false]];
    }

    #[DataProvider('databaseDebugProvider')]
    public function testPost_RefusesAnOpeningPostedMeanwhileByARequestHoldingAnOlderSnapshot(bool $debug): void
    {
        $employee    = $this->manager();
        $this->other = $this->openSecondConnection($debug);

        $this->startWithSnapshot($this->other);
        $this->service->post(1, $this->balancedLines(), false, $employee);

        $this->assertFailsWith(AccountingError::OpeningAlreadyPosted, fn () => (new OpeningBalanceService($this->other))->post(1, $this->balancedLines('50.00'), false, $employee));
        $this->assertSame(['1'], $this->openingRevisions());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->where('status', 'posted')->countAllResults());
    }

    public function testSaveDraft_WaitsForAPostInProgressAndThenRefusesToKeepADraft(): void
    {
        $employee    = $this->manager();
        $this->other = $this->openSecondConnection();

        $this->holdOpen(fn () => $this->service->post(1, $this->balancedLines(), false, $employee));

        $this->assertWaitsForLock(fn () => (new OpeningBalanceService($this->other))->saveDraft(1, $this->balancedLines('50.00'), $employee));

        $this->accountingDb()->transComplete();

        $this->assertFailsWith(AccountingError::OpeningAlreadyPosted, fn () => (new OpeningBalanceService($this->other))->saveDraft(1, $this->balancedLines('50.00'), $employee));
        $this->assertSame(0, $this->accountingDb()->table('accounting_opening_drafts')->countAllResults());
    }

    public function testReverse_WaitsForAnotherReversalAndThenFindsNothingToReverse(): void
    {
        $employee    = $this->manager();
        $this->other = $this->openSecondConnection();
        $this->service->post(1, $this->balancedLines(), false, $employee);

        $this->holdOpen(fn () => $this->service->reverse(1, '2026-01-02', 'First', $employee));

        $this->assertWaitsForLock(fn () => (new OpeningBalanceService($this->other))->reverse(1, '2026-01-02', 'Second', $employee));

        $this->accountingDb()->transComplete();

        $this->assertFailsWith(AccountingError::OpeningNotPosted, fn () => (new OpeningBalanceService($this->other))->reverse(1, '2026-01-02', 'Second', $employee));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testReverse_RefusesAnOpeningReversedMeanwhileByARequestHoldingAnOlderSnapshot(): void
    {
        $employee    = $this->manager();
        $this->other = $this->openSecondConnection();
        $this->service->post(1, $this->balancedLines(), false, $employee);

        $this->startWithSnapshot($this->other);
        $this->service->reverse(1, '2026-01-02', 'First', $employee);

        $this->assertFailsWith(AccountingError::AlreadyReversed, fn () => (new OpeningBalanceService($this->other))->reverse(1, '2026-01-02', 'Second', $employee));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id IS NOT NULL', null, false)->countAllResults());
    }

    public function testPost_KeepsRevisionsUniqueAndTheBalancesRightAcrossRepeatedReversalAndReplacement(): void
    {
        $employee = $this->manager();
        $amounts  = ['100.00', '250.00', '400.00'];

        foreach ($amounts as $index => $amount) {
            if ($index > 0) {
                $this->service->reverse(1, '2026-01-02', 'Wrong counts', $employee);
            }

            $this->service->post(1, $this->balancedLines($amount), false, $employee);
            $this->assertFailsWith(AccountingError::OpeningAlreadyPosted, fn () => $this->service->post(1, $this->balancedLines($amount), false, $employee));
        }

        $this->assertSame(['1', '2', '3'], $this->openingRevisions());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->where('status', 'posted')->countAllResults());
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_type', 'opening')->where('status', 'reversed')->countAllResults());

        $cash = $this->accountingDb()->table('accounting_journal_lines')
            ->select('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance', false)
            ->where('account_id', $this->accountId('cash'))
            ->get()
            ->getRow();

        $this->assertSame('400.0000', (string) $cash->balance);
    }
}
