<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Accounting\TreasuryTransfers;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Money moved from one treasury to another: a journal that debits the treasury it goes into and
 * credits the one it comes out of, within one branch, taken back by reversing it.
 */
class TreasuryTransfersTest extends CIUnitTestCase
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

    private TreasuryService $treasuries;
    private TreasuryTransfers $transfers;
    private int $manager;
    private int $main;
    private int $drawer;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->treasuries = new TreasuryService();
        $this->transfers  = new TreasuryTransfers();
        $this->manager    = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);
        $this->main       = $this->treasuries->ensureMain();
        $this->drawer     = $this->treasuries->create(['name' => 'Drawer', 'kind' => 'cash'], $this->manager);

        $this->putInMain('500.00');
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    private function putInMain(string $amount): void
    {
        (new PostingService())->post(new JournalDraft(
            date: '2026-10-20',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($this->main), debit: $amount), new JournalLineDraft($this->accountId('opening_balance'), credit: $amount)],
            sourceType: 'opening',
            sourceId: random_int(1000, 9999),
            currencyCode: 'SAR',
        ));
    }

    private function balanceOf(int $treasuryId): string
    {
        return $this->treasuries->position($treasuryId)->balance;
    }

    private function refusal(callable $action): AccountingException|PostingException
    {
        try {
            $action();
        } catch (AccountingException|PostingException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    /**
     * @return list<array{string, string}>
     */
    private function linesOf(int $transferId): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $transferId, 'status' => 'posted'])->get()->getRow();

        return array_map(
            static fn (object $line): array => [(string) $line->account_id, bcadd((string) $line->debit, '0', 2) . ':' . bcadd((string) $line->credit, '0', 2)],
            $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
        );
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceTransfers(): array
    {
        return ParityFixture::cases('T-22', 'treasury_transfer');
    }

    /**
     * Brings a treasury to the balance the case starts from, a negative one by drawing on it.
     */
    private function startAt(int $treasuryId, string $balance): void
    {
        if (bccomp($balance, '0', 2) > 0) {
            $this->transfers->transfer($this->main, $treasuryId, $balance, '2026-10-20');
        } elseif (bccomp($balance, '0', 2) < 0) {
            $this->transfers->transfer($treasuryId, $this->main, ltrim($balance, '-'), '2026-10-20');
        }
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceTransfers')]
    public function testTransfer_MovesMoneyAsTheReferenceSystemDoes(array $case): void
    {
        $bank = $this->treasuries->create(['name' => 'Fixture bank', 'kind' => 'bank', 'bank_name' => 'Riyad Bank', 'account_number' => '3500000001'], $this->manager);

        $this->startAt($this->drawer, $case['inputs']['from_balance']);
        $this->startAt($bank, $case['inputs']['to_balance']);

        $transferId = $this->transfers->transfer($this->drawer, $bank, $case['inputs']['amount'], '2026-10-21', '', $this->manager);
        $journal    = $this->accountingDb()->table('accounting_journals')->where(['source_type' => TreasuryTransfers::SOURCE, 'source_id' => $transferId])->get()->getRow();
        $lines      = $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult();

        $this->assertSame((int) $this->treasuries->accountOf($bank), (int) $lines[0]->account_id, 'The receiver is debited');
        $this->assertSame((int) $this->treasuries->accountOf($this->drawer), (int) $lines[1]->account_id, 'The sender is credited');

        $outcome = ['from_balance' => bcadd($this->balanceOf($this->drawer), '0', 2), 'to_balance' => bcadd($this->balanceOf($bank), '0', 2)];

        foreach (['from_balance', 'to_balance'] as $key) {
            if (array_key_exists($key, $case['expected'])) {
                $this->assertSame($case['expected'][$key], $outcome[$key], $key);
            }
        }

        $this->assertSame($case['reference'], $case['expected'], 'A case that is exact has the same outcome as the reference system');
    }

    public function testTransfer_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasuryItComesOutOf(): void
    {
        $other = $this->employeeWithGrants([]);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);

        $refusal = $this->refusal(fn () => $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('500.0000', $this->balanceOf($this->main));
        $this->assertSame('0.0000', $this->balanceOf($this->drawer));
        $this->assertSame(0, $this->countRows('treasury_transfers'));
    }

    public function testTransfer_RefusesAnEmployeeWhoMayNotDepositIntoTheTreasuryItGoesInto(): void
    {
        $other = $this->employeeWithGrants([]);

        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);

        $refusal = $this->refusal(fn () => $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame('Drawer', $refusal->context['name']);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
    }

    public function testTransfer_AcceptsAnEmployeeWhoIsInTheScopeOfBothTreasuries(): void
    {
        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->manager]], $this->manager);
        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->manager]], $this->manager);

        $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->assertSame('40.0000', $this->balanceOf($this->drawer));
    }

    public function testTransfer_DoesNotNeedTheScopeOfTheTreasuryItDoesNotTouchInThatDirection(): void
    {
        $other = $this->employeeWithGrants([]);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);
        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);

        $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->assertSame('40.0000', $this->balanceOf($this->drawer), 'Main was only drawn on and the drawer only paid into');
    }

    public function testTransfer_LetsTheBooksMoveMoneyWhateverTheScopes(): void
    {
        $other = $this->employeeWithGrants([]);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);
        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);

        $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21');

        $this->assertSame('40.0000', $this->balanceOf($this->drawer));
    }

    public function testCancel_NeedsOnlyThePermissionToManageWhateverTheScopes(): void
    {
        $other      = $this->employeeWithGrants([]);
        $transferId = $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);
        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);

        $this->transfers->cancel($transferId, 'entered twice', $this->manager);

        $this->assertSame('0.0000', $this->balanceOf($this->drawer));
    }

    public function testTransfer_DebitsTheTreasuryItGoesIntoAndCreditsTheOneItComesOutOf(): void
    {
        $id = $this->transfers->transfer($this->main, $this->drawer, '120.50', '2026-10-21', 'Float for the day', $this->manager);

        $this->assertSame([
            [(string) $this->treasuries->accountOf($this->drawer), '120.50:0.00'],
            [(string) $this->treasuries->accountOf($this->main), '0.00:120.50'],
        ], $this->linesOf($id));
        $this->assertSame('379.5000', $this->balanceOf($this->main));
        $this->assertSame('120.5000', $this->balanceOf($this->drawer));

        $row = $this->transfers->find($id);

        $this->assertSame('completed', $row->status);
        $this->assertSame('120.5000', $row->amount);
        $this->assertSame('Float for the day', $row->note);
        $this->assertSame(1, (int) $row->branch_id);
        $this->assertSame('Drawer', $row->to_name);
    }

    public function testTransfer_AcceptsTheWholeBalanceAndMoreThanTheTreasuryHolds(): void
    {
        $this->transfers->transfer($this->main, $this->drawer, '500.00', '2026-10-21', '', $this->manager);
        $this->transfers->transfer($this->drawer, $this->main, '700.00', '2026-10-21', '', $this->manager);

        $this->assertSame('700.0000', $this->balanceOf($this->main));
        $this->assertSame('-200.0000', $this->balanceOf($this->drawer), 'Nothing in the reference system stops a treasury from going below zero');
    }

    /**
     * @return array<string, array{string, AccountingError}>
     */
    public static function invalidAmounts(): array
    {
        return [
            'zero'              => ['0', AccountingError::TransferAmountInvalid],
            'negative'          => ['-5', AccountingError::TransferAmountInvalid],
            'not a number'      => ['ten', AccountingError::TransferAmountInvalid],
            'five decimals'     => ['1.00001', AccountingError::TransferAmountInvalid],
            'an empty amount'   => ['', AccountingError::TransferAmountInvalid],
        ];
    }

    #[DataProvider('invalidAmounts')]
    public function testTransfer_RefusesAnAmountThatIsNotMoreThanZero(string $amount, AccountingError $error): void
    {
        $this->assertSame($error, $this->refusal(fn () => $this->transfers->transfer($this->main, $this->drawer, $amount, '2026-10-21', '', $this->manager))->error);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
    }

    public function testTransfer_RefusesTheSameTreasuryAndADateThatIsNotOne(): void
    {
        $this->assertSame(AccountingError::TransferSameTreasury, $this->refusal(fn () => $this->transfers->transfer($this->main, $this->main, '1', '2026-10-21', '', $this->manager))->error);
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->transfers->transfer($this->main, $this->drawer, '1', '2026-02-30', '', $this->manager))->error);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
    }

    public function testTransfer_RefusesATreasuryThatDoesNotExistOrIsDeactivated(): void
    {
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->transfers->transfer($this->main, 99999, '1', '2026-10-21', '', $this->manager))->error);

        $this->treasuries->deactivate($this->drawer, $this->manager);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->transfers->transfer($this->main, $this->drawer, '1', '2026-10-21', '', $this->manager))->error);
    }

    public function testTransfer_RefusesTreasuriesOfDifferentBranches(): void
    {
        $other = $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $this->createBranch('B2')], $this->manager);

        $this->assertSame(AccountingError::TransferCrossBranch, $this->refusal(fn () => $this->transfers->transfer($this->main, $other, '1', '2026-10-21', '', $this->manager))->error);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
    }

    public function testTransfer_RefusesAnEmployeeWithoutThePermissionOrOfAnotherBranch(): void
    {
        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->transfers->transfer($this->main, $this->drawer, '1', '2026-10-21', '', $this->employeeWithGrants([])))->error);

        $other = $this->createBranch('B2');
        $local = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE], [$other]);

        $this->expectException(BranchException::class);

        $this->transfers->transfer($this->main, $this->drawer, '1', '2026-10-21', '', $local);
    }

    public function testTransfer_LeavesNothingBehindWhenThePeriodIsClosed(): void
    {
        $this->closeThrough('2026-10-31');

        $refusal = $this->refusal(fn () => $this->transfers->transfer($this->main, $this->drawer, '10', '2026-10-21', '', $this->manager));

        $this->assertSame(PostingError::PeriodClosed, $refusal->error);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
        $this->assertSame('500.0000', $this->balanceOf($this->main));
    }

    public function testCancel_ReversesTheJournalAndKeepsTheTransferWithItsReason(): void
    {
        $id = $this->transfers->transfer($this->main, $this->drawer, '80.00', '2026-10-21', '', $this->manager);

        $this->transfers->cancel($id, 'Wrong drawer', $this->manager);

        $row = $this->transfers->find($id);

        $this->assertSame('cancelled', $row->status);
        $this->assertSame('Wrong drawer', $row->cancel_reason);
        $this->assertSame($this->manager, (int) $row->cancelled_by);
        $this->assertSame('500.0000', $this->balanceOf($this->main));
        $this->assertSame('0.0000', $this->balanceOf($this->drawer));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $id, 'status' => 'reversed'])->countAllResults());
    }

    public function testCancel_RefusesATransferAlreadyCancelledOneThatDoesNotExistAndNoReason(): void
    {
        $id = $this->transfers->transfer($this->main, $this->drawer, '80.00', '2026-10-21', '', $this->manager);

        $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->transfers->cancel($id, '  ', $this->manager))->error);

        $this->transfers->cancel($id, 'Wrong drawer', $this->manager);

        $this->assertSame(AccountingError::TransferAlreadyCancelled, $this->refusal(fn () => $this->transfers->cancel($id, 'Again', $this->manager))->error);
        $this->assertSame(AccountingError::TransferNotFound, $this->refusal(fn () => $this->transfers->cancel(99999, 'Gone', $this->manager))->error);
        $this->assertSame('500.0000', $this->balanceOf($this->main), 'The second cancellation reversed nothing more');
    }

    public function testCancel_ReversesTodayWhenThePeriodOfTheTransferHasBeenClosedSince(): void
    {
        $id = $this->transfers->transfer($this->main, $this->drawer, '80.00', '2026-10-21', '', $this->manager);

        $this->closeThrough('2026-10-22');
        $this->transfers->cancel($id, 'Wrong drawer', $this->manager);

        $reversal = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id IS NOT NULL', null, false)->get()->getRow();

        $this->assertSame(date('Y-m-d'), $reversal->journal_date);
        $this->assertSame('500.0000', $this->balanceOf($this->main));
    }

    public function testCancel_RefusesAnEmployeeWithoutThePermission(): void
    {
        $id = $this->transfers->transfer($this->main, $this->drawer, '80.00', '2026-10-21', '', $this->manager);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->transfers->cancel($id, 'No', $this->employeeWithGrants([])))->error);
        $this->assertSame('completed', $this->transfers->find($id)->status);
    }

    public function testTransfer_KeepsOppositeTransfersBetweenTheSamePairTidy(): void
    {
        $this->transfers->transfer($this->main, $this->drawer, '100.00', '2026-10-21', '', $this->manager);
        $this->transfers->transfer($this->drawer, $this->main, '40.00', '2026-10-21', '', $this->manager);
        $this->transfers->transfer($this->main, $this->drawer, '5.25', '2026-10-22', '', $this->manager);

        $this->assertSame('434.7500', $this->balanceOf($this->main));
        $this->assertSame('65.2500', $this->balanceOf($this->drawer));
        $this->assertSame('500.0000', bcadd($this->balanceOf($this->main), $this->balanceOf($this->drawer), 4));
    }

    public function testSearch_ListsTheNewestFirstAndOnlyTheBranchesGiven(): void
    {
        $first  = $this->transfers->transfer($this->main, $this->drawer, '1', '2026-10-21', '', $this->manager);
        $second = $this->transfers->transfer($this->drawer, $this->main, '1', '2026-10-21', '', $this->manager);

        $all = $this->transfers->search();

        $this->assertSame(2, $all['total']);
        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->transfer_id, $all['rows']));
        $this->assertSame(['total' => 0, 'rows' => []], $this->transfers->search([]));
        $this->assertSame(0, $this->transfers->search([$this->createBranch('B2')])['total']);
    }

    public function testMovements_ShowTheBalanceAfterEachMovementAndWhichOneTookItBelowZero(): void
    {
        $this->transfers->transfer($this->main, $this->drawer, '200.00', '2026-10-21', '', $this->manager);
        $second = $this->transfers->transfer($this->drawer, $this->main, '300.00', '2026-10-22', '', $this->manager);

        $movements = $this->treasuries->movements($this->drawer);

        $this->assertSame(['200.0000', '-100.0000'], array_column($movements['rows'], 'balance'));
        $this->assertSame([false, true], array_column($movements['rows'], 'crossed'));
        $this->assertCount(1, $movements['crossings']);
        $this->assertSame(['treasury_transfer', $second], [$movements['crossings'][0]['source_type'], (int) $movements['crossings'][0]['source_id']]);
        $this->assertSame('-100.0000', $movements['lowest']);
        $this->assertSame('-100.0000', $this->balanceOf($this->drawer));
    }

    public function testMovements_CountTheReversalOfATransferAndItsOriginalBoth(): void
    {
        $transferId = $this->transfers->transfer($this->main, $this->drawer, '200.00', '2026-10-21', '', $this->manager);
        $this->transfers->cancel($transferId, 'wrong drawer', $this->manager);

        $rows = $this->treasuries->movements($this->drawer)['rows'];

        $this->assertSame(['200.0000', '0.0000'], array_column($rows, 'balance'));
        $this->assertNull($rows[0]['reverses_journal_id']);
        $this->assertNotNull($rows[1]['reverses_journal_id']);
    }

    public function testMovements_AreEmptyForATreasuryNothingWasPostedTo(): void
    {
        $this->assertSame(['rows' => [], 'crossings' => [], 'lowest' => '0.0000'], $this->treasuries->movements($this->drawer));
    }
}
