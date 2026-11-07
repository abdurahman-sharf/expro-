<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\InterBranchPosition;
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
use Tests\Support\ParityCaseTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * Money moved from one treasury to another: a journal that debits the treasury it goes into and
 * credits the one it comes out of, in one branch or, between two branches, a journal in each that
 * meet in the accounts between branches, taken back by reversing them.
 */
class TreasuryTransfersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
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

    public function testCancel_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasuryTheTransferWentInto(): void
    {
        $other      = $this->employeeWithGrants([]);
        $transferId = $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);

        $refusal = $this->refusal(fn () => $this->transfers->cancel($transferId, 'entered twice', $this->manager));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('Drawer', $refusal->context['name']);
        $this->assertSame('40.0000', $this->balanceOf($this->drawer), 'The money the cancellation would have taken out is still there');
        $this->assertSame('completed', $this->accountingDb()->table('treasury_transfers')->where('transfer_id', $transferId)->get()->getRow()->status);
    }

    public function testCancel_RefusesAnEmployeeWhoMayNotDepositIntoTheTreasuryTheTransferCameOutOf(): void
    {
        $other      = $this->employeeWithGrants([]);
        $transferId = $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->transfers->cancel($transferId, 'entered twice', $this->manager))->error);
        $this->assertSame('460.0000', $this->balanceOf($this->main));
    }

    public function testCancel_AcceptsAnEmployeeWhoMayWithdrawFromTheOneItWentIntoAndDepositIntoTheOneItCameOutOf(): void
    {
        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->manager]], $this->manager);
        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->manager]], $this->manager);

        $transferId = $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->transfers->cancel($transferId, 'entered twice', $this->manager);

        $this->assertSame('0.0000', $this->balanceOf($this->drawer));
        $this->assertSame('500.0000', $this->balanceOf($this->main));
    }

    public function testCancel_LetsTheBooksTakeATransferBackWhateverTheScopes(): void
    {
        $other      = $this->employeeWithGrants([]);
        $transferId = $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);
        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);

        $this->transfers->cancel($transferId, 'entered twice');

        $this->assertSame('0.0000', $this->balanceOf($this->drawer));
    }

    public function testCancel_DoesNotLetTheScopeOfTheTreasuryTheMoneyLeftStopTheOneThatNeverMovedMoney(): void
    {
        $other      = $this->employeeWithGrants([]);
        $transferId = $this->transfers->transfer($this->main, $this->drawer, '40.00', '2026-10-21', '', $this->manager);

        $this->treasuries->update($this->main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->manager);
        $this->treasuries->update($this->drawer, ['name' => 'Drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->manager);

        $this->transfers->cancel($transferId, 'entered twice', $this->manager);

        $this->assertSame('0.0000', $this->balanceOf($this->drawer), 'Taking back a transfer needs the opposite scopes, not the ones the transfer needed');
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

    /**
     * A treasury of a second branch, and the branch.
     *
     * @return array{int, int}
     */
    private function elsewhere(string $scopes = ''): array
    {
        $branch   = $this->createBranch('B2');
        $treasury = $this->treasuries->create(['name' => 'Elsewhere', 'kind' => 'cash', 'branch_id' => $branch] + ($scopes === 'deposit-closed' ? ['deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]] : []) + ($scopes === 'withdraw-closed' ? ['withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]] : []), $this->manager);

        return [$treasury, $branch];
    }

    /**
     * The standing journals of a transfer with their branch and lines as "account:debit:credit".
     *
     * @return array<string, array{branch: int, lines: list<string>}>
     */
    private function journalsOf(int $transferId, string $status = 'posted'): array
    {
        $found = [];

        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['treasury_transfer', 'treasury_transfer_receipt'])->where(['source_id' => $transferId, 'status' => $status])->orderBy('journal_id', 'ASC')->get()->getResult() as $journal) {
            $found[$journal->source_type] = [
                'branch' => (int) $journal->branch_id,
                'lines'  => array_map(
                    static fn (object $line): string => $line->account_id . ':' . bcadd((string) $line->debit, '0', 2) . ':' . bcadd((string) $line->credit, '0', 2),
                    $this->accountingDb()->table('accounting_journal_lines')->select('account_id, debit, credit')->where('journal_id', $journal->journal_id)->orderBy('line_no', 'ASC')->get()->getResult(),
                ),
            ];
        }

        return $found;
    }

    public function testTransfer_PostsAJournalInEachBranchThatMeetInTheAccountsBetweenBranches(): void
    {
        [$other, $branch] = $this->elsewhere();

        $id = $this->transfers->transfer($this->main, $other, '100.00', '2026-10-21', 'to the second branch', $this->manager);

        $journals = $this->journalsOf($id);

        $this->assertSame([
            'treasury_transfer'         => ['branch' => 1, 'lines' => [$this->accountId('branches_receivable') . ':100.00:0.00', $this->treasuries->accountOf($this->main) . ':0.00:100.00']],
            'treasury_transfer_receipt' => ['branch' => $branch, 'lines' => [$this->treasuries->accountOf($other) . ':100.00:0.00', $this->accountId('branches_payable') . ':0.00:100.00']],
        ], $journals);
        $this->assertSame('400.0000', $this->balanceOf($this->main));
        $this->assertSame('100.0000', $this->balanceOf($other));
        $this->assertSame((string) $branch, (string) $this->transfers->find($id)->to_branch_id);
        $this->assertSame('B2', $this->transfers->find($id)->to_branch_code);
    }

    public function testTransfer_LeavesTheTransferWithinOneBranchWithNoBranchItGoesTo(): void
    {
        $id = $this->transfers->transfer($this->main, $this->drawer, '10.00', '2026-10-21', '', $this->manager);

        $this->assertNull($this->transfers->find($id)->to_branch_id);
        $this->assertSame(['treasury_transfer'], array_keys($this->journalsOf($id)));
    }

    public function testTransfer_RefusesBetweenBranchesWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        [$other] = $this->elsewhere();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.payable')->update(['account_id' => null]);

        $before  = $this->countRows('accounting_journals');
        $refusal = $this->refusal(fn () => $this->transfers->transfer($this->main, $other, '10', '2026-10-21', '', $this->manager));

        $this->assertSame(AccountingError::InterBranchAccountsMissing, $refusal->error);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
        $this->assertSame($before, $this->countRows('accounting_journals'));
    }

    public function testTransfer_RefusesAnEmployeeWhoWorksInOnlyOneOfTheTwoBranches(): void
    {
        [$other, $branch] = $this->elsewhere();

        $inFirst  = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE], [1]);
        $inSecond = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE], [$branch]);

        foreach ([$inFirst, $inSecond] as $employee) {
            try {
                $this->transfers->transfer($this->main, $other, '10', '2026-10-21', '', $employee);
                $this->fail('A branch the employee does not work in was accepted.');
            } catch (BranchException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(0, $this->countRows('treasury_transfers'));
        $this->assertGreaterThan(0, $this->transfers->transfer($this->main, $other, '10', '2026-10-21', '', $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE], [1, $branch])));
    }

    public function testTransfer_AsksTheScopesOfBothTreasuriesBetweenBranches(): void
    {
        [$closedToDeposits] = $this->elsewhere('deposit-closed');

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->transfers->transfer($this->main, $closedToDeposits, '10', '2026-10-21', '', $this->manager))->error);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
    }

    public function testTransfer_LeavesNothingBehindWhenThePeriodOfTheReceivingBranchIsClosed(): void
    {
        [$other, $branch] = $this->elsewhere();

        $this->closeThrough('2026-10-31', $branch);

        $before  = $this->countRows('accounting_journals');
        $refusal = $this->refusal(fn () => $this->transfers->transfer($this->main, $other, '10', '2026-10-21', '', $this->manager));

        $this->assertSame(PostingError::PeriodClosed, $refusal->error);
        $this->assertSame(0, $this->countRows('treasury_transfers'));
        $this->assertSame($before, $this->countRows('accounting_journals'), 'The sending branch journal went with it');
        $this->assertSame('500.0000', $this->balanceOf($this->main));
    }

    public function testCancel_ReversesTheJournalOfEachBranchAndTheAccountsBetweenBranchesOffsetAgain(): void
    {
        [$other, $branch] = $this->elsewhere();
        $id               = $this->transfers->transfer($this->main, $other, '100.00', '2026-10-21', '', $this->manager);

        $this->transfers->cancel($id, 'Wrong branch', $this->manager);

        $this->assertSame([], $this->journalsOf($id));
        $this->assertSame(['treasury_transfer', 'treasury_transfer_receipt'], array_keys($this->journalsOf($id, 'reversed')));
        $this->assertSame('500.0000', $this->balanceOf($this->main));
        $this->assertSame('0.0000', $this->balanceOf($other));
        $this->assertSame('cancelled', $this->transfers->find($id)->status);

        $reversals = $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->orderBy('journal_id', 'ASC')->get()->getResult();

        $this->assertSame([1, $branch], array_map(static fn (object $journal): int => (int) $journal->branch_id, array_slice($reversals, -2)));
        $this->assertSame('0.0000', (new InterBranchPosition())->difference());
    }

    /**
     * The journal ids of a transfer by the branch of each journal.
     *
     * @return array<int, int>
     */
    private function journalIdsByBranch(int $transferId, string $source = 'treasury_transfer'): array
    {
        $ids = [];

        foreach ($this->accountingDb()->table('accounting_journals')->whereIn('source_type', [$source, $source . '_receipt'])->where('source_id', $transferId)->get()->getResult() as $journal) {
            $ids[(int) $journal->branch_id] = (int) $journal->journal_id;
        }

        return $ids;
    }

    public function testTransfer_WritesTheJournalOfTheLowerBranchFirstWhicheverWayTheMoneyGoes(): void
    {
        [$other, $branch] = $this->elsewhere();

        $forward  = $this->journalIdsByBranch($this->transfers->transfer($this->main, $other, '10.00', '2026-10-21', '', $this->manager));
        $backward = $this->journalIdsByBranch($this->transfers->transfer($other, $this->main, '5.00', '2026-10-21', '', $this->manager));

        $this->assertLessThan($forward[$branch], $forward[1]);
        $this->assertLessThan($backward[$branch], $backward[1]);
    }

    public function testCancel_ReversesTheJournalOfTheLowerBranchFirstWhicheverWayTheMoneyGoes(): void
    {
        [$other, $branch] = $this->elsewhere();
        $forward          = $this->transfers->transfer($this->main, $other, '10.00', '2026-10-21', '', $this->manager);
        $backward         = $this->transfers->transfer($other, $this->main, '5.00', '2026-10-21', '', $this->manager);

        $this->transfers->cancel($forward, 'Reason', $this->manager);
        $this->transfers->cancel($backward, 'Reason', $this->manager);

        foreach ([$forward, $backward] as $transferId) {
            $originals = $this->journalIdsByBranch($transferId);
            $first     = (int) $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $originals[1])->get()->getRow()->journal_id;
            $second    = (int) $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $originals[$branch])->get()->getRow()->journal_id;

            $this->assertLessThan($second, $first);
        }
    }

    public function testCancel_RefusesAnEmployeeWhoWorksInOnlyOneOfTheBranchesAndReversesNothing(): void
    {
        [$other, $branch] = $this->elsewhere();
        $id               = $this->transfers->transfer($this->main, $other, '100.00', '2026-10-21', '', $this->manager);

        foreach ([$this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE], [1]), $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE], [$branch])] as $local) {
            try {
                $this->transfers->cancel($id, 'Reason', $local);
                $this->fail('A branch the employee does not work in was accepted.');
            } catch (BranchException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(['treasury_transfer', 'treasury_transfer_receipt'], array_keys($this->journalsOf($id)));
        $this->assertSame('completed', $this->transfers->find($id)->status);
    }

    public function testCancel_AsksTheScopesOfBothTreasuriesAndReversesNothingWhenOneIsClosed(): void
    {
        [$other] = $this->elsewhere();
        $id      = $this->transfers->transfer($this->main, $other, '100.00', '2026-10-21', '', $this->manager);
        $editor  = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);

        $this->treasuries->update($other, ['name' => 'Elsewhere', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]], $this->manager);

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->refusal(fn () => $this->transfers->cancel($id, 'Reason', $editor))->error);
        $this->assertSame(['treasury_transfer', 'treasury_transfer_receipt'], array_keys($this->journalsOf($id)));
        $this->assertSame('100.0000', $this->balanceOf($other));
    }

    public function testSearch_ListsATransferToTheEmployeesOfEitherBranchAndToNoOneElse(): void
    {
        [$other, $branch] = $this->elsewhere();
        $third            = $this->createBranch('B3');
        $id               = $this->transfers->transfer($this->main, $other, '100.00', '2026-10-21', '', $this->manager);

        foreach ([[1], [$branch], [1, $third], null] as $seen) {
            $this->assertContains($id, array_map(static fn (object $row): int => (int) $row->transfer_id, $this->transfers->search($seen)['rows']));
        }

        $this->assertNotContains($id, array_map(static fn (object $row): int => (int) $row->transfer_id, $this->transfers->search([$third])['rows']));
        $this->assertSame(0, $this->transfers->search([$third])['total']);
        $this->assertSame(1, $this->transfers->search([$branch])['total']);
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

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixture T-52
    // ---------------------------------------------------------------------------------------

    public function testCancel_ReversesATransferWhereTheReferenceSystemEditsItInPlaceAndCannotDeleteIt(): void
    {
        $case      = $this->referenceCase('T-52', 'treasury_rules', 'a-transfer-is-edited-in-place-or-cancelled-by-a-reversal', 'deliberate_difference');
        $transfer  = $this->transfers->transfer($this->main, $this->drawer, '100.00', '2026-10-21', '', $this->manager);
        $methods   = get_class_methods(TreasuryTransfers::class);
        $original  = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $transfer])->get()->getRow();

        $this->transfers->cancel($transfer, 'wrong amount', $this->manager);

        $this->assertSameAsFixture($case['expected'], [
            'can_be_deleted'          => array_filter($methods, static fn (string $method): bool => preg_match('/delete|remove/i', $method) === 1) !== [],
            'amount_edited_in_place'  => array_filter($methods, static fn (string $method): bool => preg_match('/edit|update|change/i', $method) === 1) !== [],
            'cancelled_by_a_reversal' => $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id', $original->journal_id)->countAllResults() === 1,
        ], 'what a transfer allows');
        $this->assertSame('reversed', $this->accountingDb()->table('accounting_journals')->where('journal_id', $original->journal_id)->get()->getRow()->status);
        $this->assertSame('0.0000', $this->balanceOf($this->drawer));
    }

    // ---------------------------------------------------------------------------------------
    // Treasuries that hold a foreign currency
    // ---------------------------------------------------------------------------------------

    private function dollarBox(string $name = 'Dollar box'): int
    {
        $currencies = new Currencies();
        $currencies->setActive('USD', true);

        if ($currencies->rates()->rateOn('USD', '2026-10-21') === null) {
            $currencies->rates()->add(['currency_code' => 'USD', 'rate' => '3.75', 'effective_date' => '2020-01-01']);
        }

        return $this->treasuries->create(['name' => $name, 'kind' => 'cash', 'currency_code' => 'USD'], $this->manager);
    }

    /**
     * @return list<string> "account:debit:credit" of the lines of the journal of a transfer.
     */
    private function transferLines(int $transferId): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $transferId])->get()->getRow();

        return array_map(static fn (object $line): string => $line->account_id . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2), $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult());
    }

    public function testTransfer_MovesTheBaseIntoATreasuryOfDollarsWithBothAmountsAndNoDifferenceAtTheRateOfTheDay(): void
    {
        $dollars = $this->dollarBox();

        $id = $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '100.00');

        $this->assertSame([(int) $this->treasuries->accountOf($dollars) . ':375.00:0.00', (int) $this->treasuries->accountOf($this->main) . ':0.00:375.00'], $this->transferLines($id));
        $this->assertSame('100.0000', $this->treasuries->position($dollars)->foreignBalance);
        $this->assertSame('375.0000', $this->treasuries->position($dollars)->balance);
        $this->assertSame('125.0000', $this->treasuries->position($this->main)->balance);

        $row = $this->accountingDb()->table('treasury_transfers')->where('transfer_id', $id)->get()->getRow();
        $this->assertSame([null, 'USD', '100.0000'], [$row->from_currency, $row->to_currency, $row->to_amount]);
    }

    public function testTransfer_PostsTheExchangeDifferenceBetweenTheTwoAmountsAsALineOfTheSameJournal(): void
    {
        $dollars = $this->dollarBox();

        $loss = $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '99.00');
        $gain = $this->transfers->transfer($this->main, $dollars, '100.00', '2026-10-21', '', $this->manager, '27.00');

        $this->assertSame([(int) $this->treasuries->accountOf($dollars) . ':371.25:0.00', (int) $this->treasuries->accountOf($this->main) . ':0.00:375.00', $this->accountId('fx_loss') . ':3.75:0.00'], $this->transferLines($loss));
        $this->assertSame([(int) $this->treasuries->accountOf($dollars) . ':101.25:0.00', (int) $this->treasuries->accountOf($this->main) . ':0.00:100.00', $this->accountId('fx_gain') . ':0.00:1.25'], $this->transferLines($gain));
    }

    public function testTransfer_MovesDollarsOutOfATreasuryOfDollarsIntoTheBase(): void
    {
        $dollars = $this->dollarBox();
        $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '100.00');

        $id = $this->transfers->transfer($dollars, $this->drawer, '40.00', '2026-10-21', '', $this->manager, '150.00');

        $this->assertSame([(int) $this->treasuries->accountOf($this->drawer) . ':150.00:0.00', (int) $this->treasuries->accountOf($dollars) . ':0.00:150.00'], $this->transferLines($id));
        $this->assertSame('60.0000', $this->treasuries->position($dollars)->foreignBalance);
        $this->assertSame('225.0000', $this->treasuries->position($dollars)->balance);
    }

    public function testTransfer_MovesDollarsBetweenTwoTreasuriesOfDollarsAsAJournalInDollars(): void
    {
        $first  = $this->dollarBox();
        $second = $this->dollarBox('Second dollar box');
        $this->transfers->transfer($this->main, $first, '375.00', '2026-10-21', '', $this->manager, '100.00');

        $id = $this->transfers->transfer($first, $second, '30.00', '2026-10-21', '', $this->manager);

        $this->assertSame([(int) $this->treasuries->accountOf($second) . ':112.50:0.00', (int) $this->treasuries->accountOf($first) . ':0.00:112.50'], $this->transferLines($id));
        $this->assertSame(['USD', '3.75000000'], [$this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $id])->get()->getRow()->currency_code, $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $id])->get()->getRow()->exchange_rate]);
        $this->assertSame('70.0000', $this->treasuries->position($first)->foreignBalance);
        $this->assertSame('30.0000', $this->treasuries->position($second)->foreignBalance);
    }

    public function testTransfer_RequiresTheAmountThatArrivesWhenTheCurrenciesDiffer(): void
    {
        $dollars = $this->dollarBox();

        try {
            $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager);
            $this->fail('A transfer between currencies needs both amounts.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::TransferAmountRequired, $exception->error);
        }

        $this->assertSame(0, $this->accountingDb()->table('treasury_transfers')->where('to_treasury_id', $dollars)->countAllResults());
    }

    public function testTransfer_RefusesADifferentAmountOutAndInWhenThereIsOneCurrency(): void
    {
        $dollars = $this->dollarBox();
        $second  = $this->dollarBox('Second dollar box');

        foreach ([[$this->main, $this->drawer], [$dollars, $second]] as [$from, $to]) {
            try {
                $this->transfers->transfer($from, $to, '10.00', '2026-10-21', '', $this->manager, '9.00');
                $this->fail('One currency takes the same amount out and in.');
            } catch (AccountingException $exception) {
                $this->assertSame(AccountingError::TransferAmountInvalid, $exception->error);
            }
        }
    }

    public function testTransfer_RefusesWhenTheCurrencyHasNoRateOnTheDay(): void
    {
        $dollars = $this->dollarBox();
        $this->accountingDb()->table('accounting_currency_rates')->truncate();

        try {
            $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '100.00');
            $this->fail('A transfer needs the rate of the day.');
        } catch (AccountingException $exception) {
            $this->assertSame(AccountingError::RateMissing, $exception->error);
        }

        $this->assertSame('0.0000', $this->treasuries->position($dollars)->balance);
    }

    public function testTransfer_RefusesAnExchangeLossWhenTheRoutingOfExchangeLossesHasNoAccountAndChangesNothing(): void
    {
        $dollars = $this->dollarBox();
        $routing = $this->accountingDb()->table('accounting_routing')->where('routing_key', 'fx.loss')->get()->getRow();

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'fx.loss')->update(['account_id' => null]);

        try {
            $exception = $this->refusal(fn () => $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '99.00'));
        } finally {
            $this->accountingDb()->table('accounting_routing')->where('routing_key', 'fx.loss')->update(['account_id' => $routing->account_id]);
        }

        $this->assertSame(PostingError::FxAccountsMissing, $exception->error);
        $this->assertSame('0.0000', $this->treasuries->position($dollars)->balance);
    }

    public function testTransfer_RefusesATransferBetweenBranchesInAForeignCurrencyAndChangesNothing(): void
    {
        $dollars = $this->dollarBox();
        $branch  = $this->createBranch('B2');
        $other   = $this->treasuries->create(['name' => 'Branch drawer', 'kind' => 'cash', 'branch_id' => $branch], $this->manager);

        $this->manager = $this->employeeWithGrants([AccountingGuard::TREASURIES_MANAGE]);

        try {
            $this->transfers->transfer($other, $dollars, '375.00', '2026-10-21', '', $this->manager, '100.00');
            $this->fail('A transfer between branches is never in a foreign currency.');
        } catch (PostingException $exception) {
            $this->assertSame(PostingError::FxNotSupported, $exception->error);
        }

        $this->assertSame('0.0000', $this->treasuries->position($dollars)->balance);
    }

    public function testCancel_TakesBackATransferInDollarsWithBothAmounts(): void
    {
        $dollars = $this->dollarBox();
        $id      = $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '99.00');

        $this->transfers->cancel($id, 'a mistake', $this->manager);

        $this->assertSame('0.0000', $this->treasuries->position($dollars)->foreignBalance);
        $this->assertSame('0.0000', $this->treasuries->position($dollars)->balance);
        $this->assertSame('500.0000', $this->treasuries->position($this->main)->balance);
        $this->assertSame('0.00', $this->balance($this->accountId('fx_loss')));
    }

    public function testTransfer_LeavesNothingBehindWhenThePeriodOfATransferBetweenCurrenciesIsClosed(): void
    {
        $dollars = $this->dollarBox();
        $this->closeThrough('2026-10-31');

        $refusal = $this->refusal(fn () => $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '99.00'));

        $this->assertSame(PostingError::PeriodClosed, $refusal->error);
        $this->assertSame(0, $this->accountingDb()->table('treasury_transfers')->where('to_treasury_id', $dollars)->countAllResults());
        $this->assertSame('0.0000', $this->treasuries->position($dollars)->foreignBalance);
        $this->assertSame('0.00', $this->balance($this->accountId('fx_loss')));
    }

    public function testCancel_KeepsATransferBetweenCurrenciesWhenThePeriodOfItsDayIsClosed(): void
    {
        $dollars = $this->dollarBox();
        $id      = $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '99.00');
        $this->closeThrough(date('Y-m-d'));

        $refusal = $this->refusal(fn () => $this->transfers->cancel($id, 'a mistake', $this->manager));

        $this->assertSame(PostingError::PeriodClosed, $refusal->error);
        $this->assertSame('99.0000', $this->treasuries->position($dollars)->foreignBalance);
        $this->assertSame('3.75', $this->balance($this->accountId('fx_loss')));
    }

    public function testTransfer_LeavesATransferBetweenTwoTreasuriesOfTheBaseAsItWas(): void
    {
        $id = $this->transfers->transfer($this->main, $this->drawer, '100.00', '2026-10-21', '', $this->manager);

        $this->assertSame([(int) $this->treasuries->accountOf($this->drawer) . ':100.00:0.00', (int) $this->treasuries->accountOf($this->main) . ':0.00:100.00'], $this->transferLines($id));

        $row = $this->accountingDb()->table('treasury_transfers')->where('transfer_id', $id)->get()->getRow();
        $this->assertSame([null, null, null], [$row->from_currency, $row->to_currency, $row->to_amount]);
    }

    /**
     * @param array<string, int> $accounts The account of each role.
     *
     * @return list<array<string, string>> The lines of the journal of a transfer as the fixture writes them.
     */
    private function transferAsFixture(int $transferId, array $accounts): array
    {
        $roles   = array_flip($accounts);
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'treasury_transfer', 'source_id' => $transferId])->get()->getRow();
        $lines   = [];

        foreach ($this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult() as $line) {
            $role = $roles[(int) $line->account_id] ?? 'account ' . $line->account_id;

            if ($line->foreign_amount === null) {
                $lines[] = ['role' => $role, 'debit' => $line->debit, 'credit' => $line->credit];
            } elseif (bccomp($line->debit, '0', 4) > 0) {
                $lines[] = ['role' => $role, 'debit' => $line->foreign_amount, 'credit' => '0', 'debit_base' => $line->debit];
            } else {
                $lines[] = ['role' => $role, 'debit' => '0', 'credit' => $line->foreign_amount, 'credit_base' => $line->credit];
            }
        }

        return $lines;
    }

    public function testTransfer_PostsTheThreeObservedTransfersAsOneJournalEachWithTheForeignLegAtTheRateOfTheTable(): void
    {
        $case    = $this->referenceCase('T-60', 'currency_transfer', 'a-transfer-between-treasuries-of-two-currencies', 'differs');
        $dollars = $this->dollarBox();
        $roles   = ['treasury' => (int) $this->treasuries->accountOf($dollars), 'bank' => (int) $this->treasuries->accountOf($this->main), 'fx_loss' => $this->accountId('fx_loss'), 'fx_gain' => $this->accountId('fx_gain')];

        $first  = $this->transfers->transfer($this->main, $dollars, '37.50', '2026-10-21', '', $this->manager, '10.00');
        $second = $this->transfers->transfer($this->main, $dollars, '30.00', '2026-10-21', '', $this->manager, '7.50');
        $third  = $this->transfers->transfer($dollars, $this->main, '17.50', '2026-10-21', '', $this->manager, '65.63');

        $this->assertSameAsFixture($case['expected']['journals']['x1'], $this->transferAsFixture($first, $roles), 'the first transfer');
        $this->assertSameAsFixture($case['expected']['journals']['x2'], $this->transferAsFixture($second, $roles), 'the second transfer');
        $this->assertSameAsFixture($case['expected']['journals']['x3'], $this->transferAsFixture($third, $roles), 'the third transfer');
        $this->assertSame('0.0000', $this->treasuries->position($dollars)->foreignBalance);
        $this->assertFalse($case['expected']['a_transfer_can_be_deleted']);
    }

    public function testTransfer_AcceptsAnAmountThatArrivesTenPercentFromTheRateOfTheDay(): void
    {
        $dollars = $this->dollarBox();

        $id = $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '90.00');

        $this->assertSame([(int) $this->treasuries->accountOf($dollars) . ':337.50:0.00', (int) $this->treasuries->accountOf($this->main) . ':0.00:375.00', $this->accountId('fx_loss') . ':37.50:0.00'], $this->transferLines($id));
    }

    public function testTransfer_RejectsAnAmountThatArrivesFurtherThanTenPercentBelowTheRateOfTheDay(): void
    {
        $dollars = $this->dollarBox();

        $exception = $this->refusal(fn () => $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '89.99'));

        $this->assertSame(AccountingError::TransferRateOutOfRange, $exception->error);
        $this->assertSame(0, $this->accountingDb()->table('treasury_transfers')->where('to_treasury_id', $dollars)->countAllResults());
        $this->assertSame('0.00', $this->balance($this->accountId('fx_loss')));
    }

    public function testTransfer_RejectsAnAmountThatArrivesFurtherThanTenPercentAboveTheRateOfTheDay(): void
    {
        $dollars = $this->dollarBox();

        $exception = $this->refusal(fn () => $this->transfers->transfer($this->main, $dollars, '3750.00', '2026-10-21', '', $this->manager, '10000.00'));

        $this->assertSame(AccountingError::TransferRateOutOfRange, $exception->error);
        $this->assertSame('0.00', $this->balance($this->accountId('fx_gain')));
        $this->assertSame('0.0000', $this->treasuries->position($dollars)->balance);
    }

    public function testTransfer_RejectsAnAmountOfTheBaseThatArrivesFarFromWhatTheDollarsLeavingAreWorth(): void
    {
        $dollars = $this->dollarBox();
        $this->transfers->transfer($this->main, $dollars, '375.00', '2026-10-21', '', $this->manager, '100.00');

        $exception = $this->refusal(fn () => $this->transfers->transfer($dollars, $this->drawer, '100.00', '2026-10-21', '', $this->manager, '2000.00'));

        $this->assertSame(AccountingError::TransferRateOutOfRange, $exception->error);
        $this->assertSame('100.0000', $this->treasuries->position($dollars)->foreignBalance);
    }
}
