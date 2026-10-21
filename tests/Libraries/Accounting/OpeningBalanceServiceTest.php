<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\OpeningBalanceService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;

class OpeningBalanceServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private OpeningBalanceService $service;

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
        $custom = $this->customAccount();
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'receivables.control')->update(['account_id' => $custom]);

        $ids = array_map('intval', array_column($this->service->allowedAccounts(), 'account_id'));

        $this->assertNotContains($custom, $ids);
        $this->assertContains($this->accountId('receivables'), $ids);
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

    public function testReverseOpening_RefusesAJournalThatIsNotAnOpeningBalance(): void
    {
        $manual = (new JournalService())->postNew($this->journalInput());

        $this->assertFailsWith(AccountingError::JournalNotFound, fn () => (new JournalService())->reverseOpening($manual, '2026-10-22', 'Reason', $this->manager()));
    }
}
