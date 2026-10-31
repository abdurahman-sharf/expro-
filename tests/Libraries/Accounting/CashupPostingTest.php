<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\CashupPosting;
use App\Libraries\Accounting\CashupPostingBuilder;
use App\Libraries\Accounting\JournalChange;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * What a cash-up puts in the books, through the real cash-up model: only the difference between
 * the cash that was counted and the cash that was expected, inside the save transaction, reversed
 * and posted again as the next revision when the cash-up changes, reversed when it is deleted, and
 * never posted for a cash-up saved before the books followed cash-ups.
 */
class CashupPostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SOURCE = CashupPostingBuilder::SOURCE;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
    }

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    private function failure(): AccountingException|PostingException|BranchException
    {
        $failure = $this->cashups->accountingFailure();

        $this->assertTrue($failure instanceof AccountingException || $failure instanceof PostingException || $failure instanceof BranchException, 'The books did not refuse the change.');

        return $failure;
    }

    private function postedJournals(int $cashupId): int
    {
        return count(array_filter($this->journalsOfSource(self::SOURCE, $cashupId), static fn (object $journal): bool => $journal->status === 'posted'));
    }

    private function row(int $cashupId): object
    {
        return $this->accountingDb()->table('cash_up')->where('cashup_id', $cashupId)->get()->getRow();
    }

    private function readsCashupRow(string $sql): bool
    {
        return preg_match('/^SELECT .*FROM\s+`?' . preg_quote($this->accountingDb()->prefixTable('cash_up'), '/') . '`?\s+WHERE cashup_id = \d+/s', trim($sql)) === 1;
    }

    /**
     * @return list<mixed>
     */
    private function claimedColumns(int $cashupId): array
    {
        $row = $this->row($cashupId);

        return [$row->branch_id, $row->treasury_id, $row->expected_amount_cash, $row->cash_difference];
    }

    /**
     * A day of sales: 400 of cash, and the drawer opened with 100, so 500 is expected.
     */
    private function aDayOfCash(): void
    {
        $this->sellFor('250.00');
        $this->sellFor('150.00');
    }

    public function testSaveValue_PostsOnlyTheShortageBetweenTheCountAndWhatWasExpected(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->assertNotNull($cashupId);
        $this->assertSame([$this->accountId('cash_difference') . '=14.50/0.00', $this->accountId('cash') . '=0.00/14.50'], $this->postedLines(self::SOURCE, $cashupId));
        $this->assertSame(['500.0000', '-14.5000'], [$this->row($cashupId)->expected_amount_cash, $this->row($cashupId)->cash_difference]);
        $this->assertSame('14.50', $this->balanceOf($this->accountId('cash_difference')));
    }

    public function testSaveValue_PostsOnlyTheOverageBetweenTheCountAndWhatWasExpected(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('503.00'));

        $this->assertSame([$this->accountId('cash') . '=3.00/0.00', $this->accountId('cash_difference') . '=0.00/3.00'], $this->postedLines(self::SOURCE, $cashupId));
        $this->assertSame('-3.00', $this->balanceOf($this->accountId('cash_difference')));
    }

    public function testSaveValue_PostsNothingWhenTheCountMatchesButStampsTheBranch(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('500.00'));

        $this->assertNotNull($cashupId);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame([1, '0.0000'], [(int) $this->row($cashupId)->branch_id, $this->row($cashupId)->cash_difference]);
    }

    public function testSaveValue_SubtractsTheCashExpensesFromWhatIsExpected(): void
    {
        $this->aDayOfCash();
        $this->saveExpense($this->expenseData('60.00'));

        $cashupId = $this->saveCashup($this->cashupData('440.00'));

        $this->assertSame('440.0000', $this->row($cashupId)->expected_amount_cash);
        $this->assertSame([], $this->journalsOfSource(self::SOURCE, $cashupId));
        $this->assertSame(1, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_AddsTheCashReceiptVouchersToWhatIsExpected(): void
    {
        $this->aDayOfCash();
        (new RevenueVouchers())->save(['date' => '2026-10-20', 'amount' => '60.00', 'tax_amount' => '0', 'payment_type' => 'Cash', 'description' => 'Rent of the stand'], null, $this->clerkId);

        $cashupId = $this->saveCashup($this->cashupData('560.00'));

        $this->assertSame(['560.0000', '0.0000'], [$this->row($cashupId)->expected_amount_cash, $this->row($cashupId)->cash_difference]);
        $this->assertSame([], $this->journalsOfSource(self::SOURCE, $cashupId), 'The counted cash holds the voucher, so no difference is booked');
        $this->assertSame(1, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_ExpectsOnlyTheCashThatWentIntoTheDrawerOfItsBranchWhateverBranchTheSaleWasOf(): void
    {
        $second = $this->createBranch('B2');

        $this->sellFor('250.00');
        $this->accountingDb()->table('sales_payments')->where('sale_id', $this->sellFor('150.00'))->update(['treasury_branch_id' => $second]);

        $cashupId = $this->saveCashup($this->cashupData('350.00'));

        $this->assertSame(['350.0000', '0.0000'], [$this->row($cashupId)->expected_amount_cash, $this->row($cashupId)->cash_difference]);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_ComputesTheExpectedCashOnTheServerAndIgnoresAnythingTheFormClaims(): void
    {
        $this->aDayOfCash();

        $data                         = $this->cashupData('480.00');
        $data['expected_amount_cash'] = '480.00';
        $data['cash_difference']      = '0.00';

        $cashupId = $this->saveCashup($data);

        $this->assertSame(['500.0000', '-20.0000'], [$this->row($cashupId)->expected_amount_cash, $this->row($cashupId)->cash_difference]);
        $this->assertSame(1, $this->postedJournals($cashupId));
    }

    public function testSaveValue_PostsNothingWhileTheCashUpIsOnlyOpened(): void
    {
        $this->aDayOfCash();

        $data = $this->cashupData('0');

        $data['closed_amount_total'] = '0';
        $cashupId                    = $this->saveCashup($data);

        $this->assertNotNull($cashupId);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertNull($this->row($cashupId)->cash_difference);
        $this->assertSame(1, (int) $this->row($cashupId)->branch_id);

        $this->saveCashup($this->cashupData('480.00'), $cashupId);

        $this->assertSame('-20.0000', $this->row($cashupId)->cash_difference);
        $this->assertSame(1, $this->postedJournals($cashupId));
    }

    public function testSaveValue_TreatsCashCountedInOtherWaysAsClosedToo(): void
    {
        $this->aDayOfCash();

        $data                       = $this->cashupData('0');
        $data['closed_amount_card'] = '75';
        $cashupId                   = $this->saveCashup($data);

        $this->assertSame('-500.0000', $this->row($cashupId)->cash_difference);
        $this->assertSame(1, $this->postedJournals($cashupId));
    }

    public function testSaveValue_ReversesAndPostsAgainAsTheNextRevisionWhenTheCountChanges(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->assertSame($cashupId, $this->saveCashup($this->cashupData('490.00'), $cashupId));

        $journals = $this->journalsOfSource(self::SOURCE, $cashupId);

        $this->assertSame([['reversed', '1'], ['posted', '2']], array_map(static fn (object $journal): array => [$journal->status, (string) $journal->revision], $journals));
        $this->assertSame([$this->accountId('cash_difference') . '=10.00/0.00', $this->accountId('cash') . '=0.00/10.00'], $this->postedLines(self::SOURCE, $cashupId));
        $this->assertSame('10.00', $this->balanceOf($this->accountId('cash_difference')));
        $this->assertSame('-10.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testSaveValue_ReversesTheJournalWhenTheCountIsCorrectedToWhatWasExpected(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->saveCashup($this->cashupData('500.00'), $cashupId);

        $this->assertSame(0, $this->postedJournals($cashupId));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash_difference')));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testSaveValue_LeavesTheJournalAloneWhenOnlyTheDescriptionChanges(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));
        $data     = $this->cashupData('485.50');

        $data['description'] = 'Counted twice';

        $this->saveCashup($data, $cashupId);

        $this->assertCount(1, $this->journalsOfSource(self::SOURCE, $cashupId));
    }

    public function testSaveValue_ReversesTheJournalWhenTheCashUpIsMarkedDeletedAndPostsItAgainWhenRestored(): void
    {
        $this->aDayOfCash();

        $cashupId        = $this->saveCashup($this->cashupData('485.50'));
        $data            = $this->cashupData('485.50');
        $data['deleted'] = 1;

        $this->saveCashup($data, $cashupId);

        $this->assertSame(0, $this->postedJournals($cashupId));

        $data['deleted'] = 0;
        $this->saveCashup($data, $cashupId);

        $this->assertSame(1, $this->postedJournals($cashupId));
        $this->assertSame('2', (string) $this->journalsOfSource(self::SOURCE, $cashupId)[1]->revision);
    }

    public function testDeleteList_ReversesTheJournalsOfEveryCashUpOrOfNone(): void
    {
        $this->aDayOfCash();

        $first  = $this->saveCashup($this->cashupData('485.50'));
        $second = $this->saveCashup($this->cashupData('510.00'));

        $this->assertTrue($this->cashups->delete_list([$first, $second], $this->clerkId));
        $this->assertSame([0, 0], [$this->postedJournals($first), $this->postedJournals($second)]);
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash_difference')));
        $this->assertSame(2, $this->accountingDb()->table('cash_up')->where('deleted', 1)->countAllResults());
    }

    public function testDeleteList_RefusesTheWholeBatchWhenOneCashUpIsOutsideTheBranchesOfTheEmployee(): void
    {
        $second  = $this->createBranch('B2');
        $remote  = $this->employeeWithGrants([], [$second]);
        $limited = $this->employeeWithGrants([], [1]);

        $this->aDayOfCash();

        $mine = $this->saveCashup($this->cashupData('485.50'), NEW_ENTRY, $limited);
        $far  = $this->saveCashup($this->cashupData('90.00', '100'), NEW_ENTRY, $remote);

        $this->assertFalse($this->cashups->delete_list([$mine, $far], $limited));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);
        $this->assertSame([1, 1], [$this->postedJournals($mine), $this->postedJournals($far)]);
        $this->assertSame(0, $this->accountingDb()->table('cash_up')->where('deleted', 1)->countAllResults());
    }

    public function testSaveValue_StampsTheDefaultBranchOfTheEmployeeAndCountsOnlyItsSales(): void
    {
        $second = $this->createBranch('B2');
        $worker = $this->employeeWithGrants([], [$second]);

        $this->sellFor('250.00', 'Cash', '2026-10-20 12:00:00', 1);
        $this->sellFor('40.00', 'Cash', '2026-10-20 12:00:00', $second);

        $cashupId = $this->saveCashup($this->cashupData('130.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $worker), NEW_ENTRY, $worker);

        $this->assertSame($second, (int) $this->row($cashupId)->branch_id);
        $this->assertSame('140.0000', $this->row($cashupId)->expected_amount_cash);
        $this->assertSame($second, (int) $this->journalsOfSource(self::SOURCE, $cashupId)[0]->branch_id);
    }

    public function testSaveValue_StampsTheTreasuryTheEmployeeWorksFromAndKeepsItOnEdit(): void
    {
        $this->aDayOfCash();

        $drawer = $this->treasury('Own drawer');
        $other  = $this->treasury('Other drawer');

        (new TreasuryService())->setDefaultFor($this->clerkId, $drawer);

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->assertSame($drawer, (int) $this->row($cashupId)->treasury_id);
        $this->assertSame('-14.50', $this->assertTreasuryTiesToItsAccount($drawer));

        (new TreasuryService())->setDefaultFor($this->clerkId, $other);
        $this->saveCashup($this->cashupData('480.00'), $cashupId);

        $this->assertSame($drawer, (int) $this->row($cashupId)->treasury_id);
        $this->assertSame('-20.00', $this->assertTreasuryTiesToItsAccount($drawer));
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($other));
    }

    public function testSaveValue_RefusesAShortageForAnEmployeeWhoMayNotWithdrawFromTheirTreasury(): void
    {
        $this->aDayOfCash();

        $worker = $this->employeeWithGrants([]);
        $drawer = $this->treasury('Guarded drawer');

        (new TreasuryService())->setDefaultFor($worker, $drawer);
        $this->restrictTreasury($drawer, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $data = $this->cashupData('485.50', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $worker);

        $this->assertNull($this->saveCashup($data, NEW_ENTRY, $worker));
        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->failure()->error);
        $this->assertSame([0, 0], [$this->countRows('cash_up'), $this->countRows('accounting_journals')]);
    }

    public function testSaveValue_AsksTheDepositScopeForAnOverage(): void
    {
        $this->aDayOfCash();

        $worker = $this->employeeWithGrants([]);
        $drawer = $this->treasury('Guarded drawer');

        (new TreasuryService())->setDefaultFor($worker, $drawer);
        $this->restrictTreasury($drawer, TreasuryScopes::DEPOSIT, [$this->clerkId]);

        $data = $this->cashupData('510.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $worker);

        $this->assertNull($this->saveCashup($data, NEW_ENTRY, $worker));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->failure()->error);
    }

    public function testDeleteList_RefusesAnEmployeeWhoMayNotMoveTheDifferenceBack(): void
    {
        $this->aDayOfCash();

        $worker = $this->employeeWithGrants([]);
        $drawer = $this->treasury('Guarded drawer');

        (new TreasuryService())->setDefaultFor($worker, $drawer);

        $cashupId = $this->saveCashup($this->cashupData('485.50', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $worker), NEW_ENTRY, $worker);

        $this->restrictTreasury($drawer, TreasuryScopes::DEPOSIT, [$this->clerkId]);

        $this->assertFalse($this->cashups->delete_list([$cashupId], $worker));
        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->failure()->error);
        $this->assertSame(1, $this->postedJournals($cashupId));
    }

    public function testSaveValue_RefusesADifferenceInAClosedPeriodAndKeepsNothing(): void
    {
        $this->closeThrough($this->daysAgo(1));

        $this->sellFor('250.00', 'Cash', $this->daysAgo(2) . ' 12:00:00');

        $data = $this->cashupData('300.00', '100', '0', $this->daysAgo(2) . ' 08:00:00', $this->daysAgo(2) . ' 18:00:00');

        $this->assertNull($this->saveCashup($data));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame([0, 0], [$this->countRows('cash_up'), $this->countRows('accounting_journals')]);
        $this->assertSame('period_closed', $this->accountingDb()->table('accounting_posting_failures')->get()->getRow()->error_code);
    }

    public function testSaveValue_AllowsACountThatMatchesInAClosedPeriodBecauseThereIsNothingToPost(): void
    {
        $this->closeThrough($this->daysAgo(1));

        $this->sellFor('250.00', 'Cash', $this->daysAgo(2) . ' 12:00:00');

        $data = $this->cashupData('350.00', '100', '0', $this->daysAgo(2) . ' 08:00:00', $this->daysAgo(2) . ' 18:00:00');

        $this->assertNotNull($this->saveCashup($data));
    }

    public function testSaveValue_RefusesChangingTheCountOfACashUpInAClosedPeriod(): void
    {
        $this->sellFor('250.00', 'Cash', $this->daysAgo(2) . ' 12:00:00');

        $cashupId = $this->saveCashup($this->cashupData('340.00', '100', '0', $this->daysAgo(2) . ' 08:00:00', $this->daysAgo(2) . ' 18:00:00'));

        $this->closeThrough($this->daysAgo(1));

        $this->assertNull($this->saveCashup($this->cashupData('330.00', '100', '0', $this->daysAgo(2) . ' 08:00:00', $this->daysAgo(2) . ' 18:00:00'), $cashupId));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame('340.00', $this->row($cashupId)->closed_amount_cash);
        $this->assertSame(1, $this->postedJournals($cashupId));
    }

    public function testDeleteList_RefusesWhenTheDayOfTheReversalIsClosedAndLogsTheFailure(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->closeThrough(date('Y-m-d', strtotime('+1 year')));

        $this->assertFalse($this->cashups->delete_list([$cashupId], $this->clerkId));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame(1, $this->postedJournals($cashupId));

        $failure = $this->accountingDb()->table('accounting_posting_failures')->get()->getRow();

        $this->assertSame([self::SOURCE, $cashupId, 'period_closed'], [$failure->source_type, (int) $failure->source_id, $failure->error_code]);
    }

    public function testSaveValue_RollsTheCashUpBackWhenThePostingFailsAndLogsWhy(): void
    {
        $this->aDayOfCash();

        $group = $this->accountId('treasuries');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'cash.difference')->update(['account_id' => $group]);

        $this->assertNull($this->saveCashup($this->cashupData('485.50')));
        $this->assertInstanceOf(PostingException::class, $this->failure());
        $this->assertSame([0, 0], [$this->countRows('cash_up'), $this->countRows('accounting_journals')]);
        $this->assertSame(self::SOURCE, $this->accountingDb()->table('accounting_posting_failures')->get()->getRow()->source_type);
    }

    public function testSaveValue_NeverPostsACashUpSavedBeforeTheBooksFollowedItNorStampsIt(): void
    {
        $this->aDayOfCash();
        $this->setConfigValue('accounting_enabled', '0');

        $legacy = $this->saveCashup($this->cashupData('485.50'));

        $this->setConfigValue('accounting_enabled', '1');

        $this->assertSame($legacy, $this->saveCashup($this->cashupData('470.00'), $legacy));
        $this->assertSame([null, null, '470.00'], [$this->row($legacy)->branch_id, $this->row($legacy)->cash_difference, $this->row($legacy)->closed_amount_cash]);
        $this->assertTrue($this->cashups->delete_list([$legacy], $this->clerkId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_LeavesACashUpDatedBeforeTheStartOfTheBooksOut(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-21');
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->assertNull($this->row($cashupId)->branch_id);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame([$cashupId], array_map(static fn (object $cashup): int => (int) $cashup->cashup_id, (new CashupPosting())->unposted()));
    }

    public function testSaveValue_RefusesChangingACashUpOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second  = $this->createBranch('B2');
        $remote  = $this->employeeWithGrants([], [$second]);
        $limited = $this->employeeWithGrants([], [1]);

        $cashupId = $this->saveCashup($this->cashupData('90.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $remote), NEW_ENTRY, $remote);

        $this->assertNull($this->saveCashup($this->cashupData('60.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $remote), $cashupId, $limited));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);
        $this->assertSame(['90.00', '-10.0000'], [$this->row($cashupId)->closed_amount_cash, $this->row($cashupId)->cash_difference]);
        $this->assertSame(1, count($this->journalsOfSource(self::SOURCE, $cashupId)));
    }

    public function testSaveValue_RefusesMarkingDeletedACashUpOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second  = $this->createBranch('B2');
        $remote  = $this->employeeWithGrants([], [$second]);
        $limited = $this->employeeWithGrants([], [1]);
        $data    = $this->cashupData('90.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $remote);

        $cashupId = $this->saveCashup($data, NEW_ENTRY, $remote);

        $data['deleted'] = 1;

        $this->assertNull($this->saveCashup($data, $cashupId, $limited));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);
        $this->assertSame(1, $this->postedJournals($cashupId));
        $this->assertSame('0', (string) $this->row($cashupId)->deleted);
    }

    public function testSaveValue_RefusesMovingACashUpOfABranchTheEmployeeDoesNotWorkInBeforeTheStartOfTheBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');

        $second  = $this->createBranch('B2');
        $remote  = $this->employeeWithGrants([], [$second]);
        $limited = $this->employeeWithGrants([], [1]);

        $cashupId = $this->saveCashup($this->cashupData('90.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $remote), NEW_ENTRY, $remote);

        $this->assertNull($this->saveCashup($this->cashupData('90.00', '100', '0', '2026-09-15 08:00:00', '2026-09-15 18:00:00', $remote), $cashupId, $limited));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);
        $this->assertSame(1, $this->postedJournals($cashupId));
        $this->assertStringStartsWith('2026-10-20', $this->row($cashupId)->close_date);
    }

    public function testSaveValue_KeepsTheJournalWhenAnEditNamesNoAmountAtAll(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('515.50', '100', '30'));

        $this->assertSame($cashupId, $this->saveCashup(['description' => 'Checked again'], $cashupId));
        $this->assertSame(1, count($this->journalsOfSource(self::SOURCE, $cashupId)));
        $this->assertSame(1, $this->postedJournals($cashupId));
        $this->assertSame(['530.0000', '-14.5000', 'Checked again'], [$this->row($cashupId)->expected_amount_cash, $this->row($cashupId)->cash_difference, $this->row($cashupId)->description]);
    }

    public function testSaveValue_LeavesTheStampedCashAndDifferenceAloneWhenACashUpMovesBeforeTheStartOfTheBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->assertSame($cashupId, $this->saveCashup($this->cashupData('470.00', '100', '0', '2026-09-15 08:00:00', '2026-09-15 18:00:00'), $cashupId));
        $this->assertSame(0, $this->postedJournals($cashupId));
        $this->assertSame(['470.00', '500.0000', '-14.5000'], [$this->row($cashupId)->closed_amount_cash, $this->row($cashupId)->expected_amount_cash, $this->row($cashupId)->cash_difference]);
    }

    public function testSaveValue_LeavesTheStampedCashAndDifferenceAloneWhenACashUpIsMarkedDeleted(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));
        $data     = $this->cashupData('470.00');

        $data['deleted'] = 1;

        $this->assertSame($cashupId, $this->saveCashup($data, $cashupId));
        $this->assertSame(0, $this->postedJournals($cashupId));
        $this->assertSame(['470.00', '500.0000', '-14.5000'], [$this->row($cashupId)->closed_amount_cash, $this->row($cashupId)->expected_amount_cash, $this->row($cashupId)->cash_difference]);
    }

    public function testSaveValue_NeverStoresWhatTheFormClaimsAboutACashUpTheBooksDoNotFollow(): void
    {
        $this->aDayOfCash();
        $this->setConfigValue('accounting_enabled', '0');

        $legacy = $this->saveCashup($this->cashupData('485.50'));

        $this->setConfigValue('accounting_enabled', '1');

        $claims = ['branch_id' => 1, 'treasury_id' => $this->treasury('Claimed drawer'), 'expected_amount_cash' => '480.00', 'cash_difference' => '5.00'];

        $this->assertSame($legacy, $this->saveCashup($this->cashupData('470.00') + $claims, $legacy));
        $this->assertSame([null, null, null, null], $this->claimedColumns($legacy));

        $this->setConfigValue('accounting_start_date', '2026-10-21');

        $early = $this->saveCashup($this->cashupData('485.50') + $claims);

        $this->assertNotNull($early);
        $this->assertSame([null, null, null, null], $this->claimedColumns($early));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testFollows_IsFalseWhileAccountingIsOffWhateverTheDate(): void
    {
        $posting = new CashupPosting();

        $this->assertTrue($posting->follows('2026-10-20'));

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertFalse($posting->follows('2026-10-20'));
        $this->assertFalse($posting->follows('2030-01-01'));
    }

    public function testSaveValue_StampsNothingOnANewCashUpThatIsAlreadyMarkedDeleted(): void
    {
        $this->aDayOfCash();

        $data            = $this->cashupData('485.50');
        $data['deleted'] = 1;

        $cashupId = $this->saveCashup($data);

        $this->assertNotNull($cashupId);
        $this->assertSame([null, null, null, null], $this->claimedColumns($cashupId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_JudgesACashUpByTheEmployeeWhoClosesItWhenNoActorIsGiven(): void
    {
        $second  = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([], [1]);
        $remote  = $this->employeeWithGrants([], [$second]);

        $cashupId = $this->saveCashup($this->cashupData('90.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $remote), NEW_ENTRY, $remote);
        $data     = $this->cashupData('60.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $limited);

        $this->assertFalse($this->cashups->save_value($data, $cashupId));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);

        $own = $this->cashupData('60.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $limited);

        $this->assertTrue($this->cashups->save_value($own, NEW_ENTRY));
        $this->assertSame($limited, (int) $this->journalsOfSource(self::SOURCE, (int) $own['cashup_id'])[0]->created_by);
    }

    public function testSaveValue_ReadsTheStoredCashUpUnderALockBeforeChangingIt(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));
        $queries  = $this->queriesOf(fn () => $this->saveCashup($this->cashupData('490.00'), $cashupId));
        $reads    = array_values(array_filter($queries, fn (string $sql): bool => $this->readsCashupRow($sql)));

        $this->assertCount(1, $reads);
        $this->assertStringEndsWith('FOR UPDATE', trim($reads[0]));
        $this->assertLessThan($this->firstIndexOf($queries, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE') && str_contains($sql, 'cash_up')), $this->firstIndexOf($queries, fn (string $sql): bool => $this->readsCashupRow($sql)));
    }

    public function testDeleteList_LocksTheCashUpsInTheOrderOfTheirNumbers(): void
    {
        $this->aDayOfCash();

        $first  = $this->saveCashup($this->cashupData('485.50'));
        $second = $this->saveCashup($this->cashupData('490.00'));
        $third  = $this->saveCashup($this->cashupData('495.00'));

        $queries = $this->queriesOf(fn () => $this->cashups->delete_list([$third, $first, $second, $first], $this->clerkId));
        $locked  = [];

        foreach ($queries as $sql) {
            if ($this->readsCashupRow($sql) && preg_match('/cashup_id = (\d+)/', $sql, $found) === 1) {
                $locked[] = (int) $found[1];
            }
        }

        $this->assertSame([$first, $second, $third], $locked);
    }

    public function testUnposted_ListsOnlyTheCashUpsTheBooksNeverFollowed(): void
    {
        $this->aDayOfCash();
        $this->setConfigValue('accounting_enabled', '0');

        $legacy = $this->saveCashup($this->cashupData('485.50'));

        $this->setConfigValue('accounting_enabled', '1');

        $followed = $this->saveCashup($this->cashupData('500.00'));
        $posting  = new CashupPosting();

        $this->assertSame([$legacy], array_map(static fn (object $cashup): int => (int) $cashup->cashup_id, $posting->unposted()));
        $this->assertSame(1, $posting->unpostedCount());
        $this->assertSame(1, $posting->unpostedCount([1]));
        $this->assertSame(0, $posting->unpostedCount([$this->createBranch('B2')]));
        $this->assertNotSame($followed, $legacy);
    }

    public function testUnposted_LeavesOutACashUpTheBooksNeverFollowedOnceItIsDeleted(): void
    {
        $this->aDayOfCash();
        $this->setConfigValue('accounting_enabled', '0');

        $legacy = $this->saveCashup($this->cashupData('485.50'));

        $this->setConfigValue('accounting_enabled', '1');

        $posting = new CashupPosting();

        $this->assertSame(1, $posting->unpostedCount());
        $this->assertTrue($this->cashups->delete_list([$legacy], $this->clerkId));
        $this->assertSame([[], 0], [$posting->unposted(), $posting->unpostedCount()]);
    }

    public function testSaveValue_ChangesNothingInTheBooksWhenAccountingIsOff(): void
    {
        $this->aDayOfCash();
        $this->setConfigValue('accounting_enabled', '0');

        $data                         = $this->cashupData('485.50');
        $data['branch_id']            = 1;
        $data['treasury_id']          = $this->treasury('Ignored drawer');
        $data['cash_difference']      = '-14.50';
        $data['expected_amount_cash'] = '500.00';

        $cashupId = $this->saveCashup($data);
        $row      = $this->row($cashupId);

        $this->assertSame([null, null, null, null], [$row->branch_id, $row->treasury_id, $row->expected_amount_cash, $row->cash_difference]);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertTrue($this->cashups->delete_list([$cashupId]));
    }

    public function testSaveValue_WritesTheAuditTrailOfEveryChange(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->saveCashup($this->cashupData('490.00'), $cashupId);
        $this->cashups->delete_list([$cashupId], $this->clerkId);

        $actions = array_column($this->accountingDb()->table('accounting_audit_log')->select('action')->where(['entity_type' => 'cashup', 'entity_id' => $cashupId])->orderBy('audit_id')->get()->getResultArray(), 'action');

        $this->assertSame(['posted', 'reposted', 'cancelled'], $actions);
    }

    public function testSaveValue_AuditsTheCancellationOfACashUpMarkedDeletedOrCorrectedToWhatWasExpected(): void
    {
        $this->aDayOfCash();

        $deletedId   = $this->saveCashup($this->cashupData('485.50'));
        $correctedId = $this->saveCashup($this->cashupData('470.00'));
        $data        = $this->cashupData('485.50');

        $data['deleted'] = 1;
        $this->saveCashup($data, $deletedId);
        $this->saveCashup($this->cashupData('500.00'), $correctedId);

        foreach ([$deletedId, $correctedId] as $cashupId) {
            $actions = array_column($this->accountingDb()->table('accounting_audit_log')->select('action')->where(['entity_type' => 'cashup', 'entity_id' => $cashupId])->orderBy('audit_id')->get()->getResultArray(), 'action');

            $this->assertSame(['posted', 'cancelled'], $actions);
        }
    }

    public function testSync_IsIdempotentAndGivesNoJournalToACashUpTheBooksNeverFollowed(): void
    {
        $this->aDayOfCash();

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->assertSame(JournalChange::Unchanged, (new CashupPosting())->sync($cashupId, $this->clerkId));
        $this->assertCount(1, $this->journalsOfSource(self::SOURCE, $cashupId));

        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveCashup($this->cashupData('480.00'));
        $this->setConfigValue('accounting_enabled', '1');

        $this->assertSame(JournalChange::None, (new CashupPosting())->sync($legacy, $this->clerkId));

        $this->accountingDb()->table('cash_up')->where('cashup_id', $legacy)->update(['cash_difference' => '-19.5000']);

        $this->assertSame(JournalChange::None, (new CashupPosting())->sync($legacy, $this->clerkId));
        $this->assertSame([], $this->journalsOfSource(self::SOURCE, $legacy));
    }

    public function testExpectedFor_TellsTheScreenWhatTheDrawerOfTheEmployeeShouldHold(): void
    {
        $this->aDayOfCash();

        $this->assertSame('500.0000', (new CashupPosting())->expectedFor($this->clerkId, '2026-10-20 08:00:00', '2026-10-20 18:00:00', '100', '0'));
    }

    public function testExpectedFor_CountsTheBranchTheEmployeeWorksInByDefault(): void
    {
        $second = $this->createBranch('B2');
        $worker = $this->employeeWithGrants([], [$second]);

        $this->sellFor('250.00', 'Cash', '2026-10-20 12:00:00', 1);
        $this->sellFor('40.00', 'Cash', '2026-10-20 12:00:00', $second);

        $this->assertSame('140.0000', (new CashupPosting())->expectedFor($worker, '2026-10-20 08:00:00', '2026-10-20 18:00:00', '100', '0'));
        $this->assertSame('350.0000', (new CashupPosting())->expectedFor($this->clerkId, '2026-10-20 08:00:00', '2026-10-20 18:00:00', '100', '0'));
    }

    public function testSaveValue_KeepsTheTreasuryAndTheTrialBalanceInStepWhateverIsDone(): void
    {
        $this->aDayOfCash();

        $drawer = $this->treasury('Tied drawer');

        (new TreasuryService())->setDefaultFor($this->clerkId, $drawer);

        $cashupId = $this->saveCashup($this->cashupData('485.50'));

        $this->saveCashup($this->cashupData('520.00'), $cashupId);
        $this->saveCashup($this->cashupData('500.00'), $cashupId);

        $row = $this->accountingDb()->table('accounting_journal_lines')->select('SUM(debit) AS debit, SUM(credit) AS credit', false)->get()->getRow();

        $this->assertSame(bcadd((string) $row->debit, '0', 4), bcadd((string) $row->credit, '0', 4));
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($drawer));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash_difference')));
    }

    public function testDeleteList_RefusesAnEmployeeOutsideTheBranchOfACashUpThatHasNoJournal(): void
    {
        $second  = $this->createBranch('B2');
        $remote  = $this->employeeWithGrants([], [$second]);
        $limited = $this->employeeWithGrants([], [1]);

        $cashupId = $this->saveCashup($this->cashupData('100.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 18:00:00', $remote), NEW_ENTRY, $remote);

        $this->assertSame([], $this->journalsOfSource(self::SOURCE, $cashupId));
        $this->assertSame($second, (int) $this->row($cashupId)->branch_id);
        $this->assertFalse($this->cashups->delete_list([$cashupId], $limited));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);
        $this->assertSame(0, $this->accountingDb()->table('cash_up')->where('deleted', 1)->countAllResults());
        $this->assertTrue($this->cashups->delete_list([$cashupId], $remote));
        $this->assertSame(1, $this->accountingDb()->table('cash_up')->where('deleted', 1)->countAllResults());
    }

    public function testDeleteList_StillLetsAnyEmployeeDeleteACashUpTheBooksNeverFollowed(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveCashup($this->cashupData('90.00'));
        $this->setConfigValue('accounting_enabled', '1');

        $limited = $this->employeeWithGrants([], [$this->createBranch('B2')]);

        $this->assertTrue($this->cashups->delete_list([$legacy], $limited));
        $this->assertSame(1, $this->accountingDb()->table('cash_up')->where('deleted', 1)->countAllResults());
    }

    public function testSaveValue_CountsTheSalesOfTheWholeDayForEveryCashUpOfTheDayWhileTheFilterIsByDay(): void
    {
        $this->sellFor('400.00', 'Cash', '2026-10-20 09:00:00');

        $morning = $this->saveCashup($this->cashupData('500.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 13:00:00'));
        $evening = $this->saveCashup($this->cashupData('10.00', '0', '0', '2026-10-20 14:00:00', '2026-10-20 20:00:00'));

        $this->assertSame(0, $this->postedJournals($morning));
        $this->assertSame(['400.0000', '-390.0000'], [$this->row($evening)->expected_amount_cash, $this->row($evening)->cash_difference]);
        $this->assertSame(1, $this->postedJournals($evening));
    }

    public function testSaveValue_CountsOnlyTheSalesOfEachCashUpOfTheDayWhenTheFilterAsksForTimes(): void
    {
        $settings = config(OSPOS::class)->settings;

        config(OSPOS::class)->settings['date_or_time_format'] = '1';

        try {
            $this->sellFor('400.00', 'Cash', '2026-10-20 09:00:00');

            $morning = $this->saveCashup($this->cashupData('500.00', '100', '0', '2026-10-20 08:00:00', '2026-10-20 13:00:00'));
            $evening = $this->saveCashup($this->cashupData('0.00', '0', '0', '2026-10-20 14:00:00', '2026-10-20 20:00:00'));
            $counted = $this->saveCashup($this->cashupData('10.00', '0', '0', '2026-10-20 14:00:00', '2026-10-20 20:00:00'));

            $this->assertSame(0, $this->postedJournals($morning));
            $this->assertNull($this->row($evening)->expected_amount_cash);
            $this->assertSame(['0.0000', '10.0000'], [$this->row($counted)->expected_amount_cash, $this->row($counted)->cash_difference]);
            $this->assertSame([$this->accountId('cash') . '=10.00/0.00', $this->accountId('cash_difference') . '=0.00/10.00'], $this->postedLines(self::SOURCE, $counted));
        } finally {
            config(OSPOS::class)->settings = $settings;
        }
    }
}
