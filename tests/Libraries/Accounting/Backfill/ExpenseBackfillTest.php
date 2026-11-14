<?php

namespace Tests\Libraries\Accounting\Backfill;

use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\CrossBranchPayments;
use App\Libraries\Accounting\Backfill\BackfillDocument;
use App\Libraries\Accounting\Backfill\BackfillFamily;
use App\Libraries\Accounting\Backfill\BackfillPlan;
use App\Libraries\Accounting\Backfill\BackfillRefusal;
use App\Libraries\Accounting\Backfill\BackfillScope;
use App\Libraries\Accounting\Backfill\ExpenseBackfill;
use App\Models\Expense;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\BackfillFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * @internal
 */
final class ExpenseBackfillTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use BackfillFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use SaleAccountingFixtureTrait;

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

        $this->startBackfill();

        $this->expenses = model(Expense::class);
        $this->clerkId  = $this->employeeWithGrants([]);
    }

    protected function tearDown(): void
    {
        $this->clearExpenseRows();
        $this->stopBackfill();

        parent::tearDown();
    }

    public function testDocumentAmount_GivesTheAmountOfTheExpenseAndZeroForOneThatIsGone(): void
    {
        $expenseId = $this->legacyExpense('230', '30', '2026-03-12');

        $this->assertSame('230.0000', $this->adapter()->documentAmount(new BackfillDocument(BackfillFamily::Expenses, $expenseId, '2026-03-12', 1)));
        $this->assertSame('0.0000', $this->adapter()->documentAmount(new BackfillDocument(BackfillFamily::Expenses, 99999999, '2026-03-12', 1)));
    }

    private function adapter(): ExpenseBackfill
    {
        return new ExpenseBackfill($this->accountingDb());
    }

    private function plan(int $expenseId): BackfillPlan
    {
        $row = $this->accountingDb()->table('expenses')->where('expense_id', $expenseId)->get()->getRow();

        return $this->adapter()->plan(new BackfillDocument(BackfillFamily::Expenses, $expenseId, substr((string) $row->date, 0, 10), (int) ($row->branch_id ?? 1)), null, 0);
    }

    public function testPlan_MakesTheJournalTheLivePostingMakesOfTheSameExpense(): void
    {
        $legacy = $this->legacyExpense('230', '30', '2026-03-12');
        $live   = $this->saveExpense($this->expenseData('230', '30', 'Cash', null, '2026-03-12 10:00:00'));

        $plan = $this->plan($legacy);

        $this->assertNotNull($live);
        $this->assertSame(['expense:general|' . implode(',', array_map(static fn (string $line): string => str_replace(['=', '/'], ':', $line), $this->postedLines('expense', $live)))], $this->plannedLines($plan));
    }

    public function testPlan_GivesAnExpenseThatWasNeverStampedTheDefaultBranchAndStampsItOnApproval(): void
    {
        $legacy = $this->legacyExpense();
        $plan   = $this->plan($legacy);

        $this->assertSame(['table' => 'expenses', 'key' => 'expense_id', 'id' => $legacy, 'null' => 'branch_id', 'columns' => ['branch_id' => 1]], $plan->stamp);
        $this->assertSame([], $this->plan($this->legacyExpense('100', '0', '2026-03-12', 1))->stamp);
    }

    public function testPlan_RefusesAnExpenseThatComesToNothingOrIsDeleted(): void
    {
        $zero = $this->legacyExpense('0', '0');

        $this->assertSame([], $this->adapter()->candidates(new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Expenses]), 0, 10, 0));

        $deleted = $this->legacyExpense();

        $this->accountingDb()->table('expenses')->where('expense_id', $deleted)->update(['deleted' => 1]);

        $this->assertSame(BackfillRefusal::DocumentChanged, $this->adapter()->plan(new BackfillDocument(BackfillFamily::Expenses, $deleted, '2026-03-12', 1), null, 0)->refusal);
        $this->assertGreaterThan(0, $zero);
    }

    public function testPlan_RefusesAnExpenseBeforeTheStartDateOrInAClosedPeriod(): void
    {
        $legacy = $this->legacyExpense();

        $this->closeThrough('2026-03-31');
        $this->assertSame(BackfillRefusal::PeriodClosed, $this->plan($legacy)->refusal);

        $this->setConfigValue('accounting_start_date', '2026-06-01');
        $this->assertSame(BackfillRefusal::BeforeStartDate, $this->plan($legacy)->refusal);
    }

    public function testPlan_PutsAnExpenseDatedBeforeTheBooksBeginOutWhateverElseIsWrongWithIt(): void
    {
        $legacy = $this->legacyExpense();

        $this->withoutKeys(fn () => $this->accountingDb()->table('expenses')->where('expense_id', $legacy)->update(['treasury_id' => 987654]));
        $this->setConfigValue('accounting_start_date', '2026-06-01');

        $this->assertSame(BackfillRefusal::BeforeStartDate, $this->plan($legacy)->refusal);
    }

    public function testPlan_RefusesAnExpensePaidFromATreasuryThatIsGoneOrOfAnotherBranch(): void
    {
        $other  = $this->createBranch();
        $legacy = $this->legacyExpense();

        $this->withoutKeys(fn () => $this->accountingDb()->table('expenses')->where('expense_id', $legacy)->update(['treasury_id' => 987654]));
        $this->assertSame(BackfillRefusal::TreasuryMissing, $this->plan($legacy)->refusal);

        $this->accountingDb()->table('expenses')->where('expense_id', $legacy)->update(['treasury_id' => $this->treasuryIn($other), 'treasury_branch_id' => $other, 'branch_id' => 1]);
        $this->assertSame(BackfillRefusal::CrossBranchPayment, $this->plan($legacy)->refusal);
    }

    public function testCandidates_ListsTheExpensesOfTheBranchOfTheScopeWithAnUnstampedOneInTheDefaultBranch(): void
    {
        $other   = $this->createBranch();
        $legacy  = $this->legacyExpense();
        $stamped = $this->legacyExpense('80', '0', '2026-03-12', $other);
        $ids     = static fn (array $documents): array => array_map(static fn (BackfillDocument $document): int => $document->id, $documents);

        $this->assertSame([$legacy], $ids($this->adapter()->candidates(new BackfillScope('2026-01-01', '2026-12-31', 1, [BackfillFamily::Expenses]), 0, 10, 0)));
        $this->assertSame([$stamped], $ids($this->adapter()->candidates(new BackfillScope('2026-01-01', '2026-12-31', $other, [BackfillFamily::Expenses]), 0, 10, 0)));
    }

    public function testCandidates_LeavesOutAnExpenseThatHasAJournalAlready(): void
    {
        $live = $this->saveExpense($this->expenseData('100', '0', 'Cash', null, '2026-03-12 10:00:00'));

        $this->assertSame([], $this->adapter()->candidates(new BackfillScope('2026-01-01', '2026-12-31', null, [BackfillFamily::Expenses]), 0, 10, 0));
        $this->assertNotNull($live);
    }

    public function testPlan_RefusesAnExpenseOfABranchThatIsInactive(): void
    {
        $other  = $this->createBranch();
        $legacy = $this->legacyExpense('100', '0', '2026-03-12', $other);

        $this->accountingDb()->table('branches')->where('branch_id', $other)->update(['active' => 0]);

        $this->assertSame(BackfillRefusal::BranchInactive, $this->plan($legacy)->refusal);
    }

    public function testPlan_RefusesAnExpenseWhoseCompanionJournalIsInTheBooksAlready(): void
    {
        $legacy    = $this->legacyExpense();
        $companion = CrossBranchPayments::companionsOf('expense')[0];

        (new PostingService())->post($this->balancedDraft($companion, $legacy));

        $this->assertSame(BackfillRefusal::PartlyPosted, $this->plan($legacy)->refusal);
    }
}
