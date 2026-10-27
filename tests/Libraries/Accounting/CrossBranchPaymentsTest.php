<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\InterBranchLegs;
use App\Libraries\Accounting\JournalChange;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\JournalService;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchGuard;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * A payment that goes into or out of a treasury of another branch: the two drafts it is made of, the
 * order they are written in, the record of the branch on the other side of each, and how the two
 * journals that stand are kept in step with a payment that changes.
 */
class CrossBranchPaymentsTest extends CIUnitTestCase
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

    private CrossBranchPayments $payments;
    private TreasuryService $treasuries;
    private int $branch;
    private int $treasury;
    private int $account;

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
        $this->payments   = new CrossBranchPayments();
        $this->branch     = $this->createBranch('B2');
        $this->treasury   = $this->treasuries->create(['name' => 'Branch drawer', 'kind' => 'cash', 'branch_id' => $this->branch]);
        $this->account    = (int) $this->treasuries->accountOf($this->treasury);
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * The draft of a payment of a sale of the first branch: what the treasury receives, or gives
     * back, against what the customer owes.
     */
    private function draft(bool $receipt = true, string $amount = '100.00', int $paymentId = 5001, int $revision = 1, string $source = SalePostingBuilder::SOURCE_PAYMENT): JournalDraft
    {
        $customer = $this->customerAccount();

        return new JournalDraft(
            date: '2026-10-21',
            lines: $receipt
                ? [new JournalLineDraft($this->account, debit: $amount), new JournalLineDraft($customer, credit: $amount)]
                : [new JournalLineDraft($customer, debit: $amount), new JournalLineDraft($this->account, credit: $amount)],
            sourceType: $source,
            sourceId: $paymentId,
            revision: $revision,
            description: 'Payment #' . $paymentId,
            branchId: 1,
        );
    }

    /**
     * @param list<JournalDraft> $drafts
     *
     * @return array<string, array{int, list<string>}> By source: the branch and the lines.
     */
    private function shapeOf(array $drafts): array
    {
        $shape = [];

        foreach ($drafts as $draft) {
            $shape[$draft->sourceType] = [(int) $draft->branchId, array_map(static fn (JournalLineDraft $line): string => $line->accountId . ':' . bcadd($line->debit, '0', 2) . ':' . bcadd($line->credit, '0', 2), $draft->lines)];
        }

        return $shape;
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

    public function testDrafts_LeavesAPaymentThatCarriesNoBranchOfATreasuryAsOneJournal(): void
    {
        $draft = $this->draft();

        $this->assertSame([$draft], $this->payments->drafts($draft, $this->treasury, null));
        $this->assertSame([$draft], $this->payments->drafts($draft, null, $this->branch));
        $this->assertSame([$draft], $this->payments->drafts($draft, $this->treasury, 1), 'A treasury of its own branch');
    }

    public function testDrafts_MakesAReceiptTwoJournalsThatMeetInTheAccountsBetweenBranches(): void
    {
        $pair = $this->payments->drafts($this->draft(true), $this->treasury, $this->branch);

        $this->assertSame([
            'sale_payment'        => [1, [$this->accountId('branches_receivable') . ':100.00:0.00', $this->customerAccount() . ':0.00:100.00']],
            'sale_payment_branch' => [$this->branch, [$this->account . ':100.00:0.00', $this->accountId('branches_payable') . ':0.00:100.00']],
        ], $this->shapeOf($pair));
    }

    public function testDrafts_MakesAPaymentOutTwoJournalsThatMeetTheOtherWay(): void
    {
        $pair = $this->payments->drafts($this->draft(false, '40.00'), $this->treasury, $this->branch);

        $this->assertSame([
            'sale_payment'        => [1, [$this->customerAccount() . ':40.00:0.00', $this->accountId('branches_payable') . ':0.00:40.00']],
            'sale_payment_branch' => [$this->branch, [$this->accountId('branches_receivable') . ':40.00:0.00', $this->account . ':0.00:40.00']],
        ], $this->shapeOf($pair));
    }

    public function testDrafts_KeepsEverythingElseOfTheDocumentJournalAndGivesTheOtherTheSameSourceAndRevision(): void
    {
        [$own, $other] = $this->payments->drafts($this->draft(true, '100.00', 5002, 3), $this->treasury, $this->branch);

        $this->assertSame(['2026-10-21', 'sale_payment', 5002, 3, 'Payment #5002'], [$own->date, $own->sourceType, $own->sourceId, $own->revision, $own->description]);
        $this->assertSame(['2026-10-21', 'sale_payment_branch', 5002, 3, 'Payment #5002 (HQ)'], [$other->date, $other->sourceType, $other->sourceId, $other->revision, $other->description]);
    }

    public function testDrafts_PutsTheLowerBranchFirstWhicheverBranchHoldsTheTreasury(): void
    {
        [$first, $second] = $this->payments->drafts($this->draft(), $this->treasury, $this->branch);

        $this->assertSame([1, $this->branch], [$first->branchId, $second->branchId], 'The treasury is of the higher branch');

        $low  = $this->treasuries->create(['name' => 'Low drawer', 'kind' => 'cash']);
        $high = new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($low), debit: '10.00'), new JournalLineDraft($this->customerAccount(), credit: '10.00')],
            sourceType: SalePostingBuilder::SOURCE_PAYMENT,
            sourceId: 5003,
            branchId: $this->branch,
        );

        [$first, $second] = $this->payments->drafts($high, $low, 1);

        $this->assertSame([1, $this->branch], [$first->branchId, $second->branchId], 'The treasury is of the lower branch');
        $this->assertSame(['sale_payment_branch', 'sale_payment'], [$first->sourceType, $second->sourceType]);
    }

    public function testDrafts_GivesEachKindOfPaymentItsOwnSourceForTheJournalOfTheTreasury(): void
    {
        foreach (['sale_payment' => 'sale_payment_branch', 'purchase_payment' => 'purchase_payment_branch', 'supplier_payment' => 'supplier_payment_branch', 'expense' => 'expense_branch'] as $source => $companion) {
            $sources = array_map(static fn (JournalDraft $draft): string => $draft->sourceType, $this->payments->drafts($this->draft(true, '10.00', 5004, 1, $source), $this->treasury, $this->branch));

            $this->assertSame([$source, $companion], $sources);
            $this->assertSame([$companion], CrossBranchPayments::companionsOf($source));
        }

        $this->assertSame([], CrossBranchPayments::companionsOf('treasury_transfer'));
    }

    public function testDrafts_RejectsAJournalOfASourceThatHasNoJournalForTheTreasuryOfAnotherBranch(): void
    {
        $refusal = $this->refusal(fn () => $this->payments->drafts($this->draft(true, '10.00', 5005, 1, 'treasury_transfer'), $this->treasury, $this->branch));

        $this->assertSame(PostingError::CrossBranchPaymentInvalid, $refusal->error);
    }

    public function testDrafts_RejectsATreasuryTheDraftMovesMoreThanOnce(): void
    {
        $twice = new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->account, debit: '10.00'), new JournalLineDraft($this->account, debit: '5.00'), new JournalLineDraft($this->customerAccount(), credit: '15.00')],
            sourceType: SalePostingBuilder::SOURCE_PAYMENT,
            sourceId: 5006,
            branchId: 1,
        );

        $this->assertSame(PostingError::CrossBranchPaymentInvalid, $this->refusal(fn () => $this->payments->drafts($twice, $this->treasury, $this->branch))->error);
    }

    public function testDrafts_LeavesAPaymentThatDoesNotMoveTheTreasuryAsItIs(): void
    {
        $elsewhere = new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '10.00'), new JournalLineDraft($this->customerAccount(), credit: '10.00')],
            sourceType: SalePostingBuilder::SOURCE_PAYMENT,
            sourceId: 5007,
            branchId: 1,
        );

        $this->assertSame([$elsewhere], $this->payments->drafts($elsewhere, $this->treasury, $this->branch));
    }

    public function testDrafts_RejectsWhenTheRoutingNamesNoAccountsBetweenBranches(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'branches.payable')->update(['account_id' => null]);

        $this->assertSame(PostingError::InterBranchAccountsMissing, $this->refusal(fn () => $this->payments->drafts($this->draft(), $this->treasury, $this->branch))->error);
    }

    public function testPost_WritesTheJournalOfTheLowerBranchFirstAndRecordsTheBranchOnTheOtherSideOfEach(): void
    {
        $this->payments->post($this->payments->drafts($this->draft(true, '100.00', 5010), $this->treasury, $this->branch));

        $journals = $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['sale_payment', 'sale_payment_branch'])->where('source_id', 5010)->orderBy('journal_id')->get()->getResult();
        $legs     = $this->accountingDb()->table('accounting_interbranch_legs')->where(['document_type' => 'sale_payment', 'document_id' => 5010])->orderBy('journal_id')->get()->getResult();

        $this->assertSame([1, $this->branch], array_map(static fn (object $journal): int => (int) $journal->branch_id, $journals));
        $this->assertSame([[(int) $journals[0]->journal_id, 'receivable', $this->branch, 1], [(int) $journals[1]->journal_id, 'payable', 1, 1]], array_map(static fn (object $leg): array => [(int) $leg->journal_id, $leg->side, (int) $leg->counterpart_branch_id, (int) $leg->revision], $legs));
    }

    public function testPost_TakesThePeriodLockOfTheLowerBranchBeforeTheOtherWhicheverBranchHoldsTheTreasury(): void
    {
        $low  = $this->treasuries->create(['name' => 'Low drawer', 'kind' => 'cash']);
        $high = new JournalDraft(
            date: '2026-10-21',
            lines: [new JournalLineDraft((int) $this->treasuries->accountOf($low), debit: '10.00'), new JournalLineDraft($this->customerAccount(), credit: '10.00')],
            sourceType: SalePostingBuilder::SOURCE_PAYMENT,
            sourceId: 5030,
            branchId: $this->branch,
        );

        $this->assertSame([1, $this->branch], $this->periodLocksTakenBy(fn () => $this->payments->post($this->payments->drafts($this->draft(true, '100.00', 5031), $this->treasury, $this->branch))));
        $this->assertSame([1, $this->branch], $this->periodLocksTakenBy(fn () => $this->payments->post($this->payments->drafts($high, $low, 1))));
    }

    public function testPost_RecordsNothingForAPaymentOfOneJournal(): void
    {
        $this->payments->post([$this->draft(true, '100.00', 5011)]);

        $this->assertSame(0, $this->countRows('accounting_interbranch_legs'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_id', 5011)->countAllResults());
    }

    public function testPost_WritesThePaymentOnlyOnce(): void
    {
        $drafts = $this->payments->drafts($this->draft(true, '100.00', 5012), $this->treasury, $this->branch);

        $this->payments->post($drafts);
        $this->payments->post($drafts);

        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['sale_payment', 'sale_payment_branch'])->where('source_id', 5012)->countAllResults());
        $this->assertSame(2, $this->countRows('accounting_interbranch_legs'));
    }

    public function testPost_LeavesBothJournalsUnwrittenWhenThePeriodOfEitherBranchIsClosed(): void
    {
        foreach ([1, $this->branch] as $closed) {
            $this->closeThrough('2026-10-31', $closed);

            $refusal = $this->refusal(fn () => $this->payments->post($this->payments->drafts($this->draft(true, '100.00', 5013), $this->treasury, $this->branch)));

            $this->assertSame(PostingError::PeriodClosed, $refusal->error);
            $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['sale_payment', 'sale_payment_branch'])->where('source_id', 5013)->countAllResults());

            $this->accountingDb()->table('accounting_closed_periods')->truncate();
        }
    }

    /**
     * Takes a journal back the way the document of a payment does.
     */
    private function reverser(?int $employeeId = null): callable
    {
        return fn (object $journal): int => (new JournalService())->reverseAutomatic((int) $journal->journal_id, date('Y-m-d'), 'Changed', $employeeId);
    }

    private function activeOf(int $paymentId): ?object
    {
        return $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $paymentId, 'status' => 'posted'])->get()->getRow();
    }

    public function testSync_DoesNothingWhenThereIsNothingToPostAndNothingStands(): void
    {
        $this->assertSame(JournalChange::None, $this->payments->sync(null, [], null, $this->reverser()));
    }

    public function testSync_PostsThePairWhenNoneStands(): void
    {
        $change = $this->payments->sync(null, $this->payments->drafts($this->draft(true, '100.00', 5020), $this->treasury, $this->branch), null, $this->reverser());

        $this->assertSame(JournalChange::Posted, $change);
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_id', 5020)->countAllResults());
    }

    public function testSync_LeavesThePairThatStandsWhenTheDraftsHoldWhatItHolds(): void
    {
        $drafts = $this->payments->drafts($this->draft(true, '100.00', 5021), $this->treasury, $this->branch);

        $this->payments->sync(null, $drafts, null, $this->reverser());

        $this->assertSame(JournalChange::Unchanged, $this->payments->sync($this->activeOf(5021), $drafts, null, $this->reverser()));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_id', 5021)->countAllResults());
    }

    public function testSync_ReplacesThePairWhenTheAmountTheDateOrTheTreasuryChanges(): void
    {
        $first = $this->payments->drafts($this->draft(true, '100.00', 5022), $this->treasury, $this->branch);

        $this->payments->sync(null, $first, null, $this->reverser());

        $changed = $this->payments->drafts($this->draft(true, '60.00', 5022, 2), $this->treasury, $this->branch);

        $this->assertSame(JournalChange::Replaced, $this->payments->sync($this->activeOf(5022), $changed, null, $this->reverser()));
        $this->assertSame(['60.0000', '60.0000'], array_map(static fn (object $journal): string => (string) $journal->total_debit, $this->accountingDb()->table('accounting_journals')->whereIn('source_type', ['sale_payment', 'sale_payment_branch'])->where(['source_id' => 5022, 'status' => 'posted'])->orderBy('journal_id')->get()->getResult()));

        $dated = new JournalDraft(date: '2026-10-22', lines: $changed[0]->lines, sourceType: $changed[0]->sourceType, sourceId: 5022, revision: 3, branchId: 1);
        $other = new JournalDraft(date: '2026-10-22', lines: $changed[1]->lines, sourceType: $changed[1]->sourceType, sourceId: 5022, revision: 3, branchId: $this->branch);

        $this->assertSame(JournalChange::Replaced, $this->payments->sync($this->activeOf(5022), [$dated, $other], null, $this->reverser()), 'Only the date changed');
    }

    public function testSync_ReplacesThePairByOneJournalAndOneJournalByThePair(): void
    {
        $this->payments->sync(null, $this->payments->drafts($this->draft(true, '100.00', 5023), $this->treasury, $this->branch), null, $this->reverser());

        $single = $this->draft(true, '100.00', 5023, 2);

        $this->assertSame(JournalChange::Replaced, $this->payments->sync($this->activeOf(5023), [$single], null, $this->reverser()));
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment_branch', 'source_id' => 5023, 'status' => 'posted'])->countAllResults());
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => 5023, 'status' => 'posted'])->countAllResults());
    }

    public function testSync_TakesTheWholePairBackWhenNothingIsToStandInItsPlace(): void
    {
        $this->payments->sync(null, $this->payments->drafts($this->draft(true, '100.00', 5024), $this->treasury, $this->branch), null, $this->reverser());

        $this->assertSame(JournalChange::Reversed, $this->payments->sync($this->activeOf(5024), [], null, $this->reverser()));
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where(['source_id' => 5024, 'status' => 'reversed'])->countAllResults());
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where(['source_id' => 5024, 'status' => 'posted'])->countAllResults());
    }

    public function testSync_RejectsAnEmployeeWhoWorksInOnlyOneOfTheTwoBranchesBeforeWritingAnything(): void
    {
        $drafts = $this->payments->drafts($this->draft(true, '100.00', 5025), $this->treasury, $this->branch);

        foreach ([[1], [$this->branch]] as $works) {
            $refusal = $this->refusal(fn () => $this->payments->sync(null, $drafts, $this->employeeWithGrants([], $works), $this->reverser()));

            $this->assertSame(AccountingError::InterBranchBranchesDenied, $refusal->error);
        }

        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_id', 5025)->countAllResults());

        $this->payments->sync(null, $drafts, $this->employeeWithGrants([], [1, $this->branch]), $this->reverser());

        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where('source_id', 5025)->countAllResults());
    }

    public function testSync_NamesTheBranchTheEmployeeDoesNotWorkInWhenItRejects(): void
    {
        $refusal = $this->refusal(fn () => $this->payments->sync(null, $this->payments->drafts($this->draft(true, '100.00', 5026), $this->treasury, $this->branch), $this->employeeWithGrants([], [1]), $this->reverser()));

        $this->assertSame('B2', $refusal->context['name']);
    }

    public function testSync_AsksTheDepositScopeOfTheTreasuryBeforePostingMoneyIntoIt(): void
    {
        $this->treasuries->update($this->treasury, ['name' => 'Branch drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->employeeWithGrants([])]]);

        $refusal = $this->refusal(fn () => $this->payments->sync(null, $this->payments->drafts($this->draft(true, '100.00', 5027), $this->treasury, $this->branch), $this->employeeWithGrants([]), $this->reverser()));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame(0, $this->accountingDb()->table('accounting_journals')->where('source_id', 5027)->countAllResults());
    }

    public function testSync_AsksTheScopeOfEachTreasuryTheReplacementMovesAndNothingForTheOneItLeavesAlone(): void
    {
        $employee = $this->employeeWithGrants([]);
        $drafts   = $this->payments->drafts($this->draft(true, '100.00', 5028), $this->treasury, $this->branch);

        $this->payments->sync(null, $drafts, null, $this->reverser());
        $this->treasuries->update($this->treasury, ['name' => 'Branch drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->employeeWithGrants([])]]);

        $lower = $this->payments->drafts($this->draft(true, '60.00', 5028, 2), $this->treasury, $this->branch);

        $refusal = $this->refusal(fn () => $this->payments->sync($this->activeOf(5028), $lower, $employee, $this->reverser($employee)));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame(2, $this->accountingDb()->table('accounting_journals')->where(['source_id' => 5028, 'status' => 'posted'])->countAllResults(), 'Nothing was reversed');
    }

    public function testIsPaired_SeesAJournalThatHasARecordOfTheBranchOnTheOtherSide(): void
    {
        $this->payments->post($this->payments->drafts($this->draft(true, '100.00', 5030), $this->treasury, $this->branch));
        $this->payments->post([$this->draft(true, '10.00', 5031)]);

        $this->assertTrue($this->payments->isPaired($this->activeOf(5030)));
        $this->assertFalse($this->payments->isPaired($this->activeOf(5031)));
        $this->assertTrue((new InterBranchLegs())->isPaired((int) $this->activeOf(5030)->journal_id));
    }

    public function testAssertMayPost_NeedsTheEmployeeToWorkInBothBranchesAndToMoveTheTreasury(): void
    {
        $drafts = $this->payments->drafts($this->draft(true, '100.00', 5040), $this->treasury, $this->branch);

        $this->payments->assertMayPost($drafts, null);
        $this->payments->assertMayPost($drafts, $this->employeeWithGrants([], [1, $this->branch]));

        $this->assertSame(AccountingError::InterBranchBranchesDenied, $this->refusal(fn () => $this->payments->assertMayPost($drafts, $this->employeeWithGrants([], [1])))->error);
        $this->assertSame(AccountingError::InterBranchBranchesDenied, $this->refusal(fn () => $this->payments->assertMayPost($drafts, $this->employeeWithGrants([], [$this->branch])))->error);
        $this->assertTrue((new BranchGuard())->canAccess($this->branch, $this->employeeWithGrants([], [$this->branch])));
    }
}
