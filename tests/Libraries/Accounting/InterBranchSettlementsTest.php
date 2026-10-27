<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\InterBranchBalances;
use App\Libraries\Accounting\InterBranchPosition;
use App\Libraries\Accounting\InterBranchSettlements;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\SettlementPlan;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Organization\BranchError;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Clearing what two branches owe each other in one document: a journal for each branch, balanced
 * inside it, that takes what was owed and what was owing off the accounts between branches and
 * moves what is left in cash, or moves nothing when the two offset. Both are written and taken back
 * together, in the order of the branch numbers, and the document is refused whenever one of the
 * branches would refuse its journal.
 */
class InterBranchSettlementsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private InterBranchSettlements $settlements;
    private InterBranchBalances $balances;
    private TreasuryService $treasuries;
    private TreasuryTransfers $transfers;
    private int $manager;
    private int $settler;
    private int $main;
    private int $other;
    private int $branch;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->treasuries  = new TreasuryService();
        $this->settlements = new InterBranchSettlements();
        $this->balances    = new InterBranchBalances();
        $this->transfers   = new TreasuryTransfers();
        $this->manager     = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $this->settler     = $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE]);
        $this->branch      = $this->createBranch('B2');
        $this->main        = $this->treasuries->ensureMain();
        $this->other       = $this->treasuries->create(['name' => 'Second branch drawer', 'kind' => 'cash', 'branch_id' => $this->branch], $this->manager);

        $this->hold($this->main, '1000.00', 9001, 1);
        $this->hold($this->other, '1000.00', 9002, $this->branch);
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopBuying();

        parent::tearDown();
    }

    private function hold(int $treasuryId, string $amount, int $number, int $branchId): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-20',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($treasuryId), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: 'opening',
            sourceId: $number,
            currencyCode: 'SAR',
            branchId: $branchId,
        ));
    }

    private function balanceOf(int $treasuryId): string
    {
        return $this->treasuries->position($treasuryId)->balance;
    }

    private function refusal(callable $action): AccountingException|PostingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|PostingException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    /**
     * Money sent from the treasury of one branch to the treasury of the other.
     */
    private function send(bool $fromFirst, string $amount, string $date = '2026-10-21'): int
    {
        return $this->transfers->transfer($fromFirst ? $this->main : $this->other, $fromFirst ? $this->other : $this->main, $amount, $date, '', $this->manager);
    }

    private function settle(?int $from = null, ?int $to = null, string $date = '2026-10-31', ?int $employee = null, string $note = '', ?string $key = null): int
    {
        return $this->settlements->settle(1, $this->branch, $date, $from, $to, $note, $key, $employee ?? $this->settler);
    }

    /**
     * The journals of a settlement by branch, as "account:debit:credit" lines.
     *
     * @return array<int, list<string>>
     */
    private function journalsOf(int $settlementId, string $status = 'posted'): array
    {
        $found = [];

        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', [InterBranchSettlements::SOURCE, InterBranchSettlements::SOURCE_SECOND])->where(['source_id' => $settlementId, 'status' => $status])->orderBy('journal_id', 'ASC')->get()->getResult() as $journal) {
            $found[(int) $journal->branch_id] = array_map(
                static fn (object $line): string => $line->account_id . ':' . bcadd((string) $line->debit, '0', 2) . ':' . bcadd((string) $line->credit, '0', 2),
                $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
            );
        }

        return $found;
    }

    private function assertBalancedInEachBranch(int $settlementId): void
    {
        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', [InterBranchSettlements::SOURCE, InterBranchSettlements::SOURCE_SECOND])->where('source_id', $settlementId)->get()->getResult() as $journal) {
            $lines = $this->accountingDb()->table('accounting_journal_lines')->select('SUM(debit) AS debit, SUM(credit) AS credit', false)->where('journal_id', $journal->journal_id)->get()->getRow();

            $this->assertSame(bcadd((string) $lines->debit, '0', 4), bcadd((string) $lines->credit, '0', 4), 'The journal of branch ' . $journal->branch_id . ' is balanced inside the branch');
        }
    }

    private function assertNothingOwedBetweenTheBranches(): void
    {
        foreach ($this->balances->report()->rows as $row) {
            $this->assertSame(['0.0000', '0.0000'], [$row->receivable, $row->payable], 'The pair of ' . $row->code . ' and ' . $row->counterpartCode . ' is clear');
        }

        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }

    public function testSettle_ClearsAPairByCashFromTheBranchThatOwesIntoTheTreasuryOfTheBranchThatIsOwed(): void
    {
        $this->send(true, '100.00');

        $this->assertSame('900.0000', $this->balanceOf($this->main));
        $this->assertSame('1100.0000', $this->balanceOf($this->other));

        $id = $this->settle($this->other, $this->main);

        $this->assertSame([
            1            => [$this->treasuries->accountOf($this->main) . ':100.00:0.00', $this->accountId('branches_receivable') . ':0.00:100.00'],
            $this->branch => [$this->accountId('branches_payable') . ':100.00:0.00', $this->treasuries->accountOf($this->other) . ':0.00:100.00'],
        ], $this->journalsOf($id));
        $this->assertBalancedInEachBranch($id);
        $this->assertSame('1000.0000', $this->balanceOf($this->main));
        $this->assertSame('1000.0000', $this->balanceOf($this->other));
        $this->assertNothingOwedBetweenTheBranches();

        $row = $this->settlements->find($id);

        $this->assertSame(['cash', '100.0000', $this->branch, 'completed'], [$row->kind, $row->amount, (int) $row->payer_branch_id, $row->status]);
        $this->assertSame([$this->other, $this->main], [(int) $row->from_treasury_id, (int) $row->to_treasury_id]);
        $this->assertSame(['100.0000', '0.0000', '0.0000', '100.0000'], [$row->first_receivable, $row->first_payable, $row->second_receivable, $row->second_payable]);
        $this->assertSame([1, $this->branch], [(int) $row->branch_id, (int) $row->counterpart_branch_id]);
    }

    public function testSettle_PaysFromTheLowerBranchWhenItIsTheOneThatOwes(): void
    {
        $this->send(false, '60.00');

        $id = $this->settle($this->main, $this->other);

        $this->assertSame([
            1            => [$this->accountId('branches_payable') . ':60.00:0.00', $this->treasuries->accountOf($this->main) . ':0.00:60.00'],
            $this->branch => [$this->treasuries->accountOf($this->other) . ':60.00:0.00', $this->accountId('branches_receivable') . ':0.00:60.00'],
        ], $this->journalsOf($id));
        $this->assertSame('1000.0000', $this->balanceOf($this->main));
        $this->assertSame('1000.0000', $this->balanceOf($this->other));
        $this->assertNothingOwedBetweenTheBranches();
    }

    public function testSettle_ClearsWhatEachOwesAndMovesOnlyTheDifferenceInCash(): void
    {
        $this->send(true, '100.00');
        $this->send(false, '30.00', '2026-10-22');

        $id = $this->settle($this->other, $this->main);

        $this->assertSame([
            1            => [$this->treasuries->accountOf($this->main) . ':70.00:0.00', $this->accountId('branches_payable') . ':30.00:0.00', $this->accountId('branches_receivable') . ':0.00:100.00'],
            $this->branch => [$this->accountId('branches_payable') . ':100.00:0.00', $this->accountId('branches_receivable') . ':0.00:30.00', $this->treasuries->accountOf($this->other) . ':0.00:70.00'],
        ], $this->journalsOf($id));
        $this->assertBalancedInEachBranch($id);
        $this->assertSame('70.0000', $this->settlements->find($id)->amount);
        $this->assertNothingOwedBetweenTheBranches();
        $this->assertSame(['1000.0000', '1000.0000'], [$this->balanceOf($this->main), $this->balanceOf($this->other)]);
    }

    public function testSettle_OffsetsBalancesThatCancelEachOtherAndMovesNoCash(): void
    {
        $this->send(true, '100.00');
        $this->send(false, '100.00', '2026-10-22');

        $before = [$this->balanceOf($this->main), $this->balanceOf($this->other)];
        $id     = $this->settle();

        $this->assertSame([
            1            => [$this->accountId('branches_payable') . ':100.00:0.00', $this->accountId('branches_receivable') . ':0.00:100.00'],
            $this->branch => [$this->accountId('branches_payable') . ':100.00:0.00', $this->accountId('branches_receivable') . ':0.00:100.00'],
        ], $this->journalsOf($id));
        $this->assertSame($before, [$this->balanceOf($this->main), $this->balanceOf($this->other)]);
        $this->assertSame(['netting', '0.0000', null], [$this->settlements->find($id)->kind, $this->settlements->find($id)->amount, $this->settlements->find($id)->payer_branch_id]);
        $this->assertNull($this->settlements->find($id)->from_treasury_id);
        $this->assertNothingOwedBetweenTheBranches();
    }

    public function testSettle_NeedsNoTreasuryToOffsetBalancesButIgnoresOnesThatAreGiven(): void
    {
        $this->send(true, '100.00');
        $this->send(false, '100.00', '2026-10-22');

        $id = $this->settle($this->main, $this->main);

        $this->assertNull($this->settlements->find($id)->to_treasury_id);
    }

    public function testSettle_WritesTheJournalOfTheLowerBranchFirstWhicheverBranchPays(): void
    {
        foreach ([[true, $this->other, $this->main], [false, $this->main, $this->other]] as [$fromFirst, $from, $to]) {
            $this->send($fromFirst, '10.00');

            $id  = $this->settle($from, $to);
            $ids = [];

            foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', [InterBranchSettlements::SOURCE, InterBranchSettlements::SOURCE_SECOND])->where('source_id', $id)->get()->getResult() as $journal) {
                $ids[(int) $journal->branch_id] = (int) $journal->journal_id;
            }

            $this->assertLessThan($ids[$this->branch], $ids[1]);
        }
    }

    public function testSettle_RecordsTheBranchOnTheOtherSideOfEachJournalAndMakesThemOnePair(): void
    {
        $this->send(true, '100.00');

        $id   = $this->settle($this->other, $this->main);
        $legs = $this->accountingDb()->table('accounting_interbranch_legs')->where(['document_type' => 'interbranch_settlement', 'document_id' => $id])->orderBy('journal_id')->get()->getResult();

        $this->assertCount(2, $legs);
        $this->assertSame([[1, 'receivable', $this->branch], [$this->branch, 'payable', 1]], array_map(
            fn (object $leg): array => [(int) $this->accountingDb()->table('accounting_journals')->where('journal_id', $leg->journal_id)->get()->getRow()->branch_id, $leg->side, (int) $leg->counterpart_branch_id],
            $legs,
        ));
    }

    public function testSettle_RefusesAnEmployeeWithoutThePermission(): void
    {
        $this->send(true, '100.00');

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->settle($this->other, $this->main, '2026-10-31', $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE])))->error);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testSettle_RefusesAnEmployeeWhoWorksInOnlyOneOfTheTwoBranches(): void
    {
        $this->send(true, '100.00');

        foreach ([1, $this->branch] as $branchId) {
            $refusal = $this->refusal(fn () => $this->settle($this->other, $this->main, '2026-10-31', $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE], [$branchId])));

            $this->assertInstanceOf(BranchException::class, $refusal);
            $this->assertSame(BranchError::NotAllowed, $refusal->error);
        }

        $this->assertSame(0, $this->countRows('interbranch_settlements'));
        $this->assertGreaterThan(0, $this->settle($this->other, $this->main, '2026-10-31', $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE], [1, $this->branch])));
    }

    public function testSettle_AsksTheWithdrawScopeOfTheTreasuryTheMoneyComesOutOf(): void
    {
        $this->send(true, '100.00');
        $this->treasuries->update($this->other, ['name' => 'Second branch drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]], $this->manager);

        $refusal = $this->refusal(fn () => $this->settle($this->other, $this->main));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
        $this->assertSame('1100.0000', $this->balanceOf($this->other));
    }

    public function testSettle_AsksTheDepositScopeOfTheTreasuryTheMoneyGoesInto(): void
    {
        $this->send(true, '100.00');
        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]], $this->manager);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->settle($this->other, $this->main))->error);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testSettle_DoesNotAskTheScopesOfTreasuriesInTheDirectionTheyDoNotMove(): void
    {
        $this->send(true, '100.00');

        $other = $this->employeeWithGrants([]);

        $this->treasuries->update($this->other, ['name' => 'Second branch drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);
        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);

        $this->assertGreaterThan(0, $this->settle($this->other, $this->main));
    }

    public function testSettle_LetsTheBooksSettleWhateverTheScopes(): void
    {
        $this->send(true, '100.00');
        $this->treasuries->update($this->other, ['name' => 'Second branch drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]], $this->manager);

        $id = $this->settlements->settle(1, $this->branch, '2026-10-31', $this->other, $this->main);

        $this->assertNull($this->settlements->find($id)->created_by);
        $this->assertNothingOwedBetweenTheBranches();
    }

    public function testSettle_RefusesTreasuriesThatAreNotThoseOfTheBranchesTheMoneyMovesBetween(): void
    {
        $this->send(true, '100.00');

        $drawer = $this->treasuries->create(['name' => 'Another drawer of the first branch', 'kind' => 'cash'], $this->manager);

        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->settle($this->main, $this->other))->error, 'Swapped');
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->settle($drawer, $this->main))->error, 'Both of the receiving branch');
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->settle(99999, $this->main))->error, 'One that does not exist');
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testSettle_RefusesACashSettlementWithoutBothTreasuries(): void
    {
        $this->send(true, '100.00');

        foreach ([[null, null], [$this->other, null], [null, $this->main], [0, 0]] as [$from, $to]) {
            $this->assertSame(AccountingError::SettlementTreasuryRequired, $this->refusal(fn () => $this->settle($from, $to))->error);
        }

        $this->assertSame(AccountingError::TransferSameTreasury, $this->refusal(fn () => $this->settle($this->main, $this->main))->error);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testSettle_RefusesATreasuryThatIsDeactivated(): void
    {
        $this->send(true, '100.00');

        $closed = $this->treasuries->create(['name' => 'Closed drawer', 'kind' => 'cash', 'branch_id' => $this->branch], $this->manager);

        $this->treasuries->deactivate($closed, $this->manager);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->settle($closed, $this->main))->error);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testSettle_RefusesWhenTheTwoBranchesDoNotSayTheSameOfEachOther(): void
    {
        $this->send(true, '100.00');

        $this->accountingDb()->table('accounting_interbranch_legs')->insert([
            'journal_id'            => (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer'])->get()->getRow()->journal_id,
            'account_id'            => $this->accountId('branches_receivable'),
            'company_id'            => 1,
            'side'                  => 'receivable',
            'counterpart_branch_id' => $this->createBranch('B3'),
            'document_type'         => 'interbranch_test',
            'document_id'           => 1,
            'revision'              => 1,
        ]);

        $refusal = $this->refusal(fn () => $this->settle($this->other, $this->main));

        $this->assertSame(AccountingError::SettlementPairNotAgreed, $refusal->error);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->whereIn('source_type', [InterBranchSettlements::SOURCE, InterBranchSettlements::SOURCE_SECOND])->countAllResults());
    }

    public function testSettle_RefusesWhenNothingIsOwedOnThatDay(): void
    {
        $this->assertSame(AccountingError::SettlementNothingToSettle, $this->refusal(fn () => $this->settle())->error);

        $this->send(true, '100.00', '2026-10-25');

        $this->assertSame(AccountingError::SettlementNothingToSettle, $this->refusal(fn () => $this->settle($this->other, $this->main, '2026-10-24'))->error, 'The transfer is dated after the settlement');
        $this->assertGreaterThan(0, $this->settle($this->other, $this->main, '2026-10-25'));
        $this->assertSame(AccountingError::SettlementNothingToSettle, $this->refusal(fn () => $this->settle($this->other, $this->main, '2026-10-26'))->error, 'Settled already');
    }

    public function testSettle_LeavesWhatIsDatedAfterTheDayOfTheSettlement(): void
    {
        $this->send(true, '100.00', '2026-10-21');
        $this->send(true, '40.00', '2026-11-05');

        $this->settle($this->other, $this->main, '2026-10-31');

        $row = $this->balances->report()->rows[0];

        $this->assertSame('40.0000', $row->owed);
        $this->assertSame('0.0000', $this->balances->report(null, '2026-10-31')->rows[0]->owed);
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }

    public function testSettle_RefusesTheSameBranchTwiceAndABranchThatDoesNotExist(): void
    {
        $this->assertSame(AccountingError::SettlementSameBranch, $this->refusal(fn () => $this->settlements->settle(1, 1, '2026-10-31', null, null, '', null, $this->settler))->error);

        $refusal = $this->refusal(fn () => $this->settlements->settle(1, 99999, '2026-10-31', null, null, '', null, $this->settler));

        $this->assertInstanceOf(BranchException::class, $refusal);
        $this->assertSame(BranchError::NotFound, $refusal->error);
    }

    public function testSettle_RefusesADateThatIsNotOne(): void
    {
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->settle(null, null, '2026-02-30'))->error);
    }

    public function testSettle_TakesTheBranchesInEitherOrder(): void
    {
        $this->send(true, '100.00');

        $id = $this->settlements->settle($this->branch, 1, '2026-10-31', $this->other, $this->main, '', null, $this->settler);

        $this->assertSame([1, $this->branch], [(int) $this->settlements->find($id)->branch_id, (int) $this->settlements->find($id)->counterpart_branch_id]);
    }

    public function testSettle_LeavesNothingBehindWhenThePeriodOfEitherBranchIsClosed(): void
    {
        $this->send(true, '100.00');

        foreach ([1, $this->branch] as $closed) {
            $this->closeThrough('2026-10-31', $closed);

            $journals = $this->countRows('accounting_journals');
            $refusal  = $this->refusal(fn () => $this->settle($this->other, $this->main));

            $this->assertSame(PostingError::PeriodClosed, $refusal->error);
            $this->assertSame(0, $this->countRows('interbranch_settlements'));
            $this->assertSame($journals, $this->countRows('accounting_journals'), 'The journal of the other branch went with it');
            $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
            $this->assertSame('1100.0000', $this->balanceOf($this->other));

            $this->accountingDb()->table('accounting_closed_periods')->truncate();
        }

        $this->assertGreaterThan(0, $this->settle($this->other, $this->main, '2026-11-02'));
    }

    public function testSettle_LeavesNothingBehindWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        $this->send(true, '100.00');
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.payable')->update(['account_id' => null]);

        $this->assertSame(AccountingError::InterBranchAccountsMissing, $this->refusal(fn () => $this->settle($this->other, $this->main))->error);
        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testSettle_ReturnsTheSettlementOfThePreviousRequestWhenAskedAgainWithTheSameKey(): void
    {
        $this->send(true, '100.00');

        $first = $this->settle($this->other, $this->main, '2026-10-31', null, '', 'form-1');
        $again = $this->settle($this->other, $this->main, '2026-10-31', null, '', 'form-1');

        $this->assertSame($first, $again);
        $this->assertSame(1, $this->countRows('interbranch_settlements'));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->whereIn('source_type', [InterBranchSettlements::SOURCE, InterBranchSettlements::SOURCE_SECOND])->countAllResults());
        $this->assertSame('1000.0000', $this->balanceOf($this->other));
    }

    public function testSettle_TakesADifferentKeyForADifferentSettlement(): void
    {
        $this->send(true, '100.00');
        $first = $this->settle($this->other, $this->main, '2026-10-31', null, '', 'form-1');

        $this->send(true, '10.00', '2026-11-01');

        $second = $this->settle($this->other, $this->main, '2026-11-02', null, '', 'form-2');

        $this->assertNotSame($first, $second);
    }

    public function testSettle_NeverPostsTheSameJournalTwice(): void
    {
        $this->send(true, '100.00');
        $id = $this->settle($this->other, $this->main);

        $draftSource = $this->accountingDb()->table('accounting_journals')->where(['source_type' => InterBranchSettlements::SOURCE, 'source_id' => $id])->get()->getRow();
        $existing    = (new PostingService())->post(new JournalDraft(
            date: '2026-10-31',
            lines: [new JournalLineDraft($this->accountId('branches_receivable'), debit: '1.00'), new JournalLineDraft($this->accountId('branches_payable'), credit: '1.00')],
            sourceType: InterBranchSettlements::SOURCE,
            sourceId: $id,
            branchId: 1,
        ));

        $this->assertFalse($existing->created);
        $this->assertSame((int) $draftSource->journal_id, $existing->journalId);
    }

    public function testSettle_WritesOneAuditEntryForEachBranch(): void
    {
        $this->send(true, '100.00');
        $id = $this->settle($this->other, $this->main);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'interbranch_settlement', 'entity_id' => $id, 'action' => 'created'])->orderBy('branch_id')->get()->getResult();

        $this->assertSame([1, $this->branch], array_map(static fn (object $entry): int => (int) $entry->branch_id, $entries));
        $this->assertSame($this->settler, (int) $entries[0]->employee_id);
        $this->assertSame('cash', json_decode($entries[0]->details, true)['kind']);
    }

    public function testSettle_WaitsForAnotherSettlementOfTheSamePair(): void
    {
        $this->send(true, '100.00');

        $other = Database::connect('tests', false);

        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other->resetTransStatus();
        $other->transStart();
        $other->query('INSERT IGNORE INTO ' . $other->prefixTable('interbranch_pair_locks') . ' (company_id, branch_id, counterpart_branch_id) VALUES (1, 1, ' . $this->branch . ')');
        $other->query('SELECT branch_id FROM ' . $other->prefixTable('interbranch_pair_locks') . ' WHERE company_id = 1 AND branch_id = 1 AND counterpart_branch_id = ' . $this->branch . ' FOR UPDATE');

        try {
            $this->assertWaitsForLock(fn () => $this->settle($this->other, $this->main));
        } finally {
            $other->transRollback();
            $other->close();
            $this->releaseOpenTransactions();
        }

        $this->assertSame(0, $this->countRows('interbranch_settlements'));
    }

    public function testPlan_SaysWhatSettlingWouldDoAndWritesNothing(): void
    {
        $this->send(true, '100.00');
        $this->send(false, '30.00', '2026-10-22');

        $journals = $this->countRows('accounting_journals');
        $plan     = $this->settlements->plan($this->branch, 1, '2026-10-31', $this->settler);

        $this->assertSame([1, $this->branch, SettlementPlan::CASH, '70.0000', $this->branch, 1, true], [$plan->firstBranchId, $plan->secondBranchId, $plan->kind, $plan->amount, $plan->payerBranchId, $plan->payeeBranchId, $plan->canBeSettled()]);
        $this->assertSame(['100.0000', '30.0000', '30.0000', '100.0000'], [$plan->firstReceivable, $plan->firstPayable, $plan->secondReceivable, $plan->secondPayable]);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertNull($this->settlements->plan(1, $this->branch, '2026-10-01', $this->settler)->kind);
    }

    public function testPlan_RefusesAnEmployeeWhoWorksInOnlyOneOfTheBranches(): void
    {
        $refusal = $this->refusal(fn () => $this->settlements->plan(1, $this->branch, '2026-10-31', $this->employeeWithGrants([], [1])));

        $this->assertInstanceOf(BranchException::class, $refusal);
    }

    public function testCancel_ReversesTheJournalsOfBothBranchesAndRestoresTheBalances(): void
    {
        $this->send(true, '100.00');

        $id = $this->settle($this->other, $this->main);

        $this->settlements->cancel($id, 'Entered by mistake', $this->settler);

        $this->assertSame([], $this->journalsOf($id));
        $this->assertSame([1, $this->branch], array_keys($this->journalsOf($id, 'reversed')));
        $this->assertSame('900.0000', $this->balanceOf($this->main));
        $this->assertSame('1100.0000', $this->balanceOf($this->other));
        $this->assertSame('100.0000', $this->balances->report()->rows[0]->owed);
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());

        $row = $this->settlements->find($id);

        $this->assertSame(['cancelled', 'Entered by mistake', $this->settler], [$row->status, $row->cancel_reason, (int) $row->cancelled_by]);

        $reversals = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->orderBy('journal_id', 'ASC')->get()->getResult();

        $this->assertSame([1, $this->branch], array_map(static fn (object $journal): int => (int) $journal->branch_id, array_slice($reversals, -2)));
    }

    public function testCancel_ReversesTheJournalOfTheLowerBranchFirstWhicheverBranchPaid(): void
    {
        foreach ([[true, $this->other, $this->main], [false, $this->main, $this->other]] as [$fromFirst, $from, $to]) {
            $this->send($fromFirst, '10.00');

            $id = $this->settle($from, $to);

            $this->settlements->cancel($id, 'Reason', $this->settler);

            $reversed = [];

            foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', [InterBranchSettlements::SOURCE, InterBranchSettlements::SOURCE_SECOND])->where('source_id', $id)->get()->getResult() as $original) {
                $reversed[(int) $original->branch_id] = (int) $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $original->journal_id)->get()->getRow()->journal_id;
            }

            $this->assertLessThan($reversed[$this->branch], $reversed[1]);

            $this->settlements->settle(1, $this->branch, '2026-11-30', $from, $to, '', null, $this->settler);
        }
    }

    public function testCancel_ReversesOnTheDayItIsDoneWhenThePeriodOfASettlementHasBeenClosedSince(): void
    {
        $this->send(true, '100.00', '2026-10-20');

        $id = $this->settle($this->other, $this->main, '2026-10-21');

        $this->closeThrough('2026-10-22', $this->branch);
        $this->settlements->cancel($id, 'Reason', $this->settler);

        $reversals = array_slice($this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->orderBy('journal_id', 'ASC')->get()->getResult(), -2);

        $this->assertSame(['2026-10-21', date('Y-m-d')], [$reversals[0]->journal_date, $reversals[1]->journal_date], 'Each on its own day: the branch that is closed on the day it was done');
        $this->assertSame('900.0000', $this->balanceOf($this->main));
    }

    public function testCancel_RefusesAnEmployeeWithoutThePermissionOrWhoWorksInOnlyOneBranchAndReversesNothing(): void
    {
        $this->send(true, '100.00');

        $id = $this->settle($this->other, $this->main);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->settlements->cancel($id, 'Reason', $this->employeeWithGrants([])))->error);

        foreach ([1, $this->branch] as $branchId) {
            $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->settlements->cancel($id, 'Reason', $this->employeeWithGrants([AccountingGuard::INTERBRANCH_SETTLE], [$branchId]))));
        }

        $this->assertSame(['completed'], [$this->settlements->find($id)->status]);
        $this->assertSame([1, $this->branch], array_keys($this->journalsOf($id)));
    }

    public function testCancel_AsksTheScopesOfBothTreasuriesAndReversesNothingWhenOneIsClosed(): void
    {
        $this->send(true, '100.00');

        $id = $this->settle($this->other, $this->main);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]], $this->manager);

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->settlements->cancel($id, 'Reason', $this->settler))->error);
        $this->assertSame([1, $this->branch], array_keys($this->journalsOf($id)), 'Neither journal was reversed');
        $this->assertSame('1000.0000', $this->balanceOf($this->other));
    }

    public function testCancel_AsksTheDepositScopeOfTheTreasuryTheMoneyCameOutOf(): void
    {
        $this->send(true, '100.00');

        $id = $this->settle($this->other, $this->main);

        $this->treasuries->update($this->other, ['name' => 'Second branch drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]], $this->manager);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->settlements->cancel($id, 'Reason', $this->settler))->error);
        $this->assertSame([1, $this->branch], array_keys($this->journalsOf($id)));
    }

    public function testCancel_RefusesASettlementThatIsGoneAlreadyCancelledOrWithoutAReason(): void
    {
        $this->send(true, '100.00');

        $id = $this->settle($this->other, $this->main);

        $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->settlements->cancel($id, '  ', $this->settler))->error);
        $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->settlements->cancel($id, str_repeat('x', 256), $this->settler))->error);
        $this->assertSame(AccountingError::SettlementNotFound, $this->refusal(fn () => $this->settlements->cancel(99999, 'Reason', $this->settler))->error);

        $this->settlements->cancel($id, 'Reason', $this->settler);

        $this->assertSame(AccountingError::SettlementAlreadyCancelled, $this->refusal(fn () => $this->settlements->cancel($id, 'Again', $this->settler))->error);
        $this->assertSame('900.0000', $this->balanceOf($this->main), 'The second cancellation reversed nothing more');
    }

    public function testCancel_LetsThePairBeSettledAgain(): void
    {
        $this->send(true, '100.00');

        $first = $this->settle($this->other, $this->main);

        $this->settlements->cancel($first, 'Reason', $this->settler);

        $second = $this->settle($this->other, $this->main);

        $this->assertNotSame($first, $second);
        $this->assertNothingOwedBetweenTheBranches();
        $this->assertSame('1000.0000', $this->balanceOf($this->main));
    }

    public function testCancel_WaitsForAnotherSettlementOfTheSamePair(): void
    {
        $this->send(true, '100.00');

        $id    = $this->settle($this->other, $this->main);
        $other = Database::connect('tests', false);

        $other->query('SET SESSION innodb_lock_wait_timeout = 1');
        $this->accountingDb()->query('SET SESSION innodb_lock_wait_timeout = 1');
        $other->resetTransStatus();
        $other->transStart();
        $other->query('SELECT branch_id FROM ' . $other->prefixTable('interbranch_pair_locks') . ' WHERE company_id = 1 AND branch_id = 1 AND counterpart_branch_id = ' . $this->branch . ' FOR UPDATE');

        try {
            $this->assertWaitsForLock(fn () => $this->settlements->cancel($id, 'Reason', $this->settler));
        } finally {
            $other->transRollback();
            $other->close();
            $this->releaseOpenTransactions();
        }

        $this->assertSame('completed', $this->settlements->find($id)->status);
        $this->assertSame([1, $this->branch], array_keys($this->journalsOf($id)));
    }

    public function testCancel_WritesOneAuditEntryForEachBranch(): void
    {
        $this->send(true, '100.00');

        $id = $this->settle($this->other, $this->main);

        $this->settlements->cancel($id, 'Reason', $this->settler);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'interbranch_settlement', 'entity_id' => $id, 'action' => 'cancelled'])->orderBy('branch_id')->get()->getResult();

        $this->assertSame([1, $this->branch], array_map(static fn (object $entry): int => (int) $entry->branch_id, $entries));
        $this->assertSame('Reason', json_decode($entries[0]->details, true)['reason']);
    }

    public function testReverse_RefusesToTakeBackOneJournalOfASettlementByHand(): void
    {
        $this->send(true, '100.00');

        $id      = $this->settle($this->other, $this->main);
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => InterBranchSettlements::SOURCE, 'source_id' => $id])->get()->getRow();
        $editor  = $this->employeeWithGrants([AccountingGuard::JOURNALS_REVERSE, AccountingGuard::JOURNALS_ALL]);

        $refusal = $this->refusal(fn () => (new JournalService())->reverse((int) $journal->journal_id, '2026-10-31', 'By hand', $editor));

        $this->assertSame(AccountingError::TransferJournalLocked, $refusal->error);
        $this->assertSame([1, $this->branch], array_keys($this->journalsOf($id)));
    }

    public function testSearch_ListsASettlementToTheEmployeesOfEitherBranchAndToNoOneElse(): void
    {
        $third = $this->createBranch('B3');

        $this->send(true, '100.00');

        $id = $this->settle($this->other, $this->main);

        foreach ([[1], [$this->branch], [1, $third], null] as $seen) {
            $this->assertContains($id, array_map(static fn (object $row): int => (int) $row->settlement_id, $this->settlements->search($seen)['rows']));
        }

        $this->assertSame(0, $this->settlements->search([$third])['total']);
        $this->assertSame(['total' => 0, 'rows' => []], $this->settlements->search([]));
        $this->assertSame(['HQ', 'B2'], [$this->settlements->find($id)->branch_code, $this->settlements->find($id)->counterpart_code]);
        $this->assertSame([1, $this->branch], InterBranchSettlements::branchesOf($this->settlements->find($id)));
    }

    public function testSettle_KeepsTheTrialBalanceOfEveryBranchBalancedAndTheCompanyCheckAtZero(): void
    {
        $this->send(true, '100.00');
        $this->send(false, '30.00', '2026-10-22');
        $this->settle($this->other, $this->main);

        foreach ([1, $this->branch] as $branchId) {
            $sums = $this->accountingDb()->table('accounting_journal_lines l')->select('SUM(l.debit) AS debit, SUM(l.credit) AS credit', false)->join('accounting_journals j', 'j.journal_id = l.journal_id')->where('j.branch_id', $branchId)->get()->getRow();

            $this->assertSame(bcadd((string) $sums->debit, '0', 4), bcadd((string) $sums->credit, '0', 4));
        }

        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }
}
