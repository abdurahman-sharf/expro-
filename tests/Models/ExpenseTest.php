<?php

namespace Tests\Models;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Numbering\NumberingSequence;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\RoleFixtureTrait;

/**
 * What the expense model shows about the books while accounting is on, and that it shows and writes
 * nothing of them while accounting is off; and the number the expense is given by the numbering,
 * with the books off and on.
 */
class ExpenseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use RoleFixtureTrait;

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

        $this->clearNumbering();
        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();
        $this->clearNumbering();

        parent::tearDown();
    }

    private function clearNumbering(): void
    {
        $db = $this->accountingDb();

        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(bool $onlyUnposted = false, bool $deleted = false): array
    {
        return [
            'is_deleted'    => $deleted ? 1 : 0,
            'start_date'    => '2026-01-01',
            'end_date'      => '2026-12-31',
            'only_debit'    => false,
            'only_credit'   => false,
            'only_cash'     => false,
            'only_due'      => false,
            'only_check'    => false,
            'only_unposted' => $onlyUnposted,
        ];
    }

    /**
     * @return array<int, object> The rows the list shows, by expense.
     */
    private function listed(bool $onlyUnposted = false, bool $deleted = false): array
    {
        $rows = [];

        foreach ($this->expenses->search('', $this->filters($onlyUnposted, $deleted), 0, 0, 'expense_id', 'asc')->getResult() as $row) {
            $rows[(int) $row->expense_id] = $row;
        }

        return $rows;
    }

    /**
     * An expense saved while the books did not follow it, a posted one and one of zero.
     *
     * @return array{legacy: int, posted: int, zero: int}
     */
    private function oneOfEach(): array
    {
        $this->setConfigValue('accounting_enabled', '0');
        $legacy = $this->saveExpense($this->expenseData('100.00'));
        $this->setConfigValue('accounting_enabled', '1');

        return ['legacy' => $legacy, 'posted' => $this->saveExpense($this->expenseData('50.00')), 'zero' => $this->saveExpense($this->expenseData('0.00'))];
    }

    private function storeExpense(string $amount = '25.00', int $expenseId = NEW_ENTRY): int
    {
        $stored = $this->saveExpense($this->expenseData($amount), $expenseId);

        $this->assertNotNull($stored);

        return $stored;
    }

    private function storeExpenseDated(string $date): int
    {
        $stored = $this->saveExpense($this->expenseData('10.00', '0', 'Cash', null, $date));

        $this->assertNotNull($stored);

        return $stored;
    }

    private function numberOf(int $expenseId): ?string
    {
        return (new DocumentNumbering())->numbers(DocumentType::Expense, 'expenses', [$expenseId])[$expenseId] ?? null;
    }

    private function switchNumberingOn(): NumberingSequence
    {
        return (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true, 'prefix' => 'EXP-', 'padding' => 5]);
    }

    private function nextNumber(): int
    {
        return (new DocumentNumbering())->sequence(DocumentType::Expense)->nextNumber;
    }

    private function useUpTheSequence(): void
    {
        $db = $this->accountingDb();

        $this->switchNumberingOn();
        $db->table('document_sequences')->where('doc_type', 'expense')->update(['next_number' => 4294967294]);
        $db->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'expense', 'source_table' => 'expenses', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'EXP-4294967294']);
    }

    public function testSearch_ShowsWhetherTheBooksHoldEachExpense(): void
    {
        $ids  = $this->oneOfEach();
        $rows = $this->listed();

        $this->assertSame([0, 1, 1], [(int) $rows[$ids['legacy']]->ledger_state, (int) $rows[$ids['posted']]->ledger_state, (int) $rows[$ids['zero']]->ledger_state]);
    }

    public function testSearch_ListsOnlyTheExpensesStillMissingFromTheBooksWhenAsked(): void
    {
        $ids = $this->oneOfEach();

        $this->assertSame([$ids['legacy']], array_keys($this->listed(true)));
        $this->assertSame(3, count($this->listed(false)));
    }

    public function testSearch_ShowsADeletedExpenseAsOwingTheBooksNothing(): void
    {
        $ids = $this->oneOfEach();

        $this->assertTrue($this->expenses->delete_list([$ids['legacy'], $ids['posted']], $this->clerkId));

        $rows = $this->listed(false, true);

        $this->assertSame([1, 1], [(int) $rows[$ids['legacy']]->ledger_state, (int) $rows[$ids['posted']]->ledger_state]);
        $this->assertSame([], $this->listed(true, true));
    }

    public function testSearch_ShowsAnExpenseThatStandsInTheBooksAgainAfterAnEditThatReplacedItsJournal(): void
    {
        $ids = $this->oneOfEach();

        $this->saveExpense($this->expenseData('80.00'), $ids['posted']);

        $this->assertSame(1, (int) $this->listed()[$ids['posted']]->ledger_state);
        $this->assertSame([$ids['legacy']], array_keys($this->listed(true)));
    }

    public function testSearch_CarriesNoLedgerColumnWhileAccountingIsOff(): void
    {
        $this->oneOfEach();
        $this->setConfigValue('accounting_enabled', '0');

        foreach ($this->listed() as $row) {
            $this->assertObjectNotHasProperty('ledger_state', $row);
        }
    }

    public function testSearch_IgnoresTheFilterOfUnpostedExpensesWhileAccountingIsOff(): void
    {
        $this->oneOfEach();
        $this->setConfigValue('accounting_enabled', '0');

        $this->assertCount(3, $this->listed(true));
    }

    public function testGetFoundRows_CountsOnlyTheExpensesStillMissingFromTheBooksWhenAsked(): void
    {
        $this->oneOfEach();

        $this->assertSame(3, $this->expenses->get_found_rows('', $this->filters()));
        $this->assertSame(1, $this->expenses->get_found_rows('', $this->filters(true)));
    }

    public function testGetInfo_TellsTheLedgerStateTheBranchAndTheTreasuryOfTheExpense(): void
    {
        $ids      = $this->oneOfEach();
        $treasury = $this->treasury();
        $data     = $this->expenseData('70.00');

        $data['treasury_id'] = $treasury;

        $withTreasury = $this->saveExpense($data);
        $info         = $this->expenses->get_info($withTreasury);

        $this->assertSame([1, $treasury, 1], [(int) $info->ledger_state, (int) $info->treasury_id, (int) $info->branch_id]);
        $this->assertSame(0, (int) $this->expenses->get_info($ids['legacy'])->ledger_state);
        $this->assertNull($this->expenses->get_info($ids['legacy'])->branch_id);
    }

    public function testGetInfo_CarriesNoLedgerStateWhileAccountingIsOff(): void
    {
        $ids = $this->oneOfEach();

        $this->setConfigValue('accounting_enabled', '0');

        $this->assertObjectNotHasProperty('ledger_state', $this->expenses->get_info($ids['posted']));
    }

    public function testSaveValue_ForgetsAnEarlierRefusalOnceTheNextSaveSucceeds(): void
    {
        $this->closeThrough('2026-10-31');

        $this->assertNull($this->saveExpense($this->expenseData('10.00')));
        $this->assertInstanceOf(PostingException::class, $this->expenses->accountingFailure());
        $this->assertSame(PostingError::PeriodClosed, $this->expenses->accountingFailure()->error);

        $this->assertNotNull($this->saveExpense($this->expenseData('10.00', '0', 'Cash', null, '2026-11-02 10:00:00')));
        $this->assertNull($this->expenses->accountingFailure());
    }

    public function testSaveValue_WritesNoAccountingColumnWhileAccountingIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $data                = $this->expenseData('40.00');
        $data['treasury_id'] = $this->treasury();
        $data['branch_id']   = 1;
        $expenseId           = $this->saveExpense($data);
        $row                 = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        $this->assertSame([null, null], [$row->branch_id, $row->treasury_id]);
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testDeleteList_MarksTheExpensesDeletedWithoutTouchingTheBooksWhileAccountingIsOff(): void
    {
        $ids = $this->oneOfEach();

        $this->setConfigValue('accounting_enabled', '0');

        $journals = $this->countRows('accounting_journals');

        $this->assertTrue($this->expenses->delete_list([$ids['posted'], $ids['legacy']]));
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame(2, $this->accountingDb()->table('expenses')->where('deleted', 1)->countAllResults());
    }

    public function testDeleteList_ForgetsAnEarlierRefusalOnceTheNextDeleteSucceeds(): void
    {
        $ids = $this->oneOfEach();

        $this->assertFalse($this->expenses->delete_list([$ids['posted']], $this->employeeWithGrants([], [$this->createBranch('B2')])));
        $this->assertNotNull($this->expenses->accountingFailure());

        $this->assertTrue($this->expenses->delete_list([$ids['posted']], $this->clerkId));
        $this->assertNull($this->expenses->accountingFailure());
    }

    public function testSaveValue_GivesNoNumberWhileTheSequenceIsOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $expenseId = $this->storeExpense();

        $this->assertNull($this->numberOf($expenseId));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testSaveValue_HandsTheIdOfTheNewExpenseBack(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $data = $this->expenseData('25.00');

        $this->assertTrue($this->expenses->save_value($data));
        $this->assertGreaterThan(0, (int) $data['expense_id']);
    }

    public function testSaveValue_NumbersANewExpenseWhenTheSequenceIsOn(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->switchNumberingOn();

        $first  = $this->storeExpense();
        $second = $this->storeExpense('30.00');

        $this->assertSame('EXP-00001', $this->numberOf($first));
        $this->assertSame('EXP-00002', $this->numberOf($second));
    }

    public function testSaveValue_ContinuesFromTheExpensesThatAlreadyShowTheirId(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $before = $this->storeExpense();

        $sequence = (new DocumentNumbering())->configure(DocumentType::Expense, ['enabled' => true]);
        $after    = $this->storeExpense();

        $this->assertGreaterThan($before, $sequence->nextNumber);
        $this->assertSame((string) $sequence->nextNumber, $this->numberOf($after));
        $this->assertNull($this->numberOf($before));
    }

    public function testSaveValue_KeepsTheNumberWhenTheExpenseIsEdited(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->switchNumberingOn();

        $expenseId = $this->storeExpense();

        $this->storeExpense('99.00', $expenseId);

        $this->assertSame('EXP-00001', $this->numberOf($expenseId));
        $this->assertSame(2, $this->nextNumber());
    }

    public function testSaveValue_KeepsTheNumberOfADeletedExpenseAndNeverGivesItAgain(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->switchNumberingOn();

        $first = $this->storeExpense();

        $this->assertTrue($this->expenses->delete_list([$first]));

        $second = $this->storeExpense();

        $this->assertSame('EXP-00001', $this->numberOf($first));
        $this->assertSame('EXP-00002', $this->numberOf($second));
    }

    public function testSaveValue_RefusesTheExpenseAndLeavesNothingWhenNoNumberCanBeGiven(): void
    {
        $this->setConfigValue('accounting_enabled', '0');
        $this->useUpTheSequence();

        $before = $this->countRows('expenses');
        $data   = $this->expenseData('25.00');

        $this->assertFalse($this->expenses->save_value($data));
        $this->assertSame(AccountingError::NumberingExhausted, $this->expenses->accountingFailure()->error);
        $this->assertSame($before, $this->countRows('expenses'));
        $this->assertSame(1, $this->countRows('document_numbers'));
    }

    public function testSaveValue_NumbersANewExpenseInTheTransactionThatPostsItsJournal(): void
    {
        $this->switchNumberingOn();

        $first  = $this->storeExpense();
        $second = $this->storeExpense('30.00');

        $this->assertSame(['EXP-00001', 'EXP-00002'], [$this->numberOf($first), $this->numberOf($second)]);
        $this->assertSame(['posted'], array_column($this->journalsOfSource('expense', $first), 'status'));
        $this->assertSame(['posted'], array_column($this->journalsOfSource('expense', $second), 'status'));
    }

    public function testSaveValue_NumbersAnExpenseOfZeroThatTheBooksDoNotPost(): void
    {
        $this->switchNumberingOn();

        $expenseId = $this->storeExpense('0.00');

        $this->assertSame('EXP-00001', $this->numberOf($expenseId));
        $this->assertSame(0, $this->countRows('accounting_journals'));
    }

    public function testSaveValue_ConsumesNoNumberWhenTheBooksRefuseANewExpense(): void
    {
        $this->switchNumberingOn();
        $this->closeThrough('2026-10-31');

        $this->assertNull($this->saveExpense($this->expenseData('10.00')));
        $this->assertSame(PostingError::PeriodClosed, $this->expenses->accountingFailure()->error);
        $this->assertSame([0, 0, 0], [$this->countRows('expenses'), $this->countRows('document_numbers'), $this->countRows('accounting_journals')]);
        $this->assertSame(1, $this->nextNumber());

        $next = $this->storeExpenseDated('2026-11-02 10:00:00');

        $this->assertSame('EXP-00001', $this->numberOf($next));
        $this->assertSame(2, $this->nextNumber());
    }

    public function testSaveValue_ConsumesNoNumberWhenTheCategoryNeedsAnAccountItHasNot(): void
    {
        $this->switchNumberingOn();
        $this->setConfigValue('accounting_expense_account_required', '1');

        $this->assertNull($this->saveExpense($this->expenseData('10.00')));
        $this->assertSame(AccountingError::ExpenseCategoryAccountRequired, $this->expenses->accountingFailure()->error);
        $this->assertSame([0, 0], [$this->countRows('expenses'), $this->countRows('document_numbers')]);
        $this->assertSame(1, $this->nextNumber());
    }

    public function testSaveValue_KeepsTheNumberAndPostsAgainWhenANumberedExpenseIsEdited(): void
    {
        $this->switchNumberingOn();

        $expenseId = $this->storeExpense();

        $this->storeExpense('99.00', $expenseId);

        $this->assertSame('EXP-00001', $this->numberOf($expenseId));
        $this->assertSame(2, $this->nextNumber());
        $this->assertSame(1, $this->countRows('document_numbers'));
        $this->assertSame(['reversed', 'posted'], array_column($this->journalsOfSource('expense', $expenseId), 'status'));
        $this->assertSame('-99.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testSaveValue_KeepsTheNumberAndTheJournalWhenTheBooksRefuseTheEditOfANumberedExpense(): void
    {
        $this->switchNumberingOn();

        $expenseId = $this->storeExpense();

        $this->closeThrough('2026-10-31');

        $this->assertNull($this->saveExpense($this->expenseData('99.00'), $expenseId));
        $this->assertSame(PostingError::PeriodClosed, $this->expenses->accountingFailure()->error);
        $this->assertSame('EXP-00001', $this->numberOf($expenseId));
        $this->assertSame(2, $this->nextNumber());
        $this->assertSame('25.00', bcadd((string) $this->expenses->get_info($expenseId)->amount, '0', 2));
        $this->assertSame(['posted'], array_column($this->journalsOfSource('expense', $expenseId), 'status'));
    }

    public function testSaveValue_RefusesTheExpenseAndLeavesNothingWhenNoNumberCanBeGivenWhileAccountingIsOn(): void
    {
        $this->useUpTheSequence();

        $data = $this->expenseData('25.00');

        $this->assertFalse($this->expenses->save_value($data, NEW_ENTRY, $this->clerkId));
        $this->assertSame([0, 1, 0], [$this->countRows('expenses'), $this->countRows('document_numbers'), $this->countRows('accounting_journals')]);
        $this->assertInstanceOf(AccountingException::class, $this->expenses->accountingFailure());
        $this->assertSame(AccountingError::NumberingExhausted, $this->expenses->accountingFailure()->error);
    }

    public function testDeleteList_KeepsTheNumberOfADeletedExpenseInTheRegisterAndReversesItsJournal(): void
    {
        $this->switchNumberingOn();

        $first = $this->storeExpense();

        $this->assertTrue($this->expenses->delete_list([$first], $this->clerkId));

        $second = $this->storeExpense('30.00');

        $this->assertSame('EXP-00001', $this->numberOf($first));
        $this->assertSame('EXP-00002', $this->numberOf($second));
        $this->assertArrayHasKey($first, $this->listed(false, true));
        $this->assertSame('-30.00', $this->balanceOf($this->accountId('cash')));
    }

    public function testDeleteList_KeepsTheNumberAndTheExpenseWhenTheBooksRefuseTheDeletion(): void
    {
        $this->switchNumberingOn();

        $expenseId = $this->storeExpense();

        $this->closeThrough('2026-10-31');

        $this->assertFalse($this->expenses->delete_list([$expenseId], $this->clerkId));
        $this->assertSame(PostingError::PeriodClosed, $this->expenses->accountingFailure()->error);
        $this->assertSame('EXP-00001', $this->numberOf($expenseId));
        $this->assertArrayHasKey($expenseId, $this->listed());
        $this->assertSame(['posted'], array_column($this->journalsOfSource('expense', $expenseId), 'status'));
    }

    private function ownRecordsClerk(): int
    {
        return $this->employeeWithRoles([$this->createRole([], 'own')]);
    }

    private function expenseOf(int $employeeId, string $amount = '25.00'): int
    {
        $stored = $this->saveExpense($this->expenseData($amount, '0', 'Cash', null, '2026-10-20 10:00:00', $employeeId), NEW_ENTRY, $employeeId);

        $this->assertNotNull($stored);

        return $stored;
    }

    public function testSearch_ListsOnlyTheExpensesOfAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $own    = $this->ownRecordsClerk();
        $mine   = [$this->expenseOf($own), $this->expenseOf($own, '30.00')];
        $theirs = $this->expenseOf($this->clerkId);

        $seen = array_map(static fn (object $row): int => (int) $row->expense_id, $this->expenses->search('', $this->filters(), 0, 0, 'expense_id', 'asc', false, $own)->getResult());

        $this->assertSame($mine, $seen);
        $this->assertSame(2, $this->expenses->get_found_rows('', $this->filters(), $own));

        $everyone = array_map(static fn (object $row): int => (int) $row->expense_id, $this->expenses->search('', $this->filters(), 0, 0, 'expense_id', 'asc', false, $this->clerkId)->getResult());

        $this->assertContains($theirs, $everyone);
        $this->assertContains($mine[0], $everyone);
    }

    public function testSearch_CutsTheListToTheEmployeeOfTheSessionWhenNoViewerIsGiven(): void
    {
        $own    = $this->ownRecordsClerk();
        $mine   = $this->expenseOf($own);
        $theirs = $this->expenseOf($this->clerkId);

        $_SESSION['person_id'] = $own;

        try {
            $this->assertSame([$mine], array_keys($this->listed()));
        } finally {
            unset($_SESSION['person_id']);
        }

        $this->assertArrayHasKey($theirs, $this->listed());
    }

    public function testGetPaymentsSummary_AddsUpOnlyTheExpensesOfAnOwnRecordsEmployee(): void
    {
        $own = $this->ownRecordsClerk();

        $this->expenseOf($own, '40.00');
        $this->expenseOf($this->clerkId, '60.00');

        $summary = $this->expenses->get_payments_summary('', $this->filters(), $own);

        $this->assertSame(1, array_sum(array_column($summary, 'count')));
        $this->assertEquals(40.00, array_sum(array_column($summary, 'amount')));
    }

    public function testGetInfo_ReturnsTheEmptyRecordForAnotherEmployeesExpenseAsForOneThatDoesNotExist(): void
    {
        $own    = $this->ownRecordsClerk();
        $mine   = $this->expenseOf($own);
        $theirs = $this->expenseOf($this->clerkId);

        $this->assertSame($mine, (int) $this->expenses->get_info($mine, $own)->expense_id);
        $this->assertSame(NEW_ENTRY, (int) $this->expenses->get_info($theirs, $own)->expense_id);
        $this->assertEquals($this->expenses->get_info(999999, $own), $this->expenses->get_info($theirs, $own));
        $this->assertSame($theirs, (int) $this->expenses->get_info($theirs, $this->clerkId)->expense_id);
    }

    public function testGetMultipleInfoAndGetExpensePayment_ReadOnlyTheExpensesOfAnOwnRecordsEmployee(): void
    {
        $own    = $this->ownRecordsClerk();
        $mine   = $this->expenseOf($own);
        $theirs = $this->expenseOf($this->clerkId);

        $this->assertSame([$mine], array_map(static fn (object $row): int => (int) $row->expense_id, $this->expenses->get_multiple_info([$mine, $theirs], $own)->getResult()));
        $this->assertSame(1, $this->expenses->get_expense_payment($mine, $own)->getNumRows());
        $this->assertSame(0, $this->expenses->get_expense_payment($theirs, $own)->getNumRows());
    }

    public function testSaveValue_RefusesToChangeAnotherEmployeesExpenseAndKeepsItsJournal(): void
    {
        $own     = $this->ownRecordsClerk();
        $theirs  = $this->expenseOf($this->clerkId, '70.00');
        $journal = $this->journalsOfSource('expense', $theirs);
        $data    = $this->expenseData('90.00', '0', 'Cash', null, '2026-10-20 10:00:00', $this->clerkId);

        $this->assertNull($this->saveExpense($data, $theirs, $own));
        $this->assertNull($this->expenses->accountingFailure(), 'The refusal is not told apart from one for an expense that does not exist');
        $this->assertSame('70.00', (string) $this->accountingDb()->table('expenses')->select('amount')->where('expense_id', $theirs)->get()->getRow()->amount);
        $this->assertSame($journal, $this->journalsOfSource('expense', $theirs));
    }

    public function testSaveValue_RefusesToTurnAnotherEmployeesExpenseIntoOneOfTheirsWhileTheBooksAreOff(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $own    = $this->ownRecordsClerk();
        $theirs = $this->expenseOf($this->clerkId, '70.00');
        $data   = $this->expenseData('90.00', '0', 'Cash', null, '2026-10-20 10:00:00', $own);

        $this->assertNull($this->saveExpense($data, $theirs, $own));
        $this->assertSame((string) $this->clerkId, (string) $this->accountingDb()->table('expenses')->select('employee_id')->where('expense_id', $theirs)->get()->getRow()->employee_id);
    }

    public function testSaveValue_ChangesTheOwnExpenseOfAnOwnRecordsEmployeeAndAnyOneForAnEmployeeWhoSeesEverything(): void
    {
        $own    = $this->ownRecordsClerk();
        $mine   = $this->expenseOf($own, '10.00');
        $theirs = $this->expenseOf($this->clerkId, '20.00');

        $this->assertSame($mine, $this->saveExpense($this->expenseData('11.00', '0', 'Cash', null, '2026-10-20 10:00:00', $own), $mine, $own));
        $this->assertSame($theirs, $this->saveExpense($this->expenseData('21.00', '0', 'Cash', null, '2026-10-20 10:00:00', $this->clerkId), $theirs, $this->clerkId));
    }

    public function testDeleteList_RefusesTheWholeListWhenOneExpenseIsAnotherEmployeesAndKeepsTheJournals(): void
    {
        $own     = $this->ownRecordsClerk();
        $mine    = $this->expenseOf($own);
        $theirs  = $this->expenseOf($this->clerkId);
        $journal = $this->journalsOfSource('expense', $mine);

        $this->assertFalse($this->expenses->delete_list([$mine, $theirs], $own));
        $this->assertSame(0, $this->accountingDb()->table('expenses')->whereIn('expense_id', [$mine, $theirs])->where('deleted', 1)->countAllResults(), 'Nothing of a refused list is deleted');
        $this->assertSame($journal, $this->journalsOfSource('expense', $mine));
        $this->assertTrue($this->expenses->delete_list([$mine], $own));
    }

    public function testDeleteList_RefusesAnotherEmployeesExpenseWhileTheBooksAreOffToo(): void
    {
        $this->setConfigValue('accounting_enabled', '0');

        $own    = $this->ownRecordsClerk();
        $theirs = $this->expenseOf($this->clerkId);

        $this->assertFalse($this->expenses->delete_list([$theirs], $own));
        $this->assertSame(0, (int) $this->accountingDb()->table('expenses')->select('deleted')->where('expense_id', $theirs)->get()->getRow()->deleted);
        $this->assertTrue($this->expenses->delete_list([$theirs], $this->clerkId));
    }

    public function testOutsideRecordScope_TreatsAnExpenseThatDoesNotExistLikeAnotherEmployeesForAnOwnRecordsEmployeeOnly(): void
    {
        $own  = $this->ownRecordsClerk();
        $mine = $this->expenseOf($own);

        $this->assertFalse($this->expenses->outsideRecordScope($mine, $own));
        $this->assertTrue($this->expenses->outsideRecordScope(999999, $own));
        $this->assertFalse($this->expenses->outsideRecordScope(999999, $this->clerkId));
    }
}
