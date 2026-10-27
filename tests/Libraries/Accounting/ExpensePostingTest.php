<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\ExpensePosting;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalChange;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\TreasuryScopes;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * What an expense puts in the books, through the real expense model: one journal inside the save
 * transaction, the tax split off to the account of tax paid, reversed and posted again as the next
 * revision when the expense changes, reversed when it is deleted, and never touched when the
 * expense was saved before the books followed it. Every refusal leaves nothing of the change behind.
 */
class ExpensePostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private const SOURCE = ExpensePostingBuilder::SOURCE;

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
        $failure = $this->expenses->accountingFailure();

        $this->assertTrue($failure instanceof AccountingException || $failure instanceof PostingException || $failure instanceof BranchException, 'The books did not refuse the change.');

        return $failure;
    }

    private function readsExpenseRow(string $sql): bool
    {
        return preg_match('/^SELECT .*FROM\s+`?' . preg_quote($this->accountingDb()->prefixTable('expenses'), '/') . '`?\s+WHERE expense_id = \d+/s', trim($sql)) === 1;
    }

    private function assertRefused(AccountingError|PostingError|BranchError $error): void
    {
        $failure = $this->failure();

        $this->assertSame($error, $failure->error);
    }

    private function postedJournals(int $expenseId): int
    {
        return count(array_filter($this->journalsOfSource(self::SOURCE, $expenseId), static fn (object $journal): bool => $journal->status === 'posted'));
    }

    /**
     * Nothing of a refused change is left: no new expense, no journal, no stock of anything.
     */
    private function assertNothingWritten(int $expenses = 0, int $journals = 0): void
    {
        $this->assertSame($expenses, $this->countRows('expenses'));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_PostsOneBalancedJournalInTheSaveTransaction(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('230.00', '30.00'));

        $this->assertNotNull($expenseId);
        $this->assertSame(
            [$this->accountId('expenses_default') . '=200.00/0.00', $this->accountId('tax_input') . '=30.00/0.00', $this->accountId('cash') . '=0.00/230.00'],
            $this->postedLines(self::SOURCE, $expenseId),
        );

        $journal = $this->journalsOfSource(self::SOURCE, $expenseId)[0];

        $this->assertSame(['posted', '1', '230.0000', '230.0000', 'general', 'Expense #' . $expenseId], [$journal->status, $journal->revision, $journal->total_debit, $journal->total_credit, $journal->entry_type, $journal->description]);
        $this->assertSame(1, $this->postedJournals($expenseId));
    }

    public function testSaveValue_StampsTheBranchOfTheEmployeeWithoutATreasury(): void
    {
        $second = $this->createBranch('B2');
        $worker = $this->employeeWithGrants([], [$second, 1]);

        $expenseId = $this->saveExpense($this->expenseData('50.00'), NEW_ENTRY, $worker);

        $this->assertSame($second, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->branch_id);
        $this->assertSame($second, (int) $this->journalsOfSource(self::SOURCE, $expenseId)[0]->branch_id);
        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
    }

    public function testSaveValue_CreditsTheNamedTreasuryAndTakesItsBranch(): void
    {
        $second   = $this->createBranch('B2');
        $treasury = $this->treasury('Branch drawer', $second);
        $data     = $this->expenseData('230.00', '30.00');
        $data['treasury_id'] = $treasury;

        $expenseId = $this->saveExpense($data);
        $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([$second, $treasury], [(int) $row->branch_id, (int) $row->treasury_id]);
        $this->assertSame((int) (new TreasuryService())->accountOf($treasury) . '=0.00/230.00', $this->postedLines(self::SOURCE, $expenseId)[2]);
        $this->assertSame($second, (int) $this->journalsOfSource(self::SOURCE, $expenseId)[0]->branch_id);
    }

    public function testSaveValue_TakesTheMoneyOutOfTheTreasuryAndItsBalanceEqualsItsAccount(): void
    {
        $treasury = $this->treasury();
        $data     = $this->expenseData('230.00', '30.00');
        $data['treasury_id'] = $treasury;

        $this->saveExpense($data);

        $this->assertSame('-230.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }

    public function testSaveValue_PaysFromTheTreasuryTheEmployeeWorksFrom(): void
    {
        $treasury = $this->treasury();

        (new TreasuryService())->setDefaultFor($this->clerkId, $treasury);

        $expenseId = $this->saveExpense($this->expenseData('80.00'));

        $this->assertSame($treasury, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
        $this->assertSame('-80.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }

    public function testSaveValue_ChargesTheAccountOfTheCategory(): void
    {
        $account = $this->customAccount('5', '5990', 'Marketing', false);

        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $this->category('Ads', $account)));

        $this->assertSame($account . '=100.00/0.00', $this->postedLines(self::SOURCE, $expenseId)[0]);
        $this->assertSame('100.00', $this->balanceOf($account));
    }

    public function testSaveValue_ChargesTheRoutedAccountWhenTheCategoryHasNoAccount(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('100.00'));

        $this->assertSame($this->accountId('expenses_default') . '=100.00/0.00', $this->postedLines(self::SOURCE, $expenseId)[0]);
    }

    public function testSaveValue_RequiresACategoryAccountWhenTheSettingIsOn(): void
    {
        $this->setConfigValue('accounting_expense_account_required', '1');

        $this->assertNull($this->saveExpense($this->expenseData('100.00')));
        $this->assertRefused(AccountingError::ExpenseCategoryAccountRequired);
        $this->assertNothingWritten();

        $account = $this->customAccount('5', '5991', 'Marketing', false);

        $this->assertNotNull($this->saveExpense($this->expenseData('100.00', '0', 'Cash', $this->category('Ads', $account))));
    }

    public function testSaveValue_KeepsAnExpenseOfACategoryWithoutAccountEditableAfterTheSettingTurnsOn(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));

        $this->setConfigValue('accounting_expense_account_required', '1');

        $data                = $this->expenseData('100.00', '0', 'Cash', $category);
        $data['description'] = 'Edited';

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));
        $this->assertSame(1, $this->postedJournals($expenseId));
    }

    public function testSaveValue_LeavesTheJournalAloneWhenNothingThatMattersChanges(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('230.00', '30.00'));
        $data      = $this->expenseData('230.00', '30.00', 'Cash', (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->expense_category_id);

        $data['description'] = 'A new description';

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));
        $this->assertCount(1, $this->journalsOfSource(self::SOURCE, $expenseId));
        $this->assertSame(0, $this->countRows('accounting_posting_failures'));
    }

    public function testSaveValue_ReversesAndPostsAgainAsTheNextRevisionWhenTheAmountChanges(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('230.00', '30.00', 'Cash', $category));

        $this->assertSame($expenseId, $this->saveExpense($this->expenseData('115.00', '15.00', 'Cash', $category), $expenseId));

        $journals = $this->journalsOfSource(self::SOURCE, $expenseId);

        $this->assertCount(2, $journals);
        $this->assertSame(['reversed', '1'], [$journals[0]->status, $journals[0]->revision]);
        $this->assertSame(['posted', '2'], [$journals[1]->status, $journals[1]->revision]);
        $this->assertSame([$this->accountId('expenses_default') . '=100.00/0.00', $this->accountId('tax_input') . '=15.00/0.00', $this->accountId('cash') . '=0.00/115.00'], $this->postedLines(self::SOURCE, $expenseId));
        $this->assertSame('100.00', $this->balanceOf($this->accountId('expenses_default')));
        $this->assertSame('-115.00', $this->balanceOf($this->accountId('cash')));
        $this->assertSame('15.00', $this->balanceOf($this->accountId('tax_input')));
    }

    public function testSaveValue_WritesTheReversalAsAJournalOfItsOwnThatUndoesTheOriginal(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('230.00', '30.00', 'Cash', $category));

        $this->saveExpense($this->expenseData('10.00', '0', 'Cash', $category), $expenseId);

        $original = $this->journalsOfSource(self::SOURCE, $expenseId)[0];
        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $original->journal_id)->get()->getRow();

        $this->assertNotNull($reversal);
        $this->assertSame($this->accountId('expenses_default') . '=0.00/200.00', $this->linesOfJournal((int) $reversal->journal_id)[0]);
        $this->assertSame($this->accountId('cash') . '=230.00/0.00', $this->linesOfJournal((int) $reversal->journal_id)[2]);
    }

    public function testSaveValue_PostsAgainWhenTheDateMoves(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, '2026-10-20 10:00:00'));

        $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, '2026-10-22 10:00:00'), $expenseId);

        $journals = $this->journalsOfSource(self::SOURCE, $expenseId);

        $this->assertSame(['2026-10-20', 'reversed'], [$journals[0]->journal_date, $journals[0]->status]);
        $this->assertSame(['2026-10-22', 'posted'], [$journals[1]->journal_date, $journals[1]->status]);
    }

    public function testSaveValue_PostsAgainWhenTheTreasuryChanges(): void
    {
        $first  = $this->treasury('First');
        $second = $this->treasury('Second');
        $data   = $this->expenseData('60.00', '0', 'Cash', $this->category());

        $data['treasury_id'] = $first;
        $expenseId           = $this->saveExpense($data);
        $data['treasury_id'] = $second;

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));
        $this->assertSame(['0.00', '-60.00'], [$this->assertTreasuryTiesToItsAccount($first), $this->assertTreasuryTiesToItsAccount($second)]);
    }

    public function testSaveValue_KeepsTheTreasuryOfAnExpenseThatNamesNoneOnEdit(): void
    {
        $treasury = $this->treasury();
        $category = $this->category();
        $data     = $this->expenseData('60.00', '0', 'Cash', $category);

        $data['treasury_id'] = $treasury;
        $expenseId           = $this->saveExpense($data);

        unset($data['treasury_id']);
        $data['amount'] = '70.00';

        $this->saveExpense($data, $expenseId);

        $this->assertSame($treasury, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
        $this->assertSame('-70.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }

    public function testSaveValue_ReversesTheJournalWhenTheExpenseIsMarkedDeletedAndPostsItAgainWhenRestored(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));
        $data      = $this->expenseData('100.00', '0', 'Cash', $category);

        $data['deleted'] = 1;
        $this->saveExpense($data, $expenseId);

        $this->assertSame(0, $this->postedJournals($expenseId));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('expenses_default')));

        $data['deleted'] = 0;
        $this->saveExpense($data, $expenseId);

        $this->assertSame(1, $this->postedJournals($expenseId));
        $this->assertSame('2', (string) $this->journalsOfSource(self::SOURCE, $expenseId)[1]->revision);
        $this->assertSame('100.00', $this->balanceOf($this->accountId('expenses_default')));
    }

    public function testSaveValue_PostsADueExpenseToOtherCreditorsAndClearsItWhenItIsPaid(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Due', $category));

        $this->assertSame('-100.00', $this->balanceOf($this->accountId('other_payables')));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));

        $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category), $expenseId);

        $this->assertSame('0.00', $this->balanceOf($this->accountId('other_payables')));
        $this->assertSame('-100.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testSaveValue_PostsMoneyThatCameBackTurnedAround(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('-230.00', '-30.00'));

        $this->assertSame([$this->accountId('expenses_default') . '=0.00/200.00', $this->accountId('tax_input') . '=0.00/30.00', $this->accountId('cash') . '=230.00/0.00'], $this->postedLines(self::SOURCE, $expenseId));
    }

    public function testSaveValue_SavesAnExpenseOfZeroWithoutAJournalAndPostsItWhenItChanges(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('0.00', '0', 'Cash', $category));

        $this->assertNotNull($expenseId);
        $this->assertSame(0, $this->countRows('accounting_journals'));

        $this->saveExpense($this->expenseData('25.00', '0', 'Cash', $category), $expenseId);

        $this->assertSame(1, $this->postedJournals($expenseId));
    }

    public function testSaveValue_WritesTheAuditTrailOfEveryChange(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));

        $this->saveExpense($this->expenseData('120.00', '0', 'Cash', $category), $expenseId);
        $this->expenses->delete_list([$expenseId], $this->clerkId);

        $actions = array_column($this->accountingDb()->table('accounting_audit_log')->select('action')->where(['entity_type' => 'expense', 'entity_id' => $expenseId])->orderBy('audit_id')->get()->getResultArray(), 'action');

        $this->assertSame(['posted', 'reposted', 'cancelled'], $actions);
    }

    public function testSaveValue_RefusesADateInAClosedPeriodAndLogsTheFailure(): void
    {
        $this->closeThrough($this->daysAgo(1));

        $this->assertNull($this->saveExpense($this->expenseData('100.00', '0', 'Cash', null, $this->daysAgo(2) . ' 10:00:00')));
        $this->assertRefused(PostingError::PeriodClosed);
        $this->assertNothingWritten();

        $failure = $this->accountingDb()->table('accounting_posting_failures')->get()->getRow();

        $this->assertSame([self::SOURCE, 'period_closed'], [$failure->source_type, $failure->error_code]);
    }

    public function testDeleteList_RefusesWhenTheDayOfTheReversalIsClosedAndLogsTheFailure(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('100.00'));

        $this->closeThrough(date('Y-m-d', strtotime('+1 year')));

        $this->assertFalse($this->expenses->delete_list([$expenseId], $this->clerkId));
        $this->assertSame(PostingError::PeriodClosed, $this->failure()->error);
        $this->assertSame(1, $this->postedJournals($expenseId));

        $failure = $this->accountingDb()->table('accounting_posting_failures')->get()->getRow();

        $this->assertSame([self::SOURCE, $expenseId, 'period_closed'], [$failure->source_type, (int) $failure->source_id, $failure->error_code]);
    }

    public function testSaveValue_RefusesMovingAnExpenseIntoAClosedPeriodAndKeepsItAsItWas(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, date('Y-m-d') . ' 09:00:00'));

        $this->closeThrough($this->daysAgo(1));

        $this->assertNull($this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, $this->daysAgo(3) . ' 09:00:00'), $expenseId));
        $this->assertRefused(PostingError::PeriodClosed);
        $this->assertSame(date('Y-m-d'), substr((string) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->date, 0, 10));
        $this->assertCount(1, $this->journalsOfSource(self::SOURCE, $expenseId));
        $this->assertSame(1, $this->postedJournals($expenseId));
    }

    public function testSaveValue_RefusesChangingTheAmountOfAnExpenseThatIsInAClosedPeriod(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, $this->daysAgo(2) . ' 09:00:00'));

        $this->closeThrough($this->daysAgo(1));

        $this->assertNull($this->saveExpense($this->expenseData('150.00', '0', 'Cash', $category, $this->daysAgo(2) . ' 09:00:00'), $expenseId));
        $this->assertRefused(PostingError::PeriodClosed);
        $this->assertSame('100.00', $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->amount);
        $this->assertCount(1, $this->journalsOfSource(self::SOURCE, $expenseId));
    }

    public function testDeleteList_TakesAnExpenseOfAClosedPeriodBackOnTheFirstOpenDay(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', null, $this->daysAgo(2) . ' 09:00:00'));

        $this->closeThrough($this->daysAgo(1));

        $this->assertTrue($this->expenses->delete_list([$expenseId], $this->clerkId));

        $journals = $this->journalsOfSource(self::SOURCE, $expenseId);

        $this->assertSame('reversed', $journals[0]->status);
        $this->assertSame('0.00', $this->balanceOf($this->accountId('expenses_default')));
        $this->assertSame(date('Y-m-d'), $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journals[0]->journal_id)->get()->getRow()->journal_date);
    }

    public function testDeleteList_ReversesTheJournalsOfEveryExpenseOrOfNone(): void
    {
        $category = $this->category();
        $first    = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));
        $second   = $this->saveExpense($this->expenseData('40.00', '0', 'Cash', $category));

        $this->assertTrue($this->expenses->delete_list([$first, $second], $this->clerkId));
        $this->assertSame([0, 0], [$this->postedJournals($first), $this->postedJournals($second)]);
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
        $this->assertSame(2, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    public function testDeleteList_RefusesTheWholeBatchWhenOneExpenseIsOutsideTheBranchesOfTheEmployee(): void
    {
        $second   = $this->createBranch('B2');
        $category = $this->category();
        $treasury = $this->treasury('Remote drawer', $second);
        $remote   = $this->expenseData('40.00', '0', 'Cash', $category);

        $remote['treasury_id'] = $treasury;

        $mine    = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));
        $far     = $this->saveExpense($remote);
        $limited = $this->employeeWithGrants([], [1]);

        $this->assertFalse($this->expenses->delete_list([$mine, $far], $limited));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);
        $this->assertSame([1, 1], [$this->postedJournals($mine), $this->postedJournals($far)]);
        $this->assertSame(0, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    public function testSaveValue_RefusesATreasuryOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second  = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([], [1]);
        $data    = $this->expenseData('40.00', '0', 'Cash', $this->category(), '2026-10-20 10:00:00', $limited);

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $this->assertNull($this->saveExpense($data, NEW_ENTRY, $limited));
        $this->assertRefused(BranchError::NotAllowed);
        $this->assertNothingWritten();
    }

    public function testSaveValue_RefusesChangingAnExpenseOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second   = $this->createBranch('B2');
        $category = $this->category();
        $data     = $this->expenseData('40.00', '0', 'Cash', $category);

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $expenseId = $this->saveExpense($data);
        $limited   = $this->employeeWithGrants([], [1]);

        $this->assertNull($this->saveExpense($this->expenseData('90.00', '0', 'Cash', $category), $expenseId, $limited));
        $this->assertRefused(BranchError::NotAllowed);
        $this->assertSame('40.00', $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->amount);
    }

    public function testSaveValue_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasury(): void
    {
        $outsider = $this->employeeWithGrants([]);
        $treasury = (new TreasuryService())->create(['name' => 'Locked ' . uniqid(), 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->clerkId]]);
        $data     = $this->expenseData('40.00', '0', 'Cash', $this->category(), '2026-10-20 10:00:00', $outsider);

        $data['treasury_id'] = $treasury;

        $this->assertNull($this->saveExpense($data, NEW_ENTRY, $outsider));
        $this->assertRefused(AccountingError::TreasuryWithdrawDenied);
        $this->assertNothingWritten();
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }

    public function testSaveValue_AllowsAnEmployeeWhoMayWithdrawFromTheTreasury(): void
    {
        $treasury = (new TreasuryService())->create(['name' => 'Locked ' . uniqid(), 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->clerkId]]);
        $data     = $this->expenseData('40.00');

        $data['treasury_id'] = $treasury;

        $this->assertNotNull($this->saveExpense($data));
    }

    public function testSaveValue_AsksTheWithdrawScopeOfTheMainTreasuryWhenTheExpenseNamesNone(): void
    {
        $main     = (new TreasuryService())->ensureMain();
        $outsider = $this->employeeWithGrants([]);

        $this->restrictTreasury($main, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $this->assertNull($this->saveExpense($this->expenseData('40.00', '0', 'Cash', null, '2026-10-20 10:00:00', $outsider), NEW_ENTRY, $outsider));
        $this->assertRefused(AccountingError::TreasuryWithdrawDenied);
        $this->assertNotNull($this->saveExpense($this->expenseData('40.00')));
    }

    public function testSaveValue_RefusesAChangeOfAmountOfAnEmployeeWhoMayNoLongerWithdrawButAllowsOneThatMovesNoMoney(): void
    {
        $category = $this->category();
        $worker   = $this->employeeWithGrants([]);
        $treasury = $this->treasury();
        $data     = $this->expenseData('40.00', '0', 'Cash', $category, '2026-10-20 10:00:00', $worker);

        $data['treasury_id'] = $treasury;
        $expenseId           = $this->saveExpense($data, NEW_ENTRY, $worker);

        $this->restrictTreasury($treasury, TreasuryScopes::WITHDRAW, [$this->clerkId]);

        $data['amount'] = '90.00';

        $this->assertNull($this->saveExpense($data, $expenseId, $worker));
        $this->assertRefused(AccountingError::TreasuryWithdrawDenied);
        $this->assertSame('40.00', $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->amount);

        $data['amount']      = '40.00';
        $data['description'] = 'Only the words change';

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId, $worker));
    }

    public function testDeleteList_RefusesAnEmployeeWhoMayNotDepositBackIntoTheTreasury(): void
    {
        $worker   = $this->employeeWithGrants([]);
        $treasury = $this->treasury();
        $data     = $this->expenseData('40.00', '0', 'Cash', $this->category(), '2026-10-20 10:00:00', $worker);

        $data['treasury_id'] = $treasury;
        $expenseId           = $this->saveExpense($data, NEW_ENTRY, $worker);

        $this->restrictTreasury($treasury, TreasuryScopes::DEPOSIT, [$this->clerkId]);

        $this->assertFalse($this->expenses->delete_list([$expenseId], $worker));
        $this->assertRefused(AccountingError::TreasuryDepositDenied);
        $this->assertSame(1, $this->postedJournals($expenseId));
        $this->assertSame(0, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    public function testSaveValue_RollsTheExpenseBackWhenThePostingFailsAndLogsWhy(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'tax.input')->update(['account_id' => null]);

        $this->assertNull($this->saveExpense($this->expenseData('115.00', '15.00')));

        $failure = $this->failure();

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::RoutingAccountUnusable, $failure->error);
        $this->assertNothingWritten();
        $this->assertSame('routing_account_unusable', $this->accountingDb()->table('accounting_posting_failures')->get()->getRow()->error_code);
        $this->assertNotSame('', AccountingMessage::of($failure));
    }

    public function testSaveValue_RollsBackTheEditWhenTheNewJournalCannotBePosted(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'tax.input')->update(['account_id' => null]);

        $this->assertNull($this->saveExpense($this->expenseData('115.00', '15.00', 'Cash', $category), $expenseId));
        $this->assertSame('100.00', $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->amount);
        $this->assertSame([['posted', '1']], array_map(static fn (object $journal): array => [$journal->status, (string) $journal->revision], $this->journalsOfSource(self::SOURCE, $expenseId)));
    }

    public function testSaveValue_RefusesATaxLargerThanTheAmount(): void
    {
        $this->assertNull($this->saveExpense($this->expenseData('100.00', '120.00')));
        $this->assertRefused(PostingError::ExpenseTaxInvalid);
        $this->assertNothingWritten();
    }

    public function testSaveValue_RefusesAWayOfPaymentTheBooksDoNotKnow(): void
    {
        $this->assertNull($this->saveExpense($this->expenseData('100.00', '0', 'Barter')));
        $this->assertRefused(PostingError::PaymentTypeUnknown);
        $this->assertNothingWritten();
    }

    public function testSaveValue_RefusesADeactivatedTreasury(): void
    {
        $treasury = $this->treasury();

        (new TreasuryService())->deactivate($treasury);

        $data                = $this->expenseData('100.00');
        $data['treasury_id'] = $treasury;

        $this->assertNull($this->saveExpense($data));
        $this->assertRefused(AccountingError::TreasuryInactive);
        $this->assertNothingWritten();
    }

    public function testSaveValue_StillAllowsEditingAnExpenseWhoseTreasuryWasDeactivatedLater(): void
    {
        $treasury = $this->treasury();
        $category = $this->category();
        $data     = $this->expenseData('0.00', '0', 'Cash', $category);

        $data['treasury_id'] = $treasury;
        $expenseId           = $this->saveExpense($data);

        (new TreasuryService())->deactivate($treasury);

        $data['description'] = 'Edited after the treasury closed';

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));
    }

    public function testSaveValue_SavesAnExpenseDatedBeforeTheStartOfTheBooksWithoutAJournal(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');

        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', null, '2026-09-15 10:00:00'));
        $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertNotNull($expenseId);
        $this->assertNull($row->branch_id);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame([$expenseId], array_map(static fn (object $expense): int => (int) $expense->expense_id, (new ExpensePosting())->unposted()));
    }

    public function testSaveValue_NeverPostsAnExpenseSavedBeforeTheBooksFollowedItNorStampsIt(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $category = $this->category();
        $legacy   = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));

        $this->assertSame(0, $this->countRows('accounting_journals'));

        $this->setConfigValue('accounting_enabled', '1');

        $data                = $this->expenseData('130.00', '0', 'Cash', $category);
        $data['treasury_id'] = $this->treasury();

        $this->assertSame($legacy, $this->saveExpense($data, $legacy));

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $legacy)->get()->getRow();

        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(['130.00', null, null], [$row->amount, $row->branch_id, $row->treasury_id]);

        $this->assertTrue($this->expenses->delete_list([$legacy], $this->clerkId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testUnposted_ListsTheExpensesThatAreNotInTheBooks(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $category = $this->category();
        $legacy   = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));

        $this->setConfigValue('accounting_enabled', '1');

        $posted = $this->saveExpense($this->expenseData('50.00', '0', 'Cash', $category));
        $zero   = $this->saveExpense($this->expenseData('0.00', '0', 'Cash', $category));
        $listed = array_map(static fn (object $expense): int => (int) $expense->expense_id, (new ExpensePosting())->unposted());

        $this->assertSame([$legacy], $listed);
        $this->assertSame(1, (new ExpensePosting())->unpostedCount());
        $this->assertNotContains($posted, $listed);
        $this->assertNotContains($zero, $listed);
    }

    public function testUnposted_ShowsAnExpenseWithoutBranchOnlyToThoseWhoSeeTheDefaultBranch(): void
    {
        $second = $this->createBranch('B2');

        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveExpense($this->expenseData('100.00'));
        $this->setConfigValue('accounting_enabled', '1');

        $this->assertSame(1, (new ExpensePosting())->unpostedCount([1]));
        $this->assertSame(0, (new ExpensePosting())->unpostedCount([$second]));
        $this->assertSame(0, (new ExpensePosting())->unpostedCount([]));
        $this->assertSame($legacy, (int) (new ExpensePosting())->unposted([1])[0]->expense_id);
    }

    public function testSaveValue_ChangesNothingInTheBooksWhenAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $data                = $this->expenseData('230.00', '30.00');
        $data['treasury_id'] = $this->treasury();
        $data['branch_id']   = 1;

        $expenseId = $this->saveExpense($data);
        $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertNotNull($expenseId);
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame([null, null], [$row->branch_id, $row->treasury_id]);
        $this->assertTrue($this->expenses->delete_list([$expenseId]));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'expense')->countAllResults());
    }

    public function testSync_IsIdempotentAndReportsWhatItDid(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('100.00'));
        $posting   = new ExpensePosting();

        $this->assertSame(JournalChange::Unchanged, $posting->sync($expenseId, $this->clerkId));
        $this->assertSame(JournalChange::Unchanged, $posting->sync($expenseId, $this->clerkId));
        $this->assertCount(1, $this->journalsOfSource(self::SOURCE, $expenseId));
    }

    public function testSync_DoesNotGiveAnExpenseTheBooksNeverFollowedAJournal(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveExpense($this->expenseData('100.00'));
        $this->setConfigValue('accounting_enabled', '1');

        $this->assertSame(JournalChange::None, (new ExpensePosting())->sync($legacy, $this->clerkId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testCancel_DoesNothingForAnExpenseThatIsNotInTheBooks(): void
    {
        $this->assertSame(JournalChange::None, (new ExpensePosting())->cancel(999999, $this->clerkId));
    }

    public function testSaveValue_KeepsTheTrialBalanceBalancedAcrossEveryChange(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('230.00', '30.00', 'Cash', $category));

        $this->saveExpense($this->expenseData('115.00', '15.00', 'Due', $category), $expenseId);
        $this->saveExpense($this->expenseData('115.00', '15.00', 'Cash', $category, '2026-10-22 10:00:00'), $expenseId);
        $this->expenses->delete_list([$expenseId], $this->clerkId);

        $row = $this->accountingDb()->table('accounting_journal_lines l')
            ->select('SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)
            ->join('accounting_journals j', 'j.journal_id = l.journal_id')
            ->get()
            ->getRow();

        $this->assertSame(bcadd((string) $row->debit, '0', 4), bcadd((string) $row->credit, '0', 4));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('expenses_default')));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('tax_input')));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('other_payables')));
    }

    public function testSaveValue_RefusesMarkingDeletedAnExpenseOfABranchTheEmployeeDoesNotWorkIn(): void
    {
        $second   = $this->createBranch('B2');
        $category = $this->category();
        $data     = $this->expenseData('40.00', '0', 'Cash', $category);

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $expenseId = $this->saveExpense($data);
        $limited   = $this->employeeWithGrants([], [1]);

        $data['deleted'] = 1;

        $this->assertNull($this->saveExpense($data, $expenseId, $limited));
        $this->assertRefused(BranchError::NotAllowed);
        $this->assertSame(1, $this->postedJournals($expenseId));
        $this->assertSame('0', (string) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->deleted);
    }

    public function testSaveValue_RefusesMovingAnExpenseOfABranchTheEmployeeDoesNotWorkInBeforeTheStartOfTheBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');

        $second   = $this->createBranch('B2');
        $category = $this->category();
        $data     = $this->expenseData('40.00', '0', 'Cash', $category);

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $expenseId = $this->saveExpense($data);
        $limited   = $this->employeeWithGrants([], [1]);

        $data['date'] = '2026-09-15 10:00:00';

        $this->assertNull($this->saveExpense($data, $expenseId, $limited));
        $this->assertRefused(BranchError::NotAllowed);
        $this->assertSame(1, $this->postedJournals($expenseId));
    }

    public function testSaveValue_ReversesTheJournalWhenTheExpenseMovesBeforeTheStartOfTheBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');

        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, '2026-10-20 10:00:00'));

        $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, '2026-09-15 10:00:00'), $expenseId);

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame(0, $this->postedJournals($expenseId));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('expenses_default')));
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertSame([$expenseId], array_map(static fn (object $expense): int => (int) $expense->expense_id, (new ExpensePosting())->unposted()));
    }

    public function testSaveValue_AsksNoCategoryAccountOfAnExpenseThatMovesBeforeTheStartOfTheBooks(): void
    {
        $this->setConfigValue('accounting_start_date', '2026-10-01');

        $withAccount = $this->category('Rent', $this->customAccount('5', '5970', 'Rent'));
        $without     = $this->category('Misc');
        $expenseId   = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $withAccount, '2026-10-20 10:00:00'));

        $this->setConfigValue('accounting_expense_account_required', '1');

        $this->assertSame($expenseId, $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $without, '2026-09-15 10:00:00'), $expenseId));
        $this->assertSame(0, $this->postedJournals($expenseId));
    }

    public function testSaveValue_AsksNoCategoryAccountOfAnExpenseThatIsMarkedDeleted(): void
    {
        $withAccount = $this->category('Rent', $this->customAccount('5', '5971', 'Rent'));
        $without     = $this->category('Misc');
        $expenseId   = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $withAccount));

        $this->setConfigValue('accounting_expense_account_required', '1');

        $data            = $this->expenseData('100.00', '0', 'Cash', $without);
        $data['deleted'] = 1;

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));
        $this->assertSame(0, $this->postedJournals($expenseId));
    }

    public function testSaveValue_KeepsTheBranchOfAnExpenseThatIsPaidInCashAgainAfterBeingOwed(): void
    {
        $second   = $this->createBranch('B2');
        $category = $this->category();
        $data     = $this->expenseData('40.00', '0', 'Cash', $category);

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $expenseId = $this->saveExpense($data);

        unset($data['treasury_id']);

        $data['payment_type'] = 'Due';
        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));

        $data['payment_type'] = 'Cash';
        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([$second, null], [(int) $row->branch_id, $row->treasury_id]);
        $this->assertSame([$this->accountId('expenses_default') . '=40.00/0.00', $this->accountId('cash') . '=0.00/40.00'], $this->postedLines(self::SOURCE, $expenseId));
        $this->assertSame($second, (int) $this->journalsOfSource(self::SOURCE, $expenseId)[2]->branch_id);
    }

    public function testSaveValue_TakesTheTreasuryTheEmployeePaysFromWhenTheFormAsksForTheDefault(): void
    {
        $second   = $this->createBranch('B2');
        $category = $this->category();
        $data     = $this->expenseData('40.00', '0', 'Cash', $category);

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $expenseId = $this->saveExpense($data);

        $data['treasury_id'] = 0;

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([$second, null], [(int) $row->branch_id, $row->treasury_id]);
        $this->assertSame([$this->accountId('expenses_default') . '=40.00/0.00', $this->accountId('cash') . '=0.00/40.00'], $this->postedLines(self::SOURCE, $expenseId));
    }

    public function testSaveValue_JudgesAnExpenseByItsOwnEmployeeWhenNoActorIsGiven(): void
    {
        $second  = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([], [1]);
        $data    = $this->expenseData('40.00', '0', 'Cash', $this->category(), '2026-10-20 10:00:00', $limited);

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $this->assertFalse($this->expenses->save_value($data, NEW_ENTRY));
        $this->assertRefused(BranchError::NotAllowed);
        $this->assertNothingWritten();

        $own = $this->expenseData('40.00', '0', 'Cash', $this->category(), '2026-10-20 10:00:00', $limited);

        $this->assertTrue($this->expenses->save_value($own, NEW_ENTRY));
        $this->assertSame($limited, (int) $this->journalsOfSource(self::SOURCE, (int) $own['expense_id'])[0]->created_by);
    }

    public function testSaveValue_ReadsTheStoredExpenseUnderALockBeforeChangingIt(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('100.00'));
        $queries   = $this->queriesOf(fn () => $this->saveExpense($this->expenseData('120.00'), $expenseId));
        $reads     = array_values(array_filter($queries, fn (string $sql): bool => $this->readsExpenseRow($sql)));

        $this->assertCount(1, $reads);
        $this->assertStringEndsWith('FOR UPDATE', trim($reads[0]));
        $this->assertLessThan($this->firstIndexOf($queries, static fn (string $sql): bool => str_starts_with($sql, 'UPDATE') && str_contains($sql, 'expenses')), $this->firstIndexOf($queries, fn (string $sql): bool => $this->readsExpenseRow($sql)));
    }

    public function testDeleteList_LocksTheExpensesInTheOrderOfTheirNumbers(): void
    {
        $first  = $this->saveExpense($this->expenseData('10.00'));
        $second = $this->saveExpense($this->expenseData('20.00'));
        $third  = $this->saveExpense($this->expenseData('30.00'));

        $queries = $this->queriesOf(fn () => $this->expenses->delete_list([$third, $first, $second, $first], $this->clerkId));
        $locked  = [];

        foreach ($queries as $sql) {
            if ($this->readsExpenseRow($sql) && preg_match('/expense_id = (\d+)/', $sql, $found) === 1) {
                $locked[] = (int) $found[1];
            }
        }

        $this->assertSame([$first, $second, $third], $locked);
    }

    public function testSaveValue_NeverStoresWhatTheFormClaimsAboutAnExpenseTheBooksDoNotFollow(): void
    {
        $category = $this->category();

        $this->setConfigValue('accounting_enabled', '0');

        $legacy = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));

        $this->setConfigValue('accounting_enabled', '1');

        $claims = ['branch_id' => 1, 'treasury_id' => $this->treasury('Claimed drawer')];

        $this->assertSame($legacy, $this->saveExpense($this->expenseData('120.00', '0', 'Cash', $category) + $claims, $legacy));

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $legacy)->get()->getRow();

        $this->assertSame([null, null], [$row->branch_id, $row->treasury_id]);

        $this->setConfigValue('accounting_start_date', '2026-10-21');

        $early = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, '2026-10-20 10:00:00') + $claims);
        $row   = $this->accountingDb()->table('expenses')->where('expense_id', $early)->get()->getRow();

        $this->assertSame([null, null], [$row->branch_id, $row->treasury_id]);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testFollows_IsFalseWhileAccountingIsOffWhateverTheDate(): void
    {
        $posting = new ExpensePosting();

        $this->assertTrue($posting->follows('2026-10-20'));

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertFalse($posting->follows('2026-10-20'));
        $this->assertFalse($posting->follows('2030-01-01'));
    }

    public function testSaveValue_StampsNothingOnANewExpenseThatIsAlreadyMarkedDeleted(): void
    {
        $data            = $this->expenseData('100.00');
        $data['deleted'] = 1;

        $expenseId = $this->saveExpense($data);
        $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([null, null], [$row->branch_id, $row->treasury_id]);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_AuditsTheCancellationOfAnExpenseMarkedDeleted(): void
    {
        $category  = $this->category();
        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));
        $data      = $this->expenseData('100.00', '0', 'Cash', $category);

        $data['deleted'] = 1;
        $this->saveExpense($data, $expenseId);

        $actions = array_column($this->accountingDb()->table('accounting_audit_log')->select('action')->where(['entity_type' => 'expense', 'entity_id' => $expenseId])->orderBy('audit_id')->get()->getResultArray(), 'action');

        $this->assertSame(['posted', 'cancelled'], $actions);
    }

    public function testSaveValue_RefusesATreasuryThatDoesNotExist(): void
    {
        $data                = $this->expenseData('100.00');
        $data['treasury_id'] = 987654;

        $this->assertNull($this->saveExpense($data));
        $this->assertRefused(AccountingError::TreasuryNotFound);
        $this->assertNothingWritten();
    }

    public function testSaveValue_RefusesMovingAnExpenseToACategoryWithoutAnAccountWhenTheSettingIsOn(): void
    {
        $account    = $this->customAccount('5', '5992', 'Marketing', false);
        $withOne    = $this->category('Ads', $account);
        $withoutOne = $this->category('Rent');
        $expenseId  = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $withOne));

        $this->setConfigValue('accounting_expense_account_required', '1');

        $this->assertNull($this->saveExpense($this->expenseData('100.00', '0', 'Cash', $withoutOne), $expenseId));
        $this->assertRefused(AccountingError::ExpenseCategoryAccountRequired);
        $this->assertSame($withOne, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->expense_category_id);
    }

    public function testSaveValue_PaysMoneyThatCameBackIntoTheDefaultTreasuryOnlyWhenTheEmployeeMayDepositIntoIt(): void
    {
        $worker   = $this->employeeWithGrants([]);
        $treasury = $this->treasury();

        (new TreasuryService())->setDefaultFor($worker, $treasury);
        $this->restrictTreasury($treasury, TreasuryScopes::DEPOSIT, [$this->clerkId]);

        $paid = $this->saveExpense($this->expenseData('50.00', '0', 'Cash', null, '2026-10-20 10:00:00', $worker), NEW_ENTRY, $worker);
        $back = $this->saveExpense($this->expenseData('-50.00', '0', 'Cash', null, '2026-10-20 10:00:00', $worker), NEW_ENTRY, $worker);

        $this->assertNotNull($back);
        $this->assertSame([$treasury, null], [
            (int) $this->accountingDb()->table('expenses')->where('expense_id', $paid)->get()->getRow()->treasury_id,
            $this->accountingDb()->table('expenses')->where('expense_id', $back)->get()->getRow()->treasury_id,
        ]);
    }

    public function testSaveValue_UsesNoTreasuryForAnExpenseThatIsDueEvenWhenOneIsNamed(): void
    {
        $second = $this->createBranch('B2');
        $data   = $this->expenseData('100.00', '0', 'Due');

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $expenseId = $this->saveExpense($data);
        $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([null, 1], [$row->treasury_id, (int) $row->branch_id]);
        $this->assertSame($this->accountId('other_payables') . '=0.00/100.00', $this->postedLines(self::SOURCE, $expenseId)[1]);
    }

    public function testUnposted_LeavesOutTheExpensesThatWereDeleted(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveExpense($this->expenseData('100.00'));
        $this->setConfigValue('accounting_enabled', '1');

        $this->assertSame(1, (new ExpensePosting())->unpostedCount());
        $this->assertTrue($this->expenses->delete_list([$legacy], $this->clerkId));
        $this->assertSame(0, (new ExpensePosting())->unpostedCount());
    }

    public function testSaveValue_StampsTheTreasuryTheEmployeeMayOnlyDepositIntoForMoneyThatCameBack(): void
    {
        $treasury = $this->treasury('Deposit only drawer');
        $other    = $this->employeeWithGrants([]);

        (new TreasuryService())->setDefaultFor($this->clerkId, $treasury);
        $this->restrictTreasury($treasury, TreasuryScopes::WITHDRAW, [$other]);

        $refund   = $this->saveExpense($this->expenseData('-30.00'));
        $spending = $this->saveExpense($this->expenseData('30.00'));

        $this->assertSame($treasury, (int) $this->accountingDb()->table('expenses')->where('expense_id', $refund)->get()->getRow()->treasury_id);
        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $spending)->get()->getRow()->treasury_id);
        $this->assertSame('30.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }

    public function testSaveValue_StampsNoTreasuryOnAnExpenseThatIsDue(): void
    {
        $treasury = $this->treasury();

        (new TreasuryService())->setDefaultFor($this->clerkId, $treasury);

        $expenseId = $this->saveExpense($this->expenseData('100.00', '0', 'Due'));

        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }
}
