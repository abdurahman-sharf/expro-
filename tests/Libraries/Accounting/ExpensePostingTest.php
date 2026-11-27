<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ExpensePosting;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\InterBranchPosition;
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
use Tests\Support\PreviewJournalFixtureTrait;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ConcurrentDbRaceTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ParityCaseTrait;
use App\Libraries\Accounting\OperationsHold;

/**
 * What an expense puts in the books, through the real expense model: one journal inside the save
 * transaction, the tax split off to the account of tax paid, reversed and posted again as the next
 * revision when the expense changes, reversed when it is deleted, and never touched when the
 * expense was saved before the books followed it. Every refusal leaves nothing of the change behind.
 */
class ExpensePostingTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use PreviewJournalFixtureTrait;
    use AccountingFixtureTrait;
    use ConcurrentDbRaceTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ParityCaseTrait;

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

    public function testSaveValue_WritesDownWhereTheRulesOfTheEngineWouldPostOtherwiseWhileTheExpenseIsInShadow(): void
    {
        $db    = $this->accountingDb();
        $setId = (int) $db->table('accounting_posting_rule_sets')->where('operation', 'expense')->get()->getRow()->rule_set_id;
        $db->table('accounting_posting_rules')->where(['rule_set_id' => $setId, 'line_no' => 3])->update(['amount' => 'tax * 2']);

        try {
            $expenseId = $this->saveExpense($this->expenseData('230.00', '30.00'));
        } finally {
            $db->table('accounting_posting_rules')->where(['rule_set_id' => $setId, 'line_no' => 3])->update(['amount' => 'tax']);
        }

        $diffs = $db->table('accounting_posting_shadow_diffs')->where('operation', 'expense')->get()->getResult();
        $db->table('accounting_posting_shadow_diffs')->where('operation', 'expense')->delete();

        $this->assertSame($this->accountId('tax_input') . '=30.00/0.00', $this->postedLines(self::SOURCE, $expenseId)[1], 'The journal of the code is what was posted');
        $this->assertCount(1, $diffs);
        $this->assertSame([$this->accountId('tax_input') . '|debit|60.0000|1|'], json_decode((string) $diffs[0]->differences, true)['only_engine']);
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

        $claims = ['branch_id' => 1, 'treasury_id' => $this->treasury('Claimed drawer'), 'treasury_branch_id' => 1];

        $this->assertSame($legacy, $this->saveExpense($this->expenseData('120.00', '0', 'Cash', $category) + $claims, $legacy));

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $legacy)->get()->getRow();

        $this->assertSame([null, null, null], [$row->branch_id, $row->treasury_id, $row->treasury_branch_id]);

        $this->setConfigValue('accounting_start_date', '2026-10-21');

        $early = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category, '2026-10-20 10:00:00') + $claims);
        $row   = $this->accountingDb()->table('expenses')->where('expense_id', $early)->get()->getRow();

        $this->assertSame([null, null, null], [$row->branch_id, $row->treasury_id, $row->treasury_branch_id]);
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

    public function testSaveValue_RefusesChangingAnExpenseOfABranchTheEmployeeDoesNotWorkInEvenWhenItNamesATreasuryOfItsOwnBranch(): void
    {
        $second   = $this->createBranch('B2');
        $category = $this->category();
        $remote   = $this->treasury('Remote drawer', $second);
        $home     = $this->treasury('Home drawer', 1);
        $data     = $this->expenseData('40.00', '0', 'Cash', $category);

        $data['treasury_id'] = $remote;

        $expenseId = $this->saveExpense($data);
        $limited   = $this->employeeWithGrants([], [1]);

        $data['amount']      = '900.00';
        $data['treasury_id'] = $home;

        $this->assertNull($this->saveExpense($data, $expenseId, $limited));
        $this->assertRefused(BranchError::NotAllowed);

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([$second, $remote, '40.00'], [(int) $row->branch_id, (int) $row->treasury_id, $row->amount]);
        $this->assertSame(1, count($this->journalsOfSource(self::SOURCE, $expenseId)));
        $this->assertSame(['-40.00', '0.00'], [$this->assertTreasuryTiesToItsAccount($remote), $this->assertTreasuryTiesToItsAccount($home)]);
    }

    public function testSaveValue_KeepsAnExpenseThatHasNoTreasuryWithoutOneWhenTheFormAsksForTheDefault(): void
    {
        $drawer = $this->treasury('Editor drawer');
        $editor = $this->employeeWithGrants([]);

        (new TreasuryService())->setDefaultFor($editor, $drawer);

        $data      = $this->expenseData('100.00', '0', 'Cash', $this->category());
        $expenseId = $this->saveExpense($data);

        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);

        $data['treasury_id'] = 0;
        $data['description'] = 'Only the description changed';

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId, $editor));
        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
        $this->assertSame(1, count($this->journalsOfSource(self::SOURCE, $expenseId)));
        $this->assertSame([$this->accountId('expenses_default') . '=100.00/0.00', $this->accountId('cash') . '=0.00/100.00'], $this->postedLines(self::SOURCE, $expenseId));
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($drawer));
    }

    public function testSaveValue_MovesAnExpenseThatHasNoTreasuryToTheOneTheFormNames(): void
    {
        $drawer    = $this->treasury('Named drawer');
        $data      = $this->expenseData('100.00', '0', 'Cash', $this->category());
        $expenseId = $this->saveExpense($data);

        $data['treasury_id'] = $drawer;

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));
        $this->assertSame($drawer, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
        $this->assertSame('-100.00', $this->assertTreasuryTiesToItsAccount($drawer));
    }

    public function testSaveValue_PaysFromTheTreasuryOfTheEmployeeWhenAnExpenseThatWasOwedIsPaidAndTheFormAsksForTheDefault(): void
    {
        $drawer = $this->treasury('Payer drawer');

        (new TreasuryService())->setDefaultFor($this->clerkId, $drawer);

        $data      = $this->expenseData('100.00', '0', 'Due', $this->category());
        $expenseId = $this->saveExpense($data);

        $data['payment_type'] = 'Cash';
        $data['treasury_id']  = 0;

        $this->assertSame($expenseId, $this->saveExpense($data, $expenseId));
        $this->assertSame($drawer, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
        $this->assertSame('-100.00', $this->assertTreasuryTiesToItsAccount($drawer));
    }

    public function testDeleteList_RefusesAnEmployeeOutsideTheBranchOfAnExpenseThatHasNoJournal(): void
    {
        $second   = $this->createBranch('B2');
        $remote   = $this->employeeWithGrants([], [$second]);
        $limited  = $this->employeeWithGrants([], [1]);
        $data     = $this->expenseData('0', '0', 'Cash', $this->category());

        $data['treasury_id'] = $this->treasury('Remote drawer', $second);

        $expenseId = $this->saveExpense($data);

        $this->assertSame([], $this->journalsOfSource(self::SOURCE, $expenseId));
        $this->assertFalse($this->expenses->delete_list([$expenseId], $limited));
        $this->assertSame(BranchError::NotAllowed, $this->failure()->error);
        $this->assertSame(0, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
        $this->assertTrue($this->expenses->delete_list([$expenseId], $remote));
        $this->assertSame(1, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    public function testDeleteList_StillLetsAnyEmployeeDeleteAnExpenseTheBooksNeverFollowed(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveExpense($this->expenseData('100.00'));
        $this->setConfigValue('accounting_enabled', '1');

        $limited = $this->employeeWithGrants([], [$this->createBranch('B2')]);

        $this->assertTrue($this->expenses->delete_list([$legacy], $limited));
        $this->assertSame(1, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    // ---------------------------------------------------------------------------------------
    // An expense of one branch paid out of a treasury of another
    // ---------------------------------------------------------------------------------------

    /**
     * A second branch with a treasury of its own.
     *
     * @return array{branch: int, treasury: int, account: int}
     */
    private function treasuryOfAnotherBranch(): array
    {
        $branch   = $this->createBranch('B2');
        $treasury = $this->treasury('Branch drawer', $branch);

        return ['branch' => $branch, 'treasury' => $treasury, 'account' => (int) (new TreasuryService())->accountOf($treasury)];
    }

    /**
     * An expense of the first branch, paid from the treasury of another branch.
     *
     * @return array<string, mixed>
     */
    private function acrossBranches(int $treasury, string $amount = '100.00', string $tax = '0'): array
    {
        return $this->expenseData($amount, $tax) + ['treasury_id' => $treasury, 'expense_branch_id' => 1];
    }

    /**
     * The journals that stand for an expense, by source, with their branch and lines.
     *
     * @return array<string, array{branch: int, lines: list<string>, revision: int}>
     */
    private function expenseJournals(int $expenseId, string $status = 'posted'): array
    {
        $found = [];

        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['expense', 'expense_branch'])->where(['source_id' => $expenseId, 'status' => $status])->orderBy('journal_id', 'ASC')->get()->getResult() as $journal) {
            $found[$journal->source_type] = ['branch' => (int) $journal->branch_id, 'lines' => $this->linesOfJournal((int) $journal->journal_id), 'revision' => (int) $journal->revision];
        }

        return $found;
    }

    private function assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset(): void
    {
        foreach ($this->accountingDb()->table('branches')->get()->getResult() as $branch) {
            $sums = $this->accountingDb()->table('accounting_journal_lines l')->select('SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)->join('accounting_journals j', 'j.journal_id = l.journal_id')->where('j.branch_id', $branch->branch_id)->get()->getRow();

            $this->assertSame(bcadd((string) $sums->debit, '0', 4), bcadd((string) $sums->credit, '0', 4), 'The trial balance of branch ' . $branch->code . ' balances');
        }

        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }

    public function testSaveValue_PaysAnExpenseOfOneBranchOutOfATreasuryOfAnotherAsAJournalInEachBranch(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury, '230.00', '30.00'));
        $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([1, $treasury, $branch], [(int) $row->branch_id, (int) $row->treasury_id, (int) $row->treasury_branch_id]);
        $this->assertSame([
            'expense'        => ['branch' => 1, 'lines' => [$this->accountId('expenses_default') . '=200.00/0.00', $this->accountId('tax_input') . '=30.00/0.00', $this->accountId('branches_payable') . '=0.00/230.00'], 'revision' => 1],
            'expense_branch' => ['branch' => $branch, 'lines' => [$this->accountId('branches_receivable') . '=230.00/0.00', $account . '=0.00/230.00'], 'revision' => 1],
        ], $this->expenseJournals($expenseId));
        $this->assertSame('-230.00', $this->assertTreasuryTiesToItsAccount($treasury));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testSaveValue_PostsMoneyReceivedBackOnAnExpenseAcrossBranchesTheOtherWay(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury, '-40.00'));

        $this->assertSame([
            'expense'        => ['branch' => 1, 'lines' => [$this->accountId('expenses_default') . '=0.00/40.00', $this->accountId('branches_receivable') . '=40.00/0.00'], 'revision' => 1],
            'expense_branch' => ['branch' => $branch, 'lines' => [$account . '=40.00/0.00', $this->accountId('branches_payable') . '=0.00/40.00'], 'revision' => 1],
        ], $this->expenseJournals($expenseId));
        $this->assertSame('40.00', $this->assertTreasuryTiesToItsAccount($treasury));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testSaveValue_ChargesTheExpenseToTheBranchOfItsTreasuryWhenNoOtherBranchIsAsked(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([[], ['expense_branch_id' => 0], ['expense_branch_id' => $branch]] as $asked) {
            $expenseId = $this->saveExpense($this->expenseData('100.00') + ['treasury_id' => $treasury] + $asked);
            $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

            $this->assertSame($branch, (int) $row->branch_id);
            $this->assertNull($row->treasury_branch_id);
            $this->assertSame(['expense'], array_keys($this->expenseJournals($expenseId)));
        }

        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
    }

    public function testSaveValue_RejectsATreasuryOfAnotherBranchToAnEmployeeWhoWorksInOnlyOneOfTheTwo(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([[1], [$branch]] as $works) {
            $limited = $this->employeeWithGrants([], $works);

            $this->assertNull($this->saveExpense($this->acrossBranches($treasury), NEW_ENTRY, $limited));

            $this->assertSame(AccountingError::TreasuryNotFound, $this->failure()->error);
            $this->assertNothingWritten();
        }
    }

    public function testSaveValue_AsksTheWithdrawScopeOfTheTreasuryOfTheOtherBranch(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->restrictTreasury($treasury, TreasuryScopes::WITHDRAW, [$this->employeeWithGrants([])]);

        $this->assertNull($this->saveExpense($this->acrossBranches($treasury)));
        $this->assertRefused(AccountingError::TreasuryWithdrawDenied);
        $this->assertNothingWritten();
    }

    public function testSaveValue_RejectsAnExpenseAcrossBranchesPaidFromATreasuryThatIsDeactivated(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        (new TreasuryService())->deactivate($treasury);

        $this->assertNull($this->saveExpense($this->acrossBranches($treasury)));
        $this->assertRefused(AccountingError::TreasuryInactive);
        $this->assertNothingWritten();
    }

    public function testSaveValue_LeavesNothingOfTheExpenseWhenThePeriodOfEitherBranchIsClosed(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        foreach ([$branch, 1] as $closed) {
            $this->closeThrough('2026-10-31', $closed);

            $this->assertNull($this->saveExpense($this->acrossBranches($treasury)));
            $this->assertRefused(PostingError::PeriodClosed);
            $this->assertNothingWritten();
            $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));

            $this->accountingDb()->table('accounting_closed_periods')->truncate();
        }
    }

    public function testSaveValue_LeavesNothingOfTheExpenseWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.payable')->update(['account_id' => null]);

        $this->assertNull($this->saveExpense($this->acrossBranches($treasury)));
        $this->assertRefused(PostingError::InterBranchAccountsMissing);
        $this->assertNothingWritten();
    }

    public function testSaveValue_ReversesBothJournalsAndPostsThePairAgainWhenTheExpenseChanges(): void
    {
        ['branch' => $branch, 'treasury' => $treasury, 'account' => $account] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));

        $this->assertSame($expenseId, $this->saveExpense($this->acrossBranches($treasury, '60.00'), $expenseId));
        $this->assertSame([
            'expense'        => ['branch' => 1, 'lines' => [$this->accountId('expenses_default') . '=60.00/0.00', $this->accountId('branches_payable') . '=0.00/60.00'], 'revision' => 2],
            'expense_branch' => ['branch' => $branch, 'lines' => [$this->accountId('branches_receivable') . '=60.00/0.00', $account . '=0.00/60.00'], 'revision' => 2],
        ], $this->expenseJournals($expenseId));
        $this->assertSame(['expense', 'expense_branch'], array_keys($this->expenseJournals($expenseId, 'reversed')));
        $this->assertSame('-60.00', $this->assertTreasuryTiesToItsAccount($treasury));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testSaveValue_LeavesThePairAloneWhenNothingAboutTheExpenseChanges(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));
        $before    = $this->countRows('accounting_journals');

        $this->assertSame($expenseId, $this->saveExpense($this->acrossBranches($treasury), $expenseId));

        $unnamed = $this->expenseData('100.00');

        $this->assertSame($expenseId, $this->saveExpense($unnamed, $expenseId));
        $this->assertSame($before, $this->countRows('accounting_journals'), 'The form that names neither the treasury nor the branch keeps both');
        $this->assertSame(1, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->branch_id);
    }

    public function testSaveValue_IgnoresTheBranchOfTheTreasuryTheFormClaims(): void
    {
        ['branch' => $branch] = $this->treasuryOfAnotherBranch();

        $own       = $this->treasury('Own drawer');
        $expenseId = $this->saveExpense($this->expenseData('100.00') + ['treasury_id' => $own, 'expense_branch_id' => 1, 'treasury_branch_id' => $branch]);
        $row       = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([1, $own, null], [(int) $row->branch_id, (int) $row->treasury_id, $row->treasury_branch_id]);
        $this->assertSame(['expense'], array_keys($this->expenseJournals($expenseId)));
        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));

        $this->assertSame($expenseId, $this->saveExpense($this->expenseData('120.00') + ['treasury_id' => $own, 'treasury_branch_id' => $branch], $expenseId));
        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_branch_id);
        $this->assertSame(['expense'], array_keys($this->expenseJournals($expenseId)));
    }

    public function testSaveValue_KeepsTheBranchOfTheExpenseWhenTheFormNamesTheSameTreasuryAndNoBranch(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));
        $before    = $this->countRows('accounting_journals');

        $this->assertSame($expenseId, $this->saveExpense($this->expenseData('100.00') + ['treasury_id' => $treasury], $expenseId));

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([1, $branch], [(int) $row->branch_id, (int) $row->treasury_branch_id], 'The expense stays charged to its own branch');
        $this->assertSame($before, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_ChargesAnExpenseThatIsPaidAcrossBranchesToTheBranchOfItsTreasuryWhenTheFormAsksForThat(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));

        $this->assertSame($expenseId, $this->saveExpense($this->expenseData('100.00') + ['treasury_id' => $treasury, 'expense_branch_id' => 0], $expenseId));

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([$branch, $treasury], [(int) $row->branch_id, (int) $row->treasury_id]);
        $this->assertNull($row->treasury_branch_id);
        $this->assertSame(['expense' => ['branch' => $branch, 'lines' => [$this->accountId('expenses_default') . '=100.00/0.00', (new TreasuryService())->accountOf($treasury) . '=0.00/100.00'], 'revision' => 2]], $this->expenseJournals($expenseId));
        $this->assertSame(['expense', 'expense_branch'], array_keys($this->expenseJournals($expenseId, 'reversed')));
        $this->assertSame('-100.00', $this->assertTreasuryTiesToItsAccount($treasury));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testSaveValue_MovesAnExpenseFromATreasuryOfAnotherBranchToOneOfItsOwnAndBack(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $own       = $this->treasury('Own drawer');
        $expenseId = $this->saveExpense($this->acrossBranches($treasury));

        $this->assertSame($expenseId, $this->saveExpense($this->expenseData('100.00') + ['treasury_id' => $own, 'expense_branch_id' => 1], $expenseId));
        $this->assertSame(['expense'], array_keys($this->expenseJournals($expenseId)));
        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_branch_id);
        $this->assertSame(['0.00', '-100.00'], [$this->assertTreasuryTiesToItsAccount($treasury), $this->assertTreasuryTiesToItsAccount($own)]);

        $this->assertSame($expenseId, $this->saveExpense($this->acrossBranches($treasury), $expenseId));
        $this->assertSame(['expense', 'expense_branch'], array_keys($this->expenseJournals($expenseId)));
        $this->assertSame($branch, (int) $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_branch_id);
        $this->assertSame(['-100.00', '0.00'], [$this->assertTreasuryTiesToItsAccount($treasury), $this->assertTreasuryTiesToItsAccount($own)]);
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testSaveValue_RejectsAnEmployeeWhoWorksInOnlyOneOfTheBranchesToChangeAnExpenseAcrossThemAndChangesNothing(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));
        $limited   = $this->employeeWithGrants([], [1]);
        $before    = $this->countRows('accounting_journals');

        $this->assertNull($this->saveExpense($this->acrossBranches($treasury, '60.00'), $expenseId, $limited));
        $this->assertRefused(AccountingError::InterBranchBranchesDenied);
        $this->assertSame($before, $this->countRows('accounting_journals'));
        $this->assertSame('100.00', $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->amount);
    }

    public function testSaveValue_RejectsAnExpenseChargedToABranchTheEmployeeDoesNotWorkIn(): void
    {
        ['branch' => $branch] = $this->treasuryOfAnotherBranch();

        $limited = $this->employeeWithGrants([], [1]);
        $data    = $this->expenseData('40.00', '0', 'Cash', $this->category(), '2026-10-20 10:00:00', $limited) + ['treasury_id' => $this->treasury('Own drawer'), 'expense_branch_id' => $branch];

        $this->assertNull($this->saveExpense($data, NEW_ENTRY, $limited));
        $this->assertRefused(AccountingError::TreasuryNotFound);
        $this->assertNothingWritten();
    }

    public function testDeleteList_TakesBackBothJournalsOfAnExpenseAcrossBranches(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));

        $this->assertTrue($this->expenses->delete_list([$expenseId], $this->clerkId));
        $this->assertSame([], $this->expenseJournals($expenseId));
        $this->assertSame(['expense', 'expense_branch'], array_keys($this->expenseJournals($expenseId, 'reversed')));
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($treasury));
        $this->assertEveryBranchBalancedAndTheAccountsBetweenBranchesOffset();
    }

    public function testDeleteList_RejectsAnEmployeeWhoMayNotDepositIntoTheTreasuryOfTheOtherBranchAndReversesNothing(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));

        $this->restrictTreasury($treasury, TreasuryScopes::DEPOSIT, [$this->employeeWithGrants([])]);

        $this->assertFalse($this->expenses->delete_list([$expenseId], $this->clerkId));
        $this->assertSame(['expense', 'expense_branch'], array_keys($this->expenseJournals($expenseId)));
        $this->assertSame('-100.00', $this->assertTreasuryTiesToItsAccount($treasury));
    }

    public function testJournalsOf_ListsTheJournalOfTheTreasuryBranchWithTheJournalOfTheExpense(): void
    {
        ['treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $expenseId = $this->saveExpense($this->acrossBranches($treasury));
        $journals  = (new ExpensePosting())->journalsOf($expenseId);

        $this->assertSame(array_map(static fn (array $journal): int => $journal['branch'], array_values($this->expenseJournals($expenseId))), array_map(fn (object $journal): int => (int) $this->accountingDb()->table('accounting_journals')->where('journal_id', $journal->journal_id)->get()->getRow()->branch_id, $journals));
        $this->assertSame(['100.0000', '100.0000'], array_map(static fn (object $journal): string => (string) $journal->total_debit, $journals));
    }

    public function testSaveValue_IgnoresTheBranchOfTheExpenseWhileTheBooksAreOff(): void
    {
        ['branch' => $branch, 'treasury' => $treasury] = $this->treasuryOfAnotherBranch();

        $this->setConfigValue('accounting_enabled', '0');

        $expenseId = $this->saveExpense($this->acrossBranches($treasury) + ['treasury_branch_id' => $branch]);

        $this->assertNotNull($expenseId, 'The expense is saved as it always was');
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_branch_id);
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixtures T-36 to T-40, T-42 and T-64
    // ---------------------------------------------------------------------------------------

    /**
     * The account of each role a journal of an expense names.
     *
     * @return array<string, int>
     */
    private function expenseRoles(?int $treasuryId = null, ?int $costAccount = null): array
    {
        return [
            'expense'        => $costAccount ?? $this->accountId('expenses_default'),
            'tax_input'      => $this->accountId('tax_input'),
            'treasury'       => $treasuryId === null ? $this->accountId('cash') : (int) (new TreasuryService())->accountOf($treasuryId),
            'other_payables' => $this->accountId('other_payables'),
        ];
    }

    /**
     * The lines of the one journal that stands for an expense, as the fixtures write them.
     *
     * @return list<array{role: string, debit: string, credit: string}>
     */
    private function roleLinesOfExpense(int $expenseId, ?int $treasuryId = null, ?int $costAccount = null): array
    {
        $standing = array_values(array_filter($this->journalsOfSource(self::SOURCE, $expenseId), static fn (object $journal): bool => $journal->status === 'posted'));

        $this->assertCount(1, $standing, "expense {$expenseId} has one standing journal");

        return $this->roleLines((int) $standing[0]->journal_id, $this->expenseRoles($treasuryId, $costAccount));
    }

    /**
     * An expense of a case, paid from a treasury that is new.
     *
     * @return array{expense: int, treasury: int}
     */
    private function expenseOfCase(string $amount, string $tax, string $payment = 'Cash', ?int $categoryId = null): array
    {
        $treasury            = $this->treasury();
        $data                = $this->expenseData($amount, $tax, $payment, $categoryId);
        $data['treasury_id'] = $treasury;
        $expenseId           = $this->saveExpense($data);

        $this->assertNotNull($expenseId);

        return ['expense' => $expenseId, 'treasury' => $treasury];
    }

    public function testSaveValue_AcceptsAnExpenseIntoATreasuryThatHoldsNothingAsTheReferenceSystemDoes(): void
    {
        $case     = $this->referenceCase('T-36', 'expense_negative_balance', 'expense-into-an-empty-treasury-is-accepted', 'exact');
        $treasury = $this->treasury();
        $before   = $this->assertTreasuryTiesToItsAccount($treasury);
        $data     = $this->expenseData($case['inputs']['amount'], $case['inputs']['tax']);

        $data['treasury_id'] = $treasury;

        $expenseId = $this->saveExpense($data);

        $this->assertSame($case['inputs']['treasury_balance_before'], $before);
        $this->assertSameAsFixture($case['expected'], [
            'accepted'               => $expenseId !== null,
            'journals'               => ['expense' => $this->roleLinesOfExpense((int) $expenseId, $treasury)],
            'treasury_balance_after' => $this->assertTreasuryTiesToItsAccount($treasury),
        ], 'expense into an empty treasury');
    }

    public function testSaveValue_SplitsAnAmountOf230WithATaxOf30IntoCostTaxAndTreasuryAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-37', 'expense_tax', 'amount-of-230-with-a-tax-of-30', 'exact');
        $made = $this->expenseOfCase($case['inputs']['amount'], $case['inputs']['tax']);

        $this->assertSameAsFixture($case['expected']['journals']['expense'], $this->roleLinesOfExpense($made['expense'], $made['treasury']), 'cost, tax and treasury');
    }

    public function testSaveValue_SplitsAnAmountOf200WithTheTaxTakenOutOfItAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-37', 'expense_tax', 'amount-of-200-with-a-tax-of-26-09-taken-out-of-it', 'exact');
        $made = $this->expenseOfCase($case['inputs']['amount'], $case['inputs']['tax']);

        $this->assertSameAsFixture($case['expected']['journals']['expense'], $this->roleLinesOfExpense($made['expense'], $made['treasury']), 'cost, tax and treasury');
    }

    public function testSaveValue_WorksNoTaxOutOfAnAmountWhereTheFormOfTheReferenceSystemDoes(): void
    {
        $case  = $this->referenceCase('T-37', 'expense_tax', 'the-tax-of-an-amount-is-worked-out-by-the-form', 'differs');
        $made  = $this->expenseOfCase($case['inputs']['amount'], '0');
        $lines = $this->roleLinesOfExpense($made['expense'], $made['treasury']);
        $of    = static fn (string $role): string => bcadd(array_sum(array_map(static fn (array $line): string => $line['role'] === $role ? $line['debit'] : '0', $lines)) . '', '0', 2);

        $this->assertSameAsFixture($case['expected'], ['tax_posted' => $of('tax_input'), 'cost_posted' => $of('expense')], 'tax and cost posted');
    }

    public function testSaveValue_ChargesNoAccountChosenOnTheExpenseWhereTheReferenceSystemLetsEachExpenseChooseItsOwn(): void
    {
        $case    = $this->referenceCase('T-38', 'expense_account', 'an-account-chosen-on-the-expense', 'differs');
        $made    = $this->expenseOfCase($case['inputs']['amount'], $case['inputs']['tax']);
        $columns = $this->accountingDb()->getFieldNames('expenses');
        $debited = (int) $this->accountingDb()->table('accounting_journal_lines l')->select('l.account_id')->join('accounting_journals j', 'j.journal_id = l.journal_id')->where(['j.source_type' => self::SOURCE, 'j.source_id' => $made['expense'], 'j.status' => 'posted'])->where('l.debit >', 0)->orderBy('l.line_no')->get()->getRow()->account_id;
        $code    = (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $debited)->get()->getRow()->code;

        $this->assertSameAsFixture($case['expected'], [
            'account_chosen_on_the_expense' => array_filter($columns, static fn (string $column): bool => stripos($column, 'account') !== false) !== [],
            'cost_account'                  => $code,
        ], 'account of the cost');
    }

    public function testSaveValue_MakesNoAccountOfItsOwnForTheCategoryOfAnExpenseWhereTheReferenceSystemMakesOneOnFirstUse(): void
    {
        $case                = $this->referenceCase('T-38', 'expense_account', 'a-category-and-its-account', 'differs');
        $category            = $this->category('Fresh');
        $data                = $this->expenseData('100.00', '0', 'Cash', $category);
        $data['treasury_id'] = $this->treasury();
        $accounts            = $this->countRows('accounting_accounts');

        $this->assertNotNull($this->saveExpense($data));
        $this->assertSameAsFixture($case['expected'], [
            'category_has_an_account_field'     => in_array('account_id', $this->accountingDb()->getFieldNames('expense_categories'), true),
            'account_made_by_the_first_expense' => $this->countRows('accounting_accounts') !== $accounts,
        ], 'category and account');
    }

    public function testSaveValue_ChargesAnExpenseWithoutAnAccountToOtherExpensesAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-38', 'expense_account', 'an-expense-without-an-account-goes-to-other-expenses', 'exact');
        $made = $this->expenseOfCase($case['inputs']['amount'], '0');

        $this->assertSameAsFixture($case['expected']['journals']['expense'], $this->roleLinesOfExpense($made['expense'], $made['treasury']), 'other expenses');
        $this->assertSame($case['expected']['cost_account'], (string) $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->accountId('expenses_default'))->get()->getRow()->code);
    }

    public function testSaveValue_RefusesAnExpenseWithoutACategoryWhereTheReferenceSystemAcceptsIt(): void
    {
        $case     = $this->referenceCase('T-38', 'expense_account', 'an-expense-without-a-category', 'differs');
        $expenses = $this->countRows('expenses');
        $journals = $this->countRows('accounting_journals');
        $data     = $this->expenseData($case['inputs']['amount'], '0');

        $data['expense_category_id'] = $case['inputs']['category'];

        $this->assertSameAsFixture($case['expected'], ['accepted' => $this->saveExpense($data) !== null], 'expense without a category');
        $this->assertSame([$expenses, $journals], [$this->countRows('expenses'), $this->countRows('accounting_journals')], 'nothing is left of the refused expense');
    }

    /**
     * An expense of 230 with 30 of tax, edited as the reference system was: 300 with 39.13 of tax, the day before, into a bank account.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array{expense: int, old: int, new: int, original: object, journals: list<object>}
     */
    private function editedExpense(array $inputs, string $typedTax): array
    {
        $first    = $inputs['first_save'];
        $edit     = $inputs['edit'];
        $old      = $this->treasury('Old drawer');
        $new      = (new TreasuryService())->create(['name' => 'New bank ' . uniqid(), 'kind' => 'bank', 'bank_name' => 'Riyad Bank', 'account_number' => '35000' . random_int(10000, 99999)]);
        $category = $this->category();
        $data     = $this->expenseData($first['amount'], $first['tax'], 'Cash', $category);

        $data['treasury_id'] = $old;
        $expenseId           = $this->saveExpense($data);
        $original            = $this->journalsOfSource(self::SOURCE, $expenseId)[0];

        $changed                = $this->expenseData($edit['amount'], $typedTax, 'Cash', $category, date('Y-m-d H:i:s', strtotime('-1 day')));
        $changed['treasury_id'] = $new;

        $this->assertSame($expenseId, $this->saveExpense($changed, $expenseId));

        return ['expense' => $expenseId, 'old' => $old, 'new' => $new, 'original' => $original, 'journals' => $this->journalsOfSource(self::SOURCE, $expenseId)];
    }

    public function testSaveValue_LeavesTheBalancesOfAnEditedExpenseWhereTheReferenceSystemLeavesThem(): void
    {
        $case = $this->referenceCase('T-39', 'expense_edit', 'edit-of-a-posted-expense-leaves-the-same-balances', 'exact');
        $made = $this->editedExpense($case['inputs'], $case['inputs']['edit']['tax']);

        $this->assertSameAsFixture($case['expected']['net_effect'], [
            'expense'      => $this->balanceOf($this->accountId('expenses_default')),
            'tax_input'    => $this->balanceOf($this->accountId('tax_input')),
            'new_treasury' => $this->balanceOf((int) (new TreasuryService())->accountOf($made['new'])),
            'old_treasury' => $this->balanceOf((int) (new TreasuryService())->accountOf($made['old'])),
        ], 'net effect of the edit');
    }

    public function testSaveValue_ReversesAndPostsAgainAnEditedExpenseWhereTheReferenceSystemEditsItsJournalInPlace(): void
    {
        $case      = $this->referenceCase('T-39', 'expense_edit', 'edit-of-a-posted-expense-keeps-or-replaces-its-journal', 'deliberate_difference');
        $made      = $this->editedExpense($case['inputs'], $case['inputs']['edit']['tax']);
        $after     = $this->accountingDb()->table('accounting_journals')->where('journal_id', $made['original']->journal_id)->get()->getRow();
        $reversals = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $made['original']->journal_id)->countAllResults();

        $this->assertSameAsFixture($case['expected'], [
            'journal_edited_in_place' => $after->total_debit !== $made['original']->total_debit,
            'reversing_journals'      => (string) $reversals,
            'journal_revisions'       => (string) max(array_map(static fn (object $journal): int => (int) $journal->revision, $made['journals'])),
        ], 'what an edit does to the journal');
    }

    public function testSaveValue_KeepsTheTaxThatWasTypedOnAnEditWhereTheReferenceSystemWorksItOutAgain(): void
    {
        $case  = $this->referenceCase('T-39', 'expense_edit', 'the-tax-typed-on-an-edit-is-kept', 'differs');
        $made  = $this->editedExpense($this->referenceCase('T-39', 'expense_edit', 'edit-of-a-posted-expense-keeps-or-replaces-its-journal', 'deliberate_difference')['inputs'], $case['inputs']['tax_typed']);
        $lines = $this->roleLinesOfExpense($made['expense'], $made['new']);
        $debit = static fn (string $role): string => bcadd(array_values(array_map(static fn (array $line): string => $line['debit'], array_filter($lines, static fn (array $line): bool => $line['role'] === $role)))[0], '0', 2);

        $this->assertSameAsFixture($case['expected'], ['tax_after_the_edit' => $debit('tax_input'), 'cost_after_the_edit' => $debit('expense')], 'tax and cost after the edit');
    }

    public function testDeleteList_GivesTheMoneyBackToTheTreasuryAsTheReferenceSystemDoes(): void
    {
        $case   = $this->referenceCase('T-40', 'expense_delete', 'deleting-an-expense-gives-the-money-back', 'exact');
        $made   = $this->expenseOfCase($case['inputs']['amount'], $case['inputs']['tax']);
        $before = $this->assertTreasuryTiesToItsAccount($made['treasury']);

        $this->assertTrue($this->expenses->delete_list([$made['expense']], $this->clerkId));
        $this->assertSameAsFixture($case['expected'], ['treasury_gain_from_the_delete' => bcsub($this->assertTreasuryTiesToItsAccount($made['treasury']), $before, 2)], 'money back');
    }

    public function testDeleteList_KeepsTheJournalOfADeletedExpenseAndWritesItsReversalWhereTheReferenceSystemErasesIt(): void
    {
        $case = $this->referenceCase('T-40', 'expense_delete', 'deleting-an-expense-erases-or-reverses-its-journal', 'deliberate_difference');
        $made = $this->expenseOfCase($case['inputs']['amount'], $case['inputs']['tax']);

        $this->assertTrue($this->expenses->delete_list([$made['expense']], $this->clerkId));

        $journals = $this->journalsOfSource(self::SOURCE, $made['expense']);

        $this->assertSameAsFixture($case['expected'], [
            'journal_erased'     => $journals === [],
            'reversing_journals' => (string) $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $journals[0]->journal_id)->countAllResults(),
        ], 'journal of a deleted expense');
    }

    public function testSaveValue_KeepsTheSupplierOfAnExpenseOutOfItsJournalAsTheReferenceSystemDoes(): void
    {
        $case     = $this->referenceCase('T-42', 'expense_supplier', 'the-supplier-of-an-expense-is-only-a-label', 'exact');
        $supplier = $this->insertSupplier('Label supplier');
        $account  = (int) (new EntityAccountService())->ensure(EntityAccountService::SUPPLIER, $supplier, 'Label supplier');
        $treasury = $this->treasury();
        $data     = $this->expenseData($case['inputs']['amount'], '0');

        $data['treasury_id'] = $treasury;
        $data['supplier_id'] = $supplier;

        $expenseId = $this->saveExpense($data);
        $lines     = $this->roleLinesOfExpense((int) $expenseId, $treasury);

        $this->assertSameAsFixture($case['expected'], ['journals' => ['expense' => $lines], 'supplier_account_in_the_journal' => $this->accountingDb()->table('accounting_journal_lines l')->join('accounting_journals j', 'j.journal_id = l.journal_id')->where(['j.source_type' => self::SOURCE, 'j.source_id' => $expenseId, 'l.account_id' => $account])->countAllResults() > 0], 'supplier label');
    }

    public function testSaveValue_OwesAnExpenseThatIsNotPaidToOtherCreditorsWhereTheReferenceSystemHasNoUnpaidExpense(): void
    {
        $case      = $this->referenceCase('T-42', 'expense_supplier', 'an-expense-that-is-not-paid', 'differs');
        $expenseId = $this->saveExpense($this->expenseData($case['inputs']['amount'], '0', 'Due'));
        $lines     = $expenseId === null ? [] : $this->roleLinesOfExpense($expenseId);
        $credited  = array_values(array_map(static fn (array $line): string => $line['role'], array_filter($lines, static fn (array $line): bool => bccomp($line['credit'], '0', 4) > 0)));

        $this->assertSameAsFixture(['can_be_saved_unpaid' => $case['expected']['can_be_saved_unpaid']], ['can_be_saved_unpaid' => $expenseId !== null], 'unpaid expense');
        $this->assertSame([$case['expected']['detail']['credited']], $credited);
    }

    public function testDeleteList_LeavesNoJournalOfADeletedExpenseStandingWhereTheReferenceSystemLeftOrphans(): void
    {
        $case = $this->referenceCase('T-64', 'reference_quirk', 'deleting-a-document-leaves-no-journal-behind', 'deliberate_difference');
        $made = $this->expenseOfCase('230.00', '30.00');
        $sums = $this->accountingDb()->table('accounting_journal_lines l')->select('SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)->join('accounting_journals j', 'j.journal_id = l.journal_id')->whereIn('j.status', ['posted', 'reversed'])->get()->getRow();

        $this->assertTrue($this->expenses->delete_list([$made['expense']], $this->clerkId));

        $this->assertSameAsFixture($case['expected'], ['journal_left_standing_after_the_delete' => $this->postedJournals($made['expense']) > 0], 'journals of a deleted expense');
        $this->assertSame('0.00', $this->balanceOf($this->accountId('expenses_default')));
        $this->assertSame('0.00', $this->balanceOf($this->accountId('tax_input')));
        $this->assertSame(bcadd((string) $sums->debit, '0', 2), bcadd((string) $sums->credit, '0', 2));
    }

    public function testSaveValue_PaysAnExpenseOfAnyWayFromTheTreasuryNamedWhateverItsKindAndWritesNoChequeRecord(): void
    {
        foreach (['cash', 'check', 'bank_transfer', 'debit', 'credit'] as $way) {
            $bank = (new TreasuryService())->create(['name' => 'Bank ' . $way . uniqid(), 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => strtoupper(uniqid('AC'))]);
            $data = $this->expenseData('115.00', '15.00', lang('Sales.' . $way));

            $data['treasury_id'] = $bank;

            $expenseId = $this->saveExpense($data);

            $this->assertSame((int) (new TreasuryService())->accountOf($bank) . '=0.00/115.00', $this->postedLines(self::SOURCE, $expenseId)[2], $way);
            $this->assertSame('-115.00', $this->assertTreasuryTiesToItsAccount($bank), $way);
        }

        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testSaveValue_PaysACheckExpenseThatNamesNoTreasuryFromTheAccountRoutedForChecks(): void
    {
        $held = $this->customAccount('12', '1230', 'Checks held');

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.check')->update(['account_id' => $held]);

        $expenseId = $this->saveExpense($this->expenseData('40.00', '0', lang('Sales.check')));

        $this->assertNull($this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow()->treasury_id);
        $this->assertSame('-40.00', $this->balanceOf($held));
        $this->assertSame(0, $this->countRows('cheques'));
    }

    public function testJournalsOf_LeavesOutThePreviewsOfABatch(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('100', '0', 'Cash', null, '2026-10-20 10:00:00'));
        $before    = (new ExpensePosting())->journalsOf($expenseId);

        $this->previewOfSource('expense', $expenseId);

        $this->assertEquals($before, (new ExpensePosting())->journalsOf($expenseId));
        $this->assertCount(1, $before);
    }

    public function testSync_RefusesAnExpenseInAForeignCurrencyWithANamedRefusalAndPostsNothingOfIt(): void
    {
        $expenseId = $this->saveExpense($this->expenseData('230.00', '30.00'));
        $journals  = $this->postedJournals($expenseId);

        $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->update(['currency_code' => 'USD', 'currency_rate' => '3.75']);

        try {
            (new ExpensePosting())->sync($expenseId, null);
            $this->fail('An expense in a foreign currency was not refused.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::FxNotSupported, $exception->error);
            $this->assertSame('expense', $exception->context['what']);
        }

        $this->assertSame($journals, $this->postedJournals($expenseId));
    }

    public function testDeleteList_LetsTwoSessionsDeleteTheSameExpenseAndTakesItsJournalBackOnce(): void
    {
        $id      = $this->saveExpense($this->expenseData('100.00'));
        $session = ['delete', (string) $id, (string) $this->clerkId];

        $this->assertSame(['done', 'done'], $this->raceSessions('expenseRace', [$session, $session]));
        $this->assertSame(1, $this->accountingDb()->table('expenses')->where(['expense_id' => $id, 'deleted' => 1])->countAllResults());
        $this->assertSame(0, $this->postedJournals($id));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->countAllResults(), 'The journal was taken back once');
        $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testSaveValue_LetsTwoSessionsChangeTheSameExpenseAndLeavesOneJournalThatSaysWhatTheRowSays(): void
    {
        $category = $this->category();
        $id       = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));
        $answers  = $this->raceSessions('expenseRace', [
            ['save', (string) $id, '150.00', (string) $category, (string) $this->clerkId],
            ['save', (string) $id, '230.00', (string) $category, (string) $this->clerkId],
        ]);

        $stored = bcadd($this->accountingDb()->table('expenses')->where('expense_id', $id)->get()->getRow()->amount, '0', 2);

        $this->assertSame(['done', 'done'], $answers);
        $this->assertContains($stored, ['150.00', '230.00']);
        $this->assertSame(1, $this->postedJournals($id), 'One journal stands for the expense');
        $this->assertSame('-' . $stored, $this->balanceOf($this->accountId('cash')), 'The cash account holds what the row says');
        $this->assertSame($stored, $this->postedDebit(self::SOURCE, $id));
    }

    public function testSaveValue_LetsAChangeAndADeletionOfTheSameExpenseRaceAndLeavesTheBooksAsTheRowSaysWhoeverWins(): void
    {
        $category = $this->category();
        $id       = $this->saveExpense($this->expenseData('100.00', '0', 'Cash', $category));
        $answers  = $this->raceSessions('expenseRace', [
            ['save', (string) $id, '150.00', (string) $category, (string) $this->clerkId],
            ['delete', (string) $id, (string) $this->clerkId],
        ]);

        $row = $this->accountingDb()->table('expenses')->where('expense_id', $id)->get()->getRow();

        $this->assertSame(['done', 'done'], $answers);

        if ((int) $row->deleted === 1) {
            $this->assertSame(0, $this->postedJournals($id), 'The deletion went last, so nothing stands');
            $this->assertSame('0.00', $this->balanceOf($this->accountId('cash')));
        } else {
            $this->assertSame(1, $this->postedJournals($id), 'The change went last, so the expense stands again');
            $this->assertSame('-150.00', $this->balanceOf($this->accountId('cash')));
            $this->assertSame('150.00', $this->postedDebit(self::SOURCE, $id));
        }
    }

    private function postedDebit(string $source, int $sourceId): string
    {
        return bcadd($this->accountingDb()->table('accounting_journal_lines l')->selectSum('l.debit', 'total')->join('accounting_journals j', 'j.journal_id = l.journal_id')->where(['j.source_type' => $source, 'j.source_id' => $sourceId, 'j.status' => 'posted'])->get()->getRow()->total, '0', 2);
    }

    public function testSaveValue_RefusesANewExpenseWhileOperationsAreFrozen(): void
    {
        $this->setConfigValue(OperationsHold::KEY, json_encode(['since' => '2026-11-16 10:00:00', 'by' => 1, 'reason' => 'Fixing the routing']));

        try {
            $this->assertNull($this->saveExpense($this->expenseData('100.00', '0', 'Cash', null, date('Y-m-d') . ' 09:00:00')));
            $this->assertRefused(PostingError::OperationsHeld);
        } finally {
            $this->setConfigValue(OperationsHold::KEY, '');
        }
    }
}
