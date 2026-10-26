<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ExpenseDocument;
use App\Libraries\Accounting\ExpensePostingBuilder;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingError;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\RoutingResolver;
use App\Libraries\Accounting\TreasuryService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ParityFixture;

/**
 * The journal a saved expense needs, as a draft: what it cost and the tax in it debited, and the
 * treasury it was paid from credited with all of it, owing nobody.
 */
class ExpensePostingBuilderTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace   = null;

    private ExpensePostingBuilder $builder;
    private int $treasuryId;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->reinstallChart();
        $this->setConfigValue('accounting_base_currency', 'SAR');

        $this->builder    = new ExpensePostingBuilder(new RoutingResolver());
        $this->treasuryId = (new TreasuryService())->create(['name' => 'Builder drawer', 'kind' => 'cash']);
    }

    protected function tearDown(): void
    {
        $this->clearJournals();

        parent::tearDown();
    }

    private function expense(string $amount, string $tax = '0', string $payment = 'Cash', ?int $categoryAccountId = null, ?int $treasuryId = -1, int $id = 12): ExpenseDocument
    {
        return new ExpenseDocument(
            expenseId: $id,
            date: '2026-10-20',
            amount: bcadd($amount, '0', 4),
            taxAmount: bcadd($tax, '0', 4),
            paymentType: $payment,
            categoryAccountId: $categoryAccountId,
            treasuryId: $treasuryId === -1 ? $this->treasuryId : $treasuryId,
            branchId: 1,
            deleted: false,
        );
    }

    private function draftOf(ExpenseDocument $expense): ?JournalDraft
    {
        return $this->builder->expense($expense, 1);
    }

    /**
     * @return list<string> "account:debit:credit" in line order.
     */
    private function linesOf(JournalDraft $draft): array
    {
        return array_map(static fn ($line): string => $line->accountId . ':' . bcadd($line->debit, '0', 4) . ':' . bcadd($line->credit, '0', 4), $draft->lines);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceJournals(): array
    {
        return ParityFixture::cases('T-25', 'expense_journal');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceJournals')]
    public function testExpense_DebitsTheCostAndTheTaxAndCreditsTheTreasuryAsTheReferenceSystemDoes(array $case): void
    {
        $roles = ['expenses_default' => $this->accountId('expenses_default'), 'tax_input' => $this->accountId('tax_input'), 'treasury' => $this->accountId('cash')];
        $draft = $this->draftOf($this->expense($case['inputs']['paid'], $case['inputs']['tax'], 'Cash', null, null));

        $expected = [];

        foreach ($case['expected']['debits'] as $debit) {
            $expected[] = $roles[$debit['account']] . ':' . bcadd($debit['amount'], '0', 4) . ':0.0000';
        }

        foreach ($case['expected']['credits'] as $credit) {
            $expected[] = $roles[$credit['account']] . ':0.0000:' . bcadd($credit['amount'], '0', 4);
        }

        $this->assertNotNull($draft);
        $this->assertSame($expected, $this->linesOf($draft));
        $this->assertSame(ExpensePostingBuilder::SOURCE, $draft->sourceType);
        $this->assertSame(12, $draft->sourceId);
        $this->assertSame('Expense #12', $draft->description);
    }

    public function testExpense_CarriesNoPartyOnAnyLine(): void
    {
        $case  = ParityFixture::cases('T-25', 'expense_journal_parties')['expense-journal-owes-nobody'][0];
        $draft = $this->draftOf($this->expense('230.00', $case['inputs']['tax']));

        $this->assertSame($case['expected']['party_lines'], count(array_filter($draft->lines, static fn ($line): bool => $line->partyType !== null)));
    }

    public function testExpense_CreditsTheAccountOfTheTreasuryItWasPaidFrom(): void
    {
        $draft = $this->draftOf($this->expense('230.00', '30.00'));

        $this->assertSame((int) (new TreasuryService())->accountOf($this->treasuryId), $draft->lines[2]->accountId);
        $this->assertSame('230.0000', $draft->lines[2]->credit);
    }

    public function testExpense_ChargesTheAccountOfTheCategoryWhenItHasOne(): void
    {
        $account = $this->customAccount('5', '5990', 'Marketing', false);
        $draft   = $this->draftOf($this->expense('100.00', '0', 'Cash', $account));

        $this->assertSame($account, $draft->lines[0]->accountId);
        $this->assertSame('100.0000', $draft->lines[0]->debit);
    }

    public function testExpense_FallsBackToTheRoutedExpensesAccountWithoutACategoryAccount(): void
    {
        $draft = $this->draftOf($this->expense('100.00'));

        $this->assertSame($this->accountId('expenses_default'), $draft->lines[0]->accountId);
    }

    public function testExpense_PostsTwoLinesWhenThereIsNoTax(): void
    {
        $draft = $this->draftOf($this->expense('100.00', '0'));

        $this->assertCount(2, $draft->lines);
        $this->assertSame('100.0000', $draft->lines[0]->debit);
        $this->assertSame('100.0000', $draft->lines[1]->credit);
    }

    public function testExpense_PostsTheTaxAloneWhenItIsAllOfTheAmount(): void
    {
        $draft = $this->draftOf($this->expense('15.00', '15.00'));

        $this->assertSame([$this->accountId('tax_input') . ':15.0000:0.0000', (new TreasuryService())->accountOf($this->treasuryId) . ':0.0000:15.0000'], $this->linesOf($draft));
    }

    public function testExpense_KeepsTheTaxExactlyWithoutDriftAtFourDecimals(): void
    {
        $draft = $this->draftOf($this->expense('115.33', '15.04'));

        $this->assertSame(['100.2900', '15.0400'], [$draft->lines[0]->debit, $draft->lines[1]->debit]);
        $this->assertSame('115.3300', $draft->lines[2]->credit);
    }

    public function testExpense_TurnsTheSidesAroundForMoneyThatCameBack(): void
    {
        $draft = $this->draftOf($this->expense('-230.00', '-30.00'));

        $this->assertSame([
            $this->accountId('expenses_default') . ':0.0000:200.0000',
            $this->accountId('tax_input') . ':0.0000:30.0000',
            (new TreasuryService())->accountOf($this->treasuryId) . ':230.0000:0.0000',
        ], $this->linesOf($draft));
    }

    public function testExpense_RejectsATaxMoreThanTheAmount(): void
    {
        $this->expectPostingFailure(PostingError::ExpenseTaxInvalid, fn () => $this->draftOf($this->expense('100.00', '100.01')));
    }

    public function testExpense_RejectsATaxWithTheOppositeSignOfTheAmount(): void
    {
        $this->expectPostingFailure(PostingError::ExpenseTaxInvalid, fn () => $this->draftOf($this->expense('100.00', '-5.00')));
    }

    public function testExpense_PostsNothingForAnAmountOfZero(): void
    {
        $this->assertNull($this->draftOf($this->expense('0.00', '0.00')));
    }

    public function testExpense_OwesOtherCreditorsWhileTheExpenseIsDue(): void
    {
        $draft = $this->draftOf($this->expense('100.00', '0', 'Due', null, null));

        $this->assertSame($this->accountId('other_payables'), $draft->lines[1]->accountId);
        $this->assertSame('100.0000', $draft->lines[1]->credit);
    }

    public function testExpense_IgnoresTheTreasuryWhileTheExpenseIsDue(): void
    {
        $draft = $this->draftOf($this->expense('100.00', '0', 'Due'));

        $this->assertSame($this->accountId('other_payables'), $draft->lines[1]->accountId);
    }

    public function testExpense_CreditsTheRoutedAccountOfTheWayOfPaymentWithoutATreasury(): void
    {
        $draft = $this->draftOf($this->expense('100.00', '0', 'Cash', null, null));

        $this->assertSame($this->accountId('cash'), $draft->lines[1]->accountId);
    }

    public function testExpense_RejectsAWayOfPaymentTheBooksDoNotKnow(): void
    {
        $this->expectPostingFailure(PostingError::PaymentTypeUnknown, fn () => $this->draftOf($this->expense('100.00', '0', 'Barter')));
    }

    public function testExpense_RejectsAGiftCardAsTheWayOfPayment(): void
    {
        $this->expectPostingFailure(PostingError::PaymentTypeUnknown, fn () => $this->draftOf($this->expense('100.00', '0', 'Gift Card')));
    }

    public function testExpense_RejectsRewardPointsAsTheWayOfPayment(): void
    {
        $this->expectPostingFailure(PostingError::PaymentTypeUnknown, fn () => $this->draftOf($this->expense('100.00', '0', 'Reward Points')));
    }

    public function testExpense_RejectsWhenTheAccountOfTaxPaidCannotTakeEntries(): void
    {
        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'tax.input')->update(['account_id' => null]);

        $this->expectPostingFailure(PostingError::RoutingAccountUnusable, fn () => $this->draftOf($this->expense('115.00', '15.00')));
    }

    private function expectPostingFailure(PostingError $error, callable $action): void
    {
        try {
            $action();
        } catch (PostingException $exception) {
            $this->assertSame($error, $exception->error);

            return;
        }

        $this->fail('The expense was not refused.');
    }
}
