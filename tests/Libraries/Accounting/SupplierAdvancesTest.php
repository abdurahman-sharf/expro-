<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
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
 * A payment to a supplier that belongs to no purchase, and what a supplier owed us or we owed
 * them before the books started: each a journal of its own against the account of the supplier,
 * a credit that is spent on the purchases of the supplier, the oldest first, or a purchase
 * without lines that payments are made against.
 */
class SupplierAdvancesTest extends CIUnitTestCase
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

    private SupplierAdvances $advances;
    private int $clerk;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();

        $this->advances = new SupplierAdvances();
        $this->clerk    = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
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
     * @return list<string> "debit:credit" of the journal lines of a source, by account, as "account=debit/credit".
     */
    private function linesOf(string $source, int $sourceId): array
    {
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $sourceId, 'status' => 'posted'])->get()->getRow();

        $this->assertNotNull($journal, 'No journal stands for ' . $source . ' ' . $sourceId);

        return array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
        );
    }

    public function testPay_DebitsTheSupplierAndCreditsTheAccountOfTheWayItWasPaid(): void
    {
        $advanceId = $this->advances->pay($this->supplierId, '300.00', '2026-10-20', 'Cash', null, 'CHQ1', 'on account', $this->clerk);

        $this->assertSame([$this->supplierAccount() . '=300.00/0.00', $this->accountId('cash') . '=0.00/300.00'], $this->linesOf(SupplierAdvances::SOURCE, $advanceId));
        $this->assertSame('-300.00', $this->owed());

        $row = $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->get()->getRow();

        $this->assertSame(['payment', 'completed', 'CHQ1', 'on account'], [$row->kind, $row->status, $row->reference_code, $row->note]);
        $this->assertSame('300.0000', $this->supplierPosition()->credit());
        $this->assertSupplierTies();
    }

    public function testPay_CreditsTheTreasuryItWasPaidFromAndTakesItsBranch(): void
    {
        $drawer = (new TreasuryService())->create(['name' => 'Payment drawer', 'kind' => 'cash'], $this->clerk);

        $advanceId = $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', $drawer, '', '', $this->clerk);

        $this->assertSame([$this->supplierAccount() . '=120.00/0.00', (new TreasuryService())->accountOf($drawer) . '=0.00/120.00'], $this->linesOf(SupplierAdvances::SOURCE, $advanceId));
        $this->assertSame('-120.0000', (new TreasuryService())->position($drawer)->balance);
        $this->assertSame($drawer, (int) $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->get()->getRow()->treasury_id);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function referenceSupplierPayments(): array
    {
        return array_filter(ParityFixture::cases('T-21', 'treasury_payment'), static fn (array $set): bool => $set[0]['inputs']['payer'] === 'supplier');
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('referenceSupplierPayments')]
    public function testPay_DebitsTheSupplierAndCreditsTheChosenTreasuryAsTheReferenceSystemDoes(array $case): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Fixture drawer', 'kind' => 'cash'], $this->clerk);
        $amount     = $case['inputs']['amount'];

        $advanceId = $this->advances->pay($this->supplierId, $amount, '2026-10-20', 'Cash', $drawer, '', '', $this->clerk);

        $this->assertSame([$this->supplierAccount() . '=' . $amount . '/0.00', $treasuries->accountOf($drawer) . '=0.00/' . $amount], $this->linesOf(SupplierAdvances::SOURCE, $advanceId));
        $this->assertSame($case['expected'], ['debit' => 'supplier', 'credit' => 'treasury', 'amount' => $amount]);
        $this->assertSame($case['reference'], $case['expected']);
    }

    public function testPay_RefusesATreasuryTheEmployeeMayNotWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $refusal = $this->refusal(fn () => $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', $drawer, '', '', $this->clerk));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('Locked drawer', $refusal->context['name']);
        $this->assertSame(0, $this->countRows('supplier_advances'));
        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);
    }

    public function testPay_AcceptsATreasuryTheEmployeeIsAllowedToWithdrawFrom(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Own drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$this->clerk]], $this->clerk);

        $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', $drawer, '', '', $this->clerk);

        $this->assertSame('-120.0000', $treasuries->position($drawer)->balance);
    }

    public function testPay_AppliesTheScopeOfTheMainTreasuryWhenNoTreasuryIsNamed(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $main       = $treasuries->ensureMain();

        $treasuries->update($main, ['name' => 'Main', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $refusal = $this->refusal(fn () => $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', null, '', '', $this->clerk));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testPay_LetsTheBooksPayWhateverTheScopeOfTheTreasury(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', $drawer);

        $this->assertSame('-120.0000', $treasuries->position($drawer)->balance);
    }

    public function testPay_IsSpentOnTheOpenPurchasesOfTheSupplierTheOldestFirst(): void
    {
        $first  = $this->purchaseOf('100.00');
        $second = $this->purchaseOf('200.00');

        $advanceId = $this->advances->pay($this->supplierId, '150.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->assertSame([CreditSource::ADVANCE . ':' . $advanceId . '->' . $first . ':100.00', CreditSource::ADVANCE . ':' . $advanceId . '->' . $second . ':50.00'], $this->supplierApplications());
        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($first)->open(), '0', 2));
        $this->assertSame('150.00', bcadd($this->supplierPosition()->purchase($second)->open(), '0', 2));
        $this->assertSame('0.0000', $this->supplierPosition()->credit());
        $this->assertSame('150.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testPay_WaitsForThePurchaseThatComesAfterItAndSpendsItThen(): void
    {
        $advanceId = $this->advances->pay($this->supplierId, '80.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);
        $purchase  = $this->purchaseOf('100.00');

        $this->assertSame([CreditSource::ADVANCE . ':' . $advanceId . '->' . $purchase . ':80.00'], $this->supplierApplications());
        $this->assertSame('20.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testCancel_ReversesTheJournalAndGivesBackWhatWasSpentOnPurchases(): void
    {
        $purchase  = $this->purchaseOf('100.00');
        $advanceId = $this->advances->pay($this->supplierId, '60.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->assertSame('40.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));

        $this->advances->cancel($advanceId, 'paid twice', $this->clerk);

        $this->assertSame('100.00', $this->owed());
        $this->assertSame('100.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertSame('0.0000', $this->supplierPosition()->credit());
        $this->assertSame('cancelled', $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->get()->getRow()->status);
        $this->assertSame(['reversed'], array_column($this->accountingDb()->table('accounting_journals')->where('source_type', 'supplier_advance')->get()->getResultArray(), 'status'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id IS NOT NULL', null, false)->countAllResults());
        $this->assertSupplierTies();
    }

    public function testCancel_RefusesAnAdvanceThatWasCancelledAndOneThatIsNotThere(): void
    {
        $advanceId = $this->advances->pay($this->supplierId, '60.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->advances->cancel($advanceId, 'mistake', $this->clerk);

        $this->assertSame(AccountingError::AdvanceAlreadyCancelled, $this->refusal(fn () => $this->advances->cancel($advanceId, 'again', $this->clerk))->error);
        $this->assertSame(AccountingError::AdvanceNotFound, $this->refusal(fn () => $this->advances->cancel(999999, 'none', $this->clerk))->error);
        $this->assertSame(AccountingError::ReasonRequired, $this->refusal(fn () => $this->advances->cancel($advanceId, '  ', $this->clerk))->error);
    }

    public function testOpeningBalance_WeOweBooksAPurchaseWithoutLinesThatAdvancesPay(): void
    {
        $opening = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '1000.00', '2026-01-01', 'from the old books', $this->clerk);
        $this->receivingIds[] = $opening;

        $this->assertSame([$this->accountId('opening_balance') . '=1000.00/0.00', $this->supplierAccount() . '=0.00/1000.00'], $this->linesOf('purchase', $opening));
        $this->assertSame('1000.00', $this->owed());

        $figures = $this->supplierPosition()->purchase($opening);

        $this->assertTrue($figures->opening);
        $this->assertSame('1000.00', bcadd($figures->open(), '0', 2));

        $advanceId = $this->advances->pay($this->supplierId, '400.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->assertSame([CreditSource::ADVANCE . ':' . $advanceId . '->' . $opening . ':400.00'], $this->supplierApplications());
        $this->assertSame('600.00', bcadd($this->supplierPosition()->purchase($opening)->open(), '0', 2));
        $this->assertSame('600.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testOpeningBalance_TheyOweBooksACreditThatIsSpentOnTheNextPurchase(): void
    {
        $advanceId = $this->advances->openingBalance($this->supplierId, SupplierAdvances::THEY_OWE, '250.00', '2026-01-01', '', $this->clerk);

        $this->assertSame([$this->supplierAccount() . '=250.00/0.00', $this->accountId('opening_balance') . '=0.00/250.00'], $this->linesOf(SupplierAdvances::SOURCE, $advanceId));
        $this->assertSame('-250.00', $this->owed());
        $this->assertSame('250.0000', $this->supplierPosition()->credit());

        $purchase = $this->purchaseOf('400.00');

        $this->assertSame([CreditSource::ADVANCE . ':' . $advanceId . '->' . $purchase . ':250.00'], $this->supplierApplications());
        $this->assertSame('150.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testCancelOpening_ReversesItsJournalAndGivesBackTheCreditSpentOnIt(): void
    {
        $opening = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '1000.00', '2026-01-01', '', $this->clerk);
        $this->receivingIds[] = $opening;
        $this->advances->pay($this->supplierId, '400.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->advances->cancelOpening($opening, 'entered twice', $this->clerk);

        $this->assertSame('-400.00', $this->owed());
        $this->assertSame('400.0000', $this->supplierPosition()->credit());
        $this->assertNull($this->supplierPosition()->purchase($opening));
        $this->assertSupplierTies();
    }

    public function testCancelOpening_RefusesWhatIsNotAnOpeningBalance(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->assertSame(AccountingError::OpeningNotFound, $this->refusal(fn () => $this->advances->cancelOpening($purchase, 'no', $this->clerk))->error);
    }

    public function testPay_RefusesWhatCannotBePaid(): void
    {
        $cases = [
            [AccountingError::AdvanceAmountInvalid, fn () => $this->advances->pay($this->supplierId, '0', '2026-10-20', 'Cash', null, '', '', $this->clerk)],
            [AccountingError::AdvanceAmountInvalid, fn () => $this->advances->pay($this->supplierId, '-5', '2026-10-20', 'Cash', null, '', '', $this->clerk)],
            [AccountingError::AdvanceAmountInvalid, fn () => $this->advances->pay($this->supplierId, 'ten', '2026-10-20', 'Cash', null, '', '', $this->clerk)],
            [AccountingError::InvalidDate, fn () => $this->advances->pay($this->supplierId, '10', '31/12/2026', 'Cash', null, '', '', $this->clerk)],
            [AccountingError::AdvancePaymentUnknown, fn () => $this->advances->pay($this->supplierId, '10', '2026-10-20', 'Due', null, '', '', $this->clerk)],
            [AccountingError::AdvancePaymentUnknown, fn () => $this->advances->pay($this->supplierId, '10', '2026-10-20', 'Gift Card', null, '', '', $this->clerk)],
            [AccountingError::AdvanceSupplierMissing, fn () => $this->advances->pay(999999, '10', '2026-10-20', 'Cash', null, '', '', $this->clerk)],
            [AccountingError::TreasuryNotFound, fn () => $this->advances->pay($this->supplierId, '10', '2026-10-20', 'Cash', 999999, '', '', $this->clerk)],
        ];

        foreach ($cases as [$error, $action]) {
            $this->assertSame($error, $this->refusal($action)->error, $error->value);
        }

        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testPay_RefusesADeactivatedTreasury(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Old drawer', 'kind' => 'cash'], $this->clerk);

        $treasuries->deactivate($drawer, $this->clerk);

        $this->assertSame(AccountingError::TreasuryInactive, $this->refusal(fn () => $this->advances->pay($this->supplierId, '10', '2026-10-20', 'Cash', $drawer, '', '', $this->clerk))->error);
    }

    public function testPay_RefusesAnEmployeeWithoutThePermissionAndABranchTheyDoNotWorkIn(): void
    {
        $outsider = $this->employeeWithGrants([]);

        $this->assertSame(AccountingError::PermissionDenied, $this->refusal(fn () => $this->advances->pay($this->supplierId, '10', '2026-10-20', 'Cash', null, '', '', $outsider))->error);

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE], [$other]);
        $drawer  = (new TreasuryService())->create(['name' => 'Branch one drawer', 'kind' => 'cash', 'branch_id' => 1], $this->clerk);

        $this->refusal(fn () => $this->advances->pay($this->supplierId, '10', '2026-10-20', 'Cash', $drawer, '', '', $limited));

        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testPay_IsRefusedInAClosedPeriodAndSavesNothing(): void
    {
        $this->closeThrough('2026-10-31');

        $exception = $this->refusal(fn () => $this->advances->pay($this->supplierId, '10', '2026-10-20', 'Cash', null, '', '', $this->clerk));

        $this->assertStringContainsString('2026-10-31', $exception->getMessage());
        $this->assertSame(0, $this->countRows('supplier_advances'));
        $this->assertSame('0.00', $this->owed());
    }

    public function testOpeningBalance_RefusesASideThatIsNeitherAndAnAmountOfNothing(): void
    {
        $this->assertSame(AccountingError::AdvanceKindInvalid, $this->refusal(fn () => $this->advances->openingBalance($this->supplierId, 'both', '10', '2026-01-01', '', $this->clerk))->error);
        $this->assertSame(AccountingError::AdvanceAmountInvalid, $this->refusal(fn () => $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '0', '2026-01-01', '', $this->clerk))->error);
    }

    public function testForSupplier_ListsTheAdvancesNewestFirstWithTheirTreasuries(): void
    {
        $drawer = (new TreasuryService())->create(['name' => 'Listed drawer', 'kind' => 'cash'], $this->clerk);
        $first  = $this->advances->pay($this->supplierId, '10.00', '2026-10-20', 'Cash', null, '', '', $this->clerk);
        $second = $this->advances->pay($this->supplierId, '20.00', '2026-10-21', 'Cash', $drawer, '', '', $this->clerk);

        $rows = $this->advances->forSupplier($this->supplierId);

        $this->assertSame([$second, $first], array_map(static fn (object $row): int => (int) $row->advance_id, $rows));
        $this->assertSame('Listed drawer', $rows[0]->treasury_name);
        $this->assertNull($rows[1]->treasury_name);
    }

    public function testBranchOf_TellsWhereAnAdvanceAndAnOpeningBalanceBelong(): void
    {
        $advanceId = $this->advances->pay($this->supplierId, '10.00', '2026-10-20', 'Cash', null, '', '', $this->clerk);
        $opening   = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '10.00', '2026-01-01', '', $this->clerk);
        $this->receivingIds[] = $opening;

        $this->assertSame(1, $this->advances->branchOf(SupplierAdvances::SOURCE, $advanceId));
        $this->assertSame(1, $this->advances->branchOf(SupplierAdvances::OPENING_TYPE, $opening));
        $this->assertNull($this->advances->branchOf(SupplierAdvances::SOURCE, 999999));
    }
}
