<?php

namespace Tests\Models;

use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;

/**
 * What the expense model shows about the books while accounting is on, and that it shows and writes
 * nothing of them while accounting is off.
 */
class ExpenseTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;

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

        $this->startExpenses();
    }

    protected function tearDown(): void
    {
        $this->stopExpenses();

        parent::tearDown();
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
}
