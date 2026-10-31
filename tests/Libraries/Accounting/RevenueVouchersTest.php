<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RevenueCategories;
use App\Libraries\Accounting\RevenuePostingBuilder;
use App\Libraries\Accounting\RevenueVouchers;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ParityCaseTrait;

/**
 * The receipt voucher: money received into a treasury that is not a sale. Each voucher has one
 * journal, reversed and posted again when the voucher changes and reversed when it is deleted, and
 * it belongs to the branch of its treasury.
 */
class RevenueVouchersTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ParityCaseTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private RevenueVouchers $vouchers;
    private RevenueCategories $categories;
    private int $treasuryId;
    private string $today;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startExpenses();

        $this->vouchers   = new RevenueVouchers();
        $this->categories = new RevenueCategories();
        $this->treasuryId = $this->treasury('Revenue drawer');
        $this->today      = date('Y-m-d');
    }

    protected function tearDown(): void
    {
        $this->releaseOpenTransactions();
        $this->stopExpenses();

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();

        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $changes
     *
     * @return array<string, mixed>
     */
    private function input(array $changes = []): array
    {
        return [...[
            'date'         => $this->today,
            'amount'       => '100.00',
            'tax_amount'   => '0',
            'payment_type' => 'Cash',
            'treasury_id'  => $this->treasuryId,
            'category_id'  => 0,
            'account_id'   => 0,
            'description'  => 'Rent of the stand',
        ], ...$changes];
    }

    private function save(array $changes = [], ?int $id = null, ?int $employeeId = null): int
    {
        return $this->vouchers->save($this->input($changes), $id, $employeeId ?? $this->clerkId);
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

    private function revenueCategory(string $name = 'Services', ?int $accountId = null): int
    {
        return $this->categories->save(['category_name' => $name, 'category_description' => '', 'account_id' => $accountId], null, null);
    }

    private function treasuryAccount(?int $treasuryId = null): int
    {
        return (int) (new TreasuryService())->accountOf($treasuryId ?? $this->treasuryId);
    }

    /**
     * @return list<string> The lines of the journal of the voucher that stands, as "account=debit/credit".
     */
    private function standing(int $revenueId): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $revenueId, 'status' => 'posted'])->get()->getRow();

        $this->assertNotNull($journal, 'No journal stands for the voucher');

        return array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
        );
    }

    private function journalCount(string $status): int
    {
        return $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'status' => $status])->countAllResults();
    }

    public function testSave_PutsTheWholeAmountIntoTheTreasuryAndSplitsTheTaxOut(): void
    {
        $category = $this->revenueCategory('Rent', $this->customAccount('42', '4210', 'Rent income'));
        $id       = $this->save(['amount' => '1150.00', 'tax_amount' => '150.00', 'category_id' => $category]);

        $this->assertSame([
            $this->treasuryAccount() . '=1150.00/0.00',
            $this->accountIdByCode('4210') . '=0.00/1000.00',
            $this->accountId('tax_output') . '=0.00/150.00',
        ], $this->standing($id));
        $this->assertSame('1150.00', $this->assertTreasuryTiesToItsAccount($this->treasuryId));
    }

    public function testSave_CreditsTheChosenAccountBeforeTheAccountOfTheCategory(): void
    {
        $category = $this->revenueCategory('Rent', $this->customAccount('42', '4210', 'Rent income'));
        $chosen   = $this->customAccount('42', '4211', 'Commissions');
        $id       = $this->save(['category_id' => $category, 'account_id' => $chosen]);

        $this->assertSame([$this->treasuryAccount() . '=100.00/0.00', $chosen . '=0.00/100.00'], $this->standing($id));
    }

    public function testSave_CreditsTheRoutedAccountOfOtherRevenueWhenNothingIsChosen(): void
    {
        $id = $this->save();

        $this->assertSame([$this->treasuryAccount() . '=100.00/0.00', $this->accountId('revenues_default') . '=0.00/100.00'], $this->standing($id));
    }

    public function testSave_TakesTheTreasuryTheEmployeeWorksFromWhenNoneIsNamed(): void
    {
        (new TreasuryService())->setDefaultFor($this->clerkId, $this->treasuryId);

        $input = $this->input();

        unset($input['treasury_id']);

        $id = $this->vouchers->save($input, null, $this->clerkId);

        $this->assertSame($this->treasuryId, (int) $this->vouchers->find($id)->treasury_id);
    }

    public function testSave_FallsBackToTheAccountOfTheWayOfPaymentWhenThereIsNoTreasury(): void
    {
        $input = $this->input();

        unset($input['treasury_id']);

        $id = $this->vouchers->save($input, null, $this->clerkId);

        $this->assertNull($this->vouchers->find($id)->treasury_id);
        $this->assertSame([$this->accountId('cash') . '=100.00/0.00', $this->accountId('revenues_default') . '=0.00/100.00'], $this->standing($id));
    }

    public function testSave_BelongsToTheBranchOfItsTreasury(): void
    {
        $branch   = $this->createBranch();
        $treasury = $this->treasury('Branch drawer', $branch);
        $id       = $this->save(['treasury_id' => $treasury]);

        $this->assertSame($branch, (int) $this->vouchers->find($id)->branch_id);
        $this->assertSame($branch, (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $id])->get()->getRow()->branch_id);
    }

    public function testSave_RequiresTheBranchOfTheTreasury(): void
    {
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([], [$other]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->save([], null, $worker)));
        $this->assertSame(0, $this->countRows('revenues'));
    }

    public function testSave_ChecksTheAmountTheTaxTheDateTheWayOfPaymentAndTheCategory(): void
    {
        foreach (['0', '0.00', '-5', 'abc', '', '1.00001'] as $amount) {
            $this->assertSame(AccountingError::RevenueAmountInvalid, $this->refusal(fn () => $this->save(['amount' => $amount]))->error, "amount {$amount}");
        }

        foreach (['-1', '100.01', 'x'] as $tax) {
            $this->assertSame(AccountingError::RevenueAmountInvalid, $this->refusal(fn () => $this->save(['tax_amount' => $tax]))->error, "tax {$tax}");
        }

        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->save(['date' => '2026-13-01']))->error);
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->save(['date' => '']))->error);
        $this->assertSame(AccountingError::RevenuePaymentInvalid, $this->refusal(fn () => $this->save(['payment_type' => 'Due']))->error);
        $this->assertSame(AccountingError::RevenuePaymentInvalid, $this->refusal(fn () => $this->save(['payment_type' => 'No such way']))->error);
        $this->assertSame(AccountingError::RevenueCategoryNotFound, $this->refusal(fn () => $this->save(['category_id' => 99999]))->error);
        $this->assertSame(0, $this->countRows('revenues'));
    }

    public function testSave_AcceptsATaxEqualToTheAmountAndPostsNoRevenueLine(): void
    {
        $id = $this->save(['amount' => '15.00', 'tax_amount' => '15.00']);

        $this->assertSame([$this->treasuryAccount() . '=15.00/0.00', $this->accountId('tax_output') . '=0.00/15.00'], $this->standing($id));
    }

    public function testSave_RejectsAChosenAccountThatIsNotAnActiveRevenueAccountThatTakesEntries(): void
    {
        $this->assertSame(AccountingError::RevenueAccountInvalid, $this->refusal(fn () => $this->save(['account_id' => $this->accountId('cogs')]))->error);
        $this->assertSame(AccountingError::RevenueAccountInvalid, $this->refusal(fn () => $this->save(['account_id' => $this->accountIdByCode('42')]))->error);
        $this->assertSame(AccountingError::RevenueAccountInvalid, $this->refusal(fn () => $this->save(['account_id' => $this->accountId('sales_revenue')]))->error);
        $this->assertSame(0, $this->countRows('revenues'));
    }

    public function testSave_RefusesATreasuryTheEmployeeMayNotDepositIntoAndLeavesNothing(): void
    {
        $other = $this->employeeWithGrants([]);

        $this->restrictTreasury($this->treasuryId, 'deposit', [$other]);

        $failure = $this->refusal(fn () => $this->save());

        $this->assertSame(AccountingError::TreasuryDepositDenied, $failure->error);
        $this->assertSame(0, $this->countRows('revenues'));
        $this->assertSame(0, $this->journalCount('posted'));
    }

    public function testSave_RefusesADeactivatedOrMissingTreasury(): void
    {
        (new TreasuryService())->deactivate($this->treasuryId);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->save())->error);
        $this->assertSame(AccountingError::TreasuryNotFound, $this->refusal(fn () => $this->save(['treasury_id' => 99999]))->error);
    }

    public function testSave_WritesNothingWhenThePeriodIsClosedAndRecordsTheRefusal(): void
    {
        $this->closeThrough($this->today);

        $failure = $this->refusal(fn () => $this->save());

        $this->assertInstanceOf(PostingException::class, $failure);
        $this->assertSame(PostingError::PeriodClosed, $failure->error);
        $this->assertSame(0, $this->countRows('revenues'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_posting_failures')->where('source_type', RevenuePostingBuilder::SOURCE)->countAllResults());
    }

    public function testSave_ReversesAndPostsAgainAsTheNextRevisionWhenTheJournalChanges(): void
    {
        $id = $this->save();

        $this->save(['amount' => '60.00'], $id);

        $this->assertSame([$this->treasuryAccount() . '=60.00/0.00', $this->accountId('revenues_default') . '=0.00/60.00'], $this->standing($id));
        $this->assertSame(['1:reversed', '2:posted'], array_map(static fn (object $journal): string => $journal->revision . ':' . $journal->status, $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $id])->orderBy('revision')->get()->getResult()));
        $this->assertSame('60.00', $this->assertTreasuryTiesToItsAccount($this->treasuryId));
    }

    public function testSave_WritesNothingNewWhenOnlyTheTextChanges(): void
    {
        $id = $this->save();

        $this->save(['description' => 'Another text'], $id);

        $this->assertSame(1, $this->journalCount('posted'));
        $this->assertSame(0, $this->journalCount('reversed'));
        $this->assertSame('Another text', $this->vouchers->find($id)->description);
    }

    public function testSave_MovesTheMoneyWhenTheTreasuryChangesAndKeepsTheOldOneWhenNoneIsNamed(): void
    {
        $second = $this->treasury('Second drawer');
        $id     = $this->save();

        $this->save(['treasury_id' => $second], $id);

        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($this->treasuryId));
        $this->assertSame('100.00', $this->assertTreasuryTiesToItsAccount($second));

        $input = $this->input(['description' => 'No treasury named']);

        unset($input['treasury_id']);

        $this->vouchers->save($input, $id, $this->clerkId);

        $this->assertSame($second, (int) $this->vouchers->find($id)->treasury_id);
        $this->assertSame([1, 1], [$this->journalCount('reversed'), $this->journalCount('posted')]);
    }

    public function testSave_RequiresTheBranchOfTheStoredVoucherToChangeIt(): void
    {
        $branch   = $this->createBranch();
        $treasury = $this->treasury('Branch drawer', $branch);
        $id       = $this->save(['treasury_id' => $treasury]);
        $worker   = $this->employeeWithGrants([], [1]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->save(['amount' => '5.00', 'treasury_id' => $this->treasuryId], $id, $worker)));
        $this->assertSame('100.0000', $this->vouchers->find($id)->amount);
    }

    public function testSave_RejectsAVoucherThatDoesNotExistOrWasDeleted(): void
    {
        $this->assertSame(AccountingError::RevenueNotFound, $this->refusal(fn () => $this->save([], 99999))->error);

        $id = $this->save();

        $this->vouchers->delete([$id], $this->clerkId);

        $this->assertSame(AccountingError::RevenueNotFound, $this->refusal(fn () => $this->save(['amount' => '1.00'], $id))->error);
    }

    public function testDelete_ReversesTheJournalAndGivesTheMoneyBack(): void
    {
        $id = $this->save();

        $this->vouchers->delete([$id], $this->clerkId);

        $this->assertSame(1, $this->journalCount('reversed'));
        $this->assertSame(0, $this->journalCount('posted'));
        $this->assertSame('0.00', $this->assertTreasuryTiesToItsAccount($this->treasuryId));
        $this->assertSame('1', (string) $this->vouchers->find($id)->deleted);
    }

    public function testDelete_IsAllOrNothingAcrossTheVouchers(): void
    {
        $first  = $this->save();
        $second = $this->save(['date' => date('Y-m-d', strtotime('-40 days'))]);

        $this->closeThrough(date('Y-m-d', strtotime('-30 days')));

        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([], [$other]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->vouchers->delete([$first, $second], $worker)));

        $this->assertSame([0, 0], [(int) $this->vouchers->find($first)->deleted, (int) $this->vouchers->find($second)->deleted]);
        $this->assertSame(2, $this->journalCount('posted'));
    }

    public function testDelete_TakesBackAJournalOfAClosedPeriodOnTodayInstead(): void
    {
        $id = $this->save(['date' => date('Y-m-d', strtotime('-40 days'))]);

        $this->closeThrough(date('Y-m-d', strtotime('-30 days')));
        $this->vouchers->delete([$id], $this->clerkId);

        $this->assertSame($this->today, $this->accountingDb()->table('accounting_journals')->where('source_type', 'reversal')->get()->getRow()->journal_date);
    }

    public function testDelete_RequiresTheBranchOfTheVoucher(): void
    {
        $id     = $this->save();
        $other  = $this->createBranch();
        $worker = $this->employeeWithGrants([], [$other]);

        $this->assertInstanceOf(BranchException::class, $this->refusal(fn () => $this->vouchers->delete([$id], $worker)));
        $this->assertSame(0, $this->journalCount('reversed'));
    }

    public function testSave_GivesEachVoucherTheNextNumberOfTheRevenueSequence(): void
    {
        $manager = $this->employeeWithGrants(['config']);

        (new DocumentNumbering())->configure(DocumentType::Revenue, ['enabled' => true, 'prefix' => 'RV-', 'padding' => 3], $manager);

        $first  = $this->save();
        $second = $this->save();

        $this->assertSame([$first => 'RV-001', $second => 'RV-002'], (new DocumentNumbering())->numbers(DocumentType::Revenue, 'revenues', [$first, $second]));
    }

    public function testSave_RecordsTheCreationTheRepostingAndTheDeletionInTheAuditLog(): void
    {
        $id = $this->save();

        $this->save(['amount' => '70.00'], $id);
        $this->vouchers->delete([$id], $this->clerkId);

        $actions = array_column($this->accountingDb()->table('accounting_audit_log')->select('action')->where(['entity_type' => 'revenue', 'entity_id' => $id])->orderBy('audit_id')->get()->getResultArray(), 'action');

        $this->assertSame(['created', 'reposted', 'deleted'], $actions);
    }

    public function testSearch_ListsTheVouchersOfTheBranchesTheReaderMaySeeWithTheirFilters(): void
    {
        $category = $this->revenueCategory('Rent');
        $branch   = $this->createBranch();
        $treasury = $this->treasury('Branch drawer', $branch);
        $first    = $this->save(['category_id' => $category, 'description' => 'January rent', 'date' => '2026-01-10']);
        $second   = $this->save(['treasury_id' => $treasury, 'description' => 'Other branch', 'date' => '2026-02-10']);
        $third    = $this->save(['description' => 'Deleted one', 'date' => '2026-03-10']);

        $this->vouchers->delete([$third], $this->clerkId);

        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->revenue_id, $result['rows']);

        $this->assertSame([$second, $first], $ids($this->vouchers->search(null, [], 'revenue_id', 'desc', 25, 0)));
        $this->assertSame([$first], $ids($this->vouchers->search([1], [], 'revenue_id', 'desc', 25, 0)));
        $this->assertSame([], $ids($this->vouchers->search([], [], 'revenue_id', 'desc', 25, 0)));
        $this->assertSame([$third], $ids($this->vouchers->search(null, ['deleted' => true], 'revenue_id', 'desc', 25, 0)));
        $this->assertSame([$first], $ids($this->vouchers->search(null, ['category_id' => $category], 'revenue_id', 'desc', 25, 0)));
        $this->assertSame([$second], $ids($this->vouchers->search(null, ['date_from' => '2026-02-01', 'date_to' => '2026-02-28'], 'revenue_id', 'desc', 25, 0)));
        $this->assertSame([$first], $ids($this->vouchers->search(null, ['search' => 'January'], 'revenue_id', 'desc', 25, 0)));
        $this->assertSame(2, $this->vouchers->search(null, [], 'amount', 'asc', 1, 0)['total']);
        $this->assertCount(1, $this->vouchers->search(null, [], 'amount', 'asc', 1, 1)['rows']);
    }

    public function testJournalsOf_ListsTheJournalsOfAVoucherOldestFirst(): void
    {
        $id = $this->save();

        $this->save(['amount' => '20.00'], $id);

        $journals = $this->vouchers->journalsOf($id);

        $this->assertSame([[1, 'reversed'], [2, 'posted']], array_map(static fn (object $journal): array => [(int) $journal->revision, $journal->status], $journals));
    }

    public function testRevenue_SaysWhatTheReferenceSystemWroteForAReceiptVoucher(): void
    {
        $case = $this->referenceCase('T-44', 'revenue_voucher', 'a-revenue-voucher-with-tax-in-a-category', 'differs');

        $category = $this->revenueCategory('Income category', $this->customAccount('42', '4204', 'Income category account'));
        $chosen   = $this->customAccount('42', '4212', 'Chosen account');
        $roles    = [
            'treasury'   => $this->treasuryAccount(),
            'income'     => $this->accountIdByCode('4204'),
            'tax_output' => $this->accountId('tax_output'),
        ];

        $withTax = $this->save(['amount' => $case['inputs']['voucher']['amount'], 'tax_amount' => $case['inputs']['voucher']['tax'], 'category_id' => $category]);
        $chosenA = $this->save(['amount' => '100.00', 'account_id' => $chosen]);
        $none    = $this->save(['amount' => $case['inputs']['without_a_category']['amount']]);

        $lines = function (int $revenueId, array $accounts): array {
            $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $revenueId, 'status' => 'posted'])->get()->getRow();

            return $this->roleLines((int) $journal->journal_id, $accounts);
        };

        $actual = [
            'voucher_with_tax'            => $lines($withTax, $roles),
            'voucher_on_a_chosen_account' => $lines($chosenA, [...$roles, 'income' => $chosen]),
            'voucher_without_a_category'  => $lines($none, [...$roles, 'income' => $this->accountId('revenues_default')]),
        ];

        $this->save(['amount' => '1000.00', 'tax_amount' => '0', 'category_id' => $category], $withTax);
        $this->vouchers->delete([$chosenA], $this->clerkId);

        $firstStillStands = $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $withTax, 'revision' => 1, 'status' => 'posted'])->countAllResults() === 1;

        $this->assertSameAsFixture($case['expected']['journals'], $actual, 'the journals of a receipt voucher');
        $this->assertSame($case['expected']['edit_changes_the_same_journal'], $firstStillStands, 'an edit leaves the first journal standing');
        $this->assertSame($case['expected']['delete_erases_the_journal'], $this->accountingDb()->table('accounting_journals')->where(['source_type' => RevenuePostingBuilder::SOURCE, 'source_id' => $chosenA])->countAllResults() === 0, 'a delete erases the journal');
    }
}
