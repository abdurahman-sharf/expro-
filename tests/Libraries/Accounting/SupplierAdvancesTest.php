<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
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
use App\Libraries\Roles\RoleService;

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
    use ParityCaseTrait;
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

        $db = $this->accountingDb();
        $db->table('document_numbers')->truncate();
        $db->table('document_sequences')->truncate();
        $db->table('accounting_audit_log')->where('entity_type', 'document_numbering')->delete();

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

    public function testPay_AppliesTheScopeOfTheTreasuryTheWayOfPaymentIsRoutedToWhenNoTreasuryIsNamed(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $this->clerk);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $refusal = $this->refusal(fn () => $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', null, '', '', $this->clerk));

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $refusal->error);
        $this->assertSame('Routed bank', $refusal->context['name']);
        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testCancel_RefusesAnEmployeeWhoMayNotDepositIntoTheTreasuryTheAdvanceWasPaidFrom(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Advance drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);
        $advanceId  = $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', $drawer, '', '', $this->clerk);

        $refusal = $this->refusal(fn () => $this->advances->cancel($advanceId, 'paid twice', $this->clerk));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame('-120.0000', $treasuries->position($drawer)->balance);
        $this->assertSame('completed', $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->get()->getRow()->status);
    }

    public function testCancel_AcceptsAnEmployeeWhoMayDepositIntoTheTreasuryAndTheBooksAlways(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Advance drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$this->clerk]], $this->clerk);
        $first      = $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', $drawer, '', '', $this->clerk);
        $second     = $this->advances->pay($this->supplierId, '80.00', '2026-10-20', 'Cash', $drawer, '', '', $this->clerk);

        $this->advances->cancel($first, 'paid twice', $this->clerk);

        $treasuries->update($drawer, ['name' => 'Advance drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);
        $this->advances->cancel($second, 'paid twice');

        $this->assertSame('0.0000', $treasuries->position($drawer)->balance);
    }

    public function testCancel_RefusesAnAdvancePaidWithoutATreasuryWhenItsTypeIsRoutedToATreasuryClosedToTheEmployee(): void
    {
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $bank       = $treasuries->create(['name' => 'Routed bank', 'kind' => 'bank', 'bank_name' => 'Gulf Bank', 'account_number' => '1234567890', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $this->clerk);

        $this->accountingDb()->table('accounting_routing')->where('routing_key', 'payment.cash')->update(['account_id' => $treasuries->accountOf($bank)]);

        $advanceId = $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', null, '', '');

        $this->assertSame(AccountingError::TreasuryDepositDenied, $this->refusal(fn () => $this->advances->cancel($advanceId, 'paid twice', $this->clerk))->error);
        $this->assertSame('-120.0000', $treasuries->position($bank)->balance);
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

    private function daysAgo(int $days): string
    {
        return date('Y-m-d', strtotime("-{$days} days"));
    }

    public function testPay_PostsOnTheDateEnteredAndKeepsTheTimeItWasSavedSeparately(): void
    {
        $date      = $this->daysAgo(5);
        $advanceId = $this->advances->pay($this->supplierId, '75.00', $date, 'Cash', null, '', '', $this->clerk);

        $row     = $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->get()->getRow();
        $journal = $this->accountingDb()->table('accounting_journals')->where(['source_type' => SupplierAdvances::SOURCE, 'source_id' => $advanceId])->get()->getRow();

        $this->assertSame($date, $row->advance_date);
        $this->assertSame($date, $journal->journal_date);
        $this->assertSame(date('Y-m-d'), substr((string) $row->created_at, 0, 10));
        $this->assertSame('-75.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testPay_AcceptsADateOfToday(): void
    {
        $advanceId = $this->advances->pay($this->supplierId, '75.00', date('Y-m-d'), 'Cash', null, '', '', $this->clerk);

        $this->assertSame(date('Y-m-d'), $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->get()->getRow()->advance_date);
    }

    public function testPay_RefusesADateAfterTodayAndSavesNothing(): void
    {
        $tomorrow  = date('Y-m-d', strtotime('+1 day'));
        $exception = $this->refusal(fn () => $this->advances->pay($this->supplierId, '75.00', $tomorrow, 'Cash', null, '', '', $this->clerk));

        $this->assertSame(AccountingError::PaymentDateInFuture, $exception->error);
        $this->assertSame(0, $this->countRows('supplier_advances'));
        $this->assertSame(0, $this->countRows('accounting_journals'));
        $this->assertSame('0.00', $this->owed());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function notADateProvider(): array
    {
        return ['a day that does not exist' => ['2026-02-30'], 'words' => ['yesterday'], 'another order' => ['20/10/2026'], 'nothing' => ['']];
    }

    #[DataProvider('notADateProvider')]
    public function testPay_RefusesADateThatIsNotADay(string $date): void
    {
        $this->assertSame(AccountingError::InvalidDate, $this->refusal(fn () => $this->advances->pay($this->supplierId, '75.00', $date, 'Cash', null, '', '', $this->clerk))->error);
        $this->assertSame(0, $this->countRows('supplier_advances'));
    }

    public function testPay_RefusesADateBeforeTheDayTheBooksStartAndAcceptsThatDay(): void
    {
        $start = $this->daysAgo(30);

        $this->setConfigValue('accounting_start_date', $start);

        $exception = $this->refusal(fn () => $this->advances->pay($this->supplierId, '75.00', $this->daysAgo(31), 'Cash', null, '', '', $this->clerk));

        $this->assertSame(AccountingError::PaymentDateBeforeBooks, $exception->error);
        $this->assertSame(0, $this->countRows('supplier_advances'));

        $this->advances->pay($this->supplierId, '75.00', $start, 'Cash', null, '', '', $this->clerk);

        $this->assertSame(1, $this->countRows('supplier_advances'));
    }

    public function testPay_AcceptsEveryDayUpToTodayWhileTheBooksStartInTheFuture(): void
    {
        $this->setConfigValue('accounting_start_date', date('Y-m-d', strtotime('+20 days')));

        $this->advances->pay($this->supplierId, '75.00', date('Y-m-d'), 'Cash', null, '', '', $this->clerk);
        $this->advances->pay($this->supplierId, '25.00', $this->daysAgo(5), 'Cash', null, '', '', $this->clerk);

        $this->assertSame(2, $this->countRows('supplier_advances'));
        $this->assertSame('-100.00', $this->owed());
    }

    public function testPay_RefusesTheLastDayOfAClosedPeriodAndAcceptsTheNextOne(): void
    {
        $this->closeThrough($this->daysAgo(10));

        $this->refusal(fn () => $this->advances->pay($this->supplierId, '75.00', $this->daysAgo(10), 'Cash', null, '', '', $this->clerk));

        $this->assertSame(0, $this->countRows('supplier_advances'));

        $advanceId = $this->advances->pay($this->supplierId, '75.00', $this->daysAgo(9), 'Cash', null, '', '', $this->clerk);

        $this->assertSame($this->daysAgo(9), $this->accountingDb()->table('accounting_journals')->where(['source_type' => SupplierAdvances::SOURCE, 'source_id' => $advanceId])->get()->getRow()->journal_date);
    }

    public function testOpeningBalance_RefusesASideThatIsNeitherAndAnAmountOfNothing(): void
    {
        $this->assertSame(AccountingError::AdvanceKindInvalid, $this->refusal(fn () => $this->advances->openingBalance($this->supplierId, 'both', '10', '2026-01-01', '', $this->clerk))->error);
        $this->assertSame(AccountingError::AdvanceAmountInvalid, $this->refusal(fn () => $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '0', '2026-01-01', '', $this->clerk))->error);
    }

    public function testOpeningBalance_RefusesADayAfterTodayAndAcceptsTodayAndLeavesNothingOfTheRefused(): void
    {
        $tomorrow = date('Y-m-d', strtotime('+1 day'));

        foreach ([SupplierAdvances::WE_OWE, SupplierAdvances::THEY_OWE] as $side) {
            $refusal = $this->refusal(fn () => $this->advances->openingBalance($this->supplierId, $side, '1000.00', $tomorrow, '', $this->clerk));

            $this->assertSame(AccountingError::InvalidDate, $refusal->error, $side);
            $this->assertStringContainsString($tomorrow, $refusal->getMessage());
        }

        $this->assertSame(0, $this->countRows('supplier_advances'));
        $this->assertSame(0, $this->countRows('receivings'));
        $this->assertSame(0, $this->countJournals());

        $today = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '1000.00', date('Y-m-d'), '', $this->clerk);

        $this->receivingIds[] = $today;

        $this->assertGreaterThan(0, $today);
        $this->assertSame('1000.00', $this->owed());
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

    private function numberOfAdvance(int $advanceId): ?string
    {
        return (new DocumentNumbering())->numbers(DocumentType::SupplierPayment, 'supplier_advances', [$advanceId])[$advanceId] ?? null;
    }

    public function testPay_GivesNoNumberWhileTheSequenceIsOff(): void
    {
        $advanceId = $this->advances->pay($this->supplierId, '50.00', '2026-10-20', 'Cash', null, '', '', $this->clerk);

        $this->assertNull($this->numberOfAdvance($advanceId));
        $this->assertSame(0, $this->countRows('document_numbers'));
    }

    public function testPay_NumbersThePaymentInTheSequenceOfSupplierPayments(): void
    {
        (new DocumentNumbering())->configure(DocumentType::SupplierPayment, ['enabled' => true, 'prefix' => 'SP-', 'padding' => 3]);

        $first  = $this->advances->pay($this->supplierId, '50.00', '2026-10-20', 'Cash', null, '', '', $this->clerk);
        $second = $this->advances->pay($this->supplierId, '60.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->assertSame('SP-001', $this->numberOfAdvance($first));
        $this->assertSame('SP-002', $this->numberOfAdvance($second));
    }

    public function testPay_SharesTheSequenceWithThePaymentsMadeAgainstAPurchase(): void
    {
        (new DocumentNumbering())->configure(DocumentType::SupplierPayment, ['enabled' => true, 'prefix' => 'SP-', 'padding' => 3]);

        $purchase  = $this->purchaseOf('100.00');
        $onInvoice = $this->payPurchase($purchase, '10.00');
        $advanceId = $this->advances->pay($this->supplierId, '60.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->assertSame('SP-001', (new DocumentNumbering())->numbers(DocumentType::SupplierPayment, 'receivings_payments', [$onInvoice])[$onInvoice]);
        $this->assertSame('SP-002', $this->numberOfAdvance($advanceId));
    }

    public function testOpeningBalance_IsNotAPaymentAndGetsNoNumber(): void
    {
        (new DocumentNumbering())->configure(DocumentType::SupplierPayment, ['enabled' => true, 'prefix' => 'SP-', 'padding' => 3]);

        $weOwe = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '10.00', '2026-01-01', '', $this->clerk);
        $this->receivingIds[] = $weOwe;
        $theyOwe = $this->advances->openingBalance($this->supplierId, SupplierAdvances::THEY_OWE, '20.00', '2026-01-01', '', $this->clerk);

        $this->assertNull($this->numberOfAdvance($theyOwe));
        $this->assertSame(0, $this->countRows('document_numbers'));
        $this->assertSame(1, (new DocumentNumbering())->sequence(DocumentType::SupplierPayment)->nextNumber);
    }

    public function testPay_LeavesNoNumberBehindWhenTheBooksRefuseAPaymentThatWasNumbered(): void
    {
        (new DocumentNumbering())->configure(DocumentType::SupplierPayment, ['enabled' => true, 'prefix' => 'SP-', 'padding' => 3]);
        $first = $this->advances->pay($this->supplierId, '50.00', '2026-10-20', 'Cash', null, '', '', $this->clerk);
        $this->closeThrough('2026-10-31');

        $refusal = $this->refusal(fn () => $this->advances->pay($this->supplierId, '60.00', '2026-10-21', 'Cash', null, '', '', $this->clerk));

        $this->assertStringContainsString('2026-10-31', $refusal->getMessage());
        $this->assertSame(1, $this->countRows('document_numbers'));
        $this->assertSame(2, (new DocumentNumbering())->sequence(DocumentType::SupplierPayment)->nextNumber);
        $this->assertSame('SP-001', $this->numberOfAdvance($first));

        $this->accountingDb()->table('accounting_closed_periods')->truncate();

        $second = $this->advances->pay($this->supplierId, '60.00', '2026-10-21', 'Cash', null, '', '', $this->clerk);

        $this->assertSame('SP-002', $this->numberOfAdvance($second));
    }

    public function testPay_RefusesThePaymentAndLeavesNothingWhenNoNumberCanBeGiven(): void
    {
        $db = $this->accountingDb();
        (new DocumentNumbering())->configure(DocumentType::SupplierPayment, ['enabled' => true, 'prefix' => 'SP-']);
        $db->table('document_sequences')->where('doc_type', 'supplier_payment')->update(['next_number' => 4294967294]);
        $db->table('document_numbers')->insert(['company_id' => 1, 'doc_type' => 'supplier_payment', 'source_table' => 'supplier_advances', 'source_id' => 999999999, 'sequence_number' => 4294967294, 'number' => 'SP-4294967294']);

        $before  = $this->countRows('supplier_advances');
        $refusal = $this->refusal(fn () => $this->advances->pay($this->supplierId, '50.00', '2026-10-20', 'Cash', null, '', '', $this->clerk));

        $this->assertInstanceOf(AccountingException::class, $refusal);
        $this->assertSame(AccountingError::NumberingExhausted, $refusal->error);
        $this->assertSame($before, $this->countRows('supplier_advances'));
        $this->assertSame(1, $this->countRows('document_numbers'));
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixtures T-45 and T-46
    // ---------------------------------------------------------------------------------------

    /**
     * @return array<string, int>
     */
    private function advanceRoles(): array
    {
        return [
            'supplier'        => $this->supplierAccount(),
            'opening_balance' => $this->accountId('opening_balance'),
            'inventory'       => $this->stockAccount(),
            'treasury'        => $this->accountId('cash'),
        ];
    }

    private function journalIdOf(string $source, int $sourceId): int
    {
        return (int) $this->accountingDb()->table('accounting_journals')->where(['source_type' => $source, 'source_id' => $sourceId, 'status' => 'posted'])->get()->getRow()->journal_id;
    }

    private function openingOwedToTheSupplier(string $amount, string $date): int
    {
        $opening = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, $amount, $date, '', $this->clerk);

        $this->receivingIds[] = $opening;

        return $opening;
    }

    public function testOpeningBalance_DebitsTheOpeningBalancesAndCreditsTheSupplierAsTheReferenceSystemDoes(): void
    {
        $case    = $this->referenceCase('T-45', 'supplier_opening_balance', 'opening-balance-owed-to-a-supplier-journal', 'exact');
        $opening = $this->openingOwedToTheSupplier($case['inputs']['amount'], '2026-10-27');

        $this->assertSameAsFixture($case['expected']['journals']['opening'], $this->roleLines($this->journalIdOf(ReceivingPostingBuilder::SOURCE_PURCHASE, $opening), $this->advanceRoles()), 'opening balance journal');
    }

    public function testOpeningBalance_AgesFromItsOwnDateAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-45', 'supplier_opening_balance', 'opening-balance-ages-from-its-own-date', 'exact');

        $this->openingOwedToTheSupplier($case['inputs']['amount'], date('Y-m-d', strtotime('-40 days')));

        $aging = $this->supplierPosition()->aging(date('Y-m-d'));

        $this->assertSameAsFixture($case['expected'], [
            'amount_in_the_aging' => bcadd(array_reduce($aging, static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0'), '0', 2),
            'ages_from'           => $aging['31-60'] === '650.0000' ? 'its own date' : 'something else',
        ], 'aging of the opening balance');
    }

    public function testCancelOpening_ReversesTheJournalAndHasNoEditWhereTheReferenceSystemEditsAndErases(): void
    {
        $case    = $this->referenceCase('T-45', 'supplier_opening_balance', 'opening-balance-edit-and-delete', 'deliberate_difference');
        $opening = $this->openingOwedToTheSupplier($case['inputs']['amount'], '2026-10-27');
        $edits   = array_filter(get_class_methods(SupplierAdvances::class), static fn (string $method): bool => preg_match('/edit|update|change/i', $method) === 1);

        $this->advances->cancelOpening($opening, 'entered again', $this->clerk);

        $this->assertSameAsFixture($case['expected'], [
            'edit_in_place'             => $edits !== [],
            'delete_erases_the_journal' => $this->accountingDb()->table('accounting_journals')->where(['source_type' => ReceivingPostingBuilder::SOURCE_PURCHASE, 'source_id' => $opening])->countAllResults() === 0,
        ], 'edit and delete of an opening balance');
        $this->assertSame(['reversed'], array_column($this->accountingDb()->table('accounting_journals')->where(['source_type' => ReceivingPostingBuilder::SOURCE_PURCHASE, 'source_id' => $opening])->get()->getResultArray(), 'status'));
    }

    /**
     * An opening balance, a payment above it and a purchase after the payment, as the case says.
     *
     * @param array<string, mixed> $inputs
     *
     * @return array{opening: int, advance: int, purchase: int}
     */
    private function paidOverAnOpeningBalance(array $inputs, bool $withThePurchase): array
    {
        $opening = $this->openingOwedToTheSupplier($inputs['opening_balance'], '2026-10-27');
        $advance = $this->advances->pay($this->supplierId, $inputs['payment'], '2026-10-27', 'Cash', null, '', '', $this->clerk);
        $buy     = 0;

        if ($withThePurchase) {
            $buy = $this->buy([$this->purchaseLine($this->item('0.00'), 0, $inputs['purchase']['quantity'] ?? '3', $inputs['purchase']['price'] ?? '100.00')]);

            $this->assertGreaterThan(0, $buy);
        }

        return ['opening' => $opening, 'advance' => $advance, 'purchase' => $buy];
    }

    public function testPay_DebitsTheSupplierAndLeavesCreditOverAnOpeningBalanceAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-46', 'supplier_payment', 'payment-over-an-opening-balance-leaves-credit', 'exact');
        $made = $this->paidOverAnOpeningBalance($case['inputs'], false);

        $this->assertSameAsFixture($case['expected'], [
            'journals'                           => ['payment' => $this->roleLines($this->journalIdOf(SupplierAdvances::SOURCE, $made['advance']), $this->advanceRoles())],
            'supplier_balance_after_the_payment' => $this->owed(),
        ], 'payment over an opening balance');
    }

    public function testPay_SettlesThePurchaseSavedAfterItFromTheCreditAsTheReferenceSystemDoes(): void
    {
        $case    = $this->referenceCase('T-46', 'supplier_payment', 'credit-settles-the-purchase-saved-after-it', 'exact');
        $inputs  = $case['inputs'];
        $opening = $this->openingOwedToTheSupplier($inputs['opening_balance'], '2026-10-27');

        $this->advances->pay($this->supplierId, $inputs['payment'], '2026-10-27', 'Cash', null, '', '', $this->clerk);

        $before   = $this->countRows('accounting_journals');
        $purchase = $this->buy([$this->purchaseLine($this->item('0.00'), 0, $inputs['purchase']['quantity'], $inputs['purchase']['price'])]);
        $figures  = $this->supplierPosition()->purchase($purchase);

        $this->assertGreaterThan(0, $opening);
        $this->assertSameAsFixture($case['expected'], [
            'journals'                  => ['purchase' => $this->roleLines((int) $this->standingPurchase($purchase)[0]['id'], $this->advanceRoles())],
            'settled_by_credit'         => bcadd($figures->applied, '0', 2),
            'open'                      => bcadd($figures->open(), '0', 2),
            'supplier_balance'          => $this->owed(),
            'journals_for_the_credit'   => (string) ($this->countRows('accounting_journals') - $before - 1),
        ], 'purchase settled from the credit');
    }

    public function testCancel_TakesBackTheCreditThePaymentGaveAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-46', 'supplier_payment', 'deleting-the-payment-takes-back-the-credit-it-gave', 'exact');
        $made = $this->paidOverAnOpeningBalance($case['inputs'] + ['purchase' => ['quantity' => '3', 'price' => '100.00']], true);

        $this->advances->cancel($made['advance'], 'wrong payment', $this->clerk);

        $this->assertSameAsFixture($case['expected'], [
            'purchase_open'            => bcadd($this->supplierPosition()->purchase($made['purchase'])->open(), '0', 2),
            'supplier_balance'         => $this->owed(),
            'credit_applications_left' => (string) count($this->supplierApplications()),
        ], 'purchase after the payment is cancelled');
    }

    public function testCancel_RefusesTheAdvanceWhoseCreditWasPaidBackUntilTheRefundIsCancelled(): void
    {
        $refunds  = new CreditRefunds(CreditRefunds::SUPPLIER);
        $refunder = $this->employeeWithGrants([AccountingGuard::PAYABLES_REFUND, AccountingGuard::PAYABLES_MANAGE]);
        $advanceId = $this->advances->pay($this->supplierId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $refunder);
        $refundId  = $refunds->refund($this->supplierId, '60.00', date('Y-m-d'), 'Cash', null, '', '', $refunder);

        $this->assertSame(AccountingError::CreditRefunded, $this->refusal(fn () => $this->advances->cancel($advanceId, 'taken by mistake', $refunder))->error);
        $this->assertSame('completed', $this->accountingDb()->table('supplier_advances')->where('advance_id', $advanceId)->get()->getRow()->status);

        $refunds->cancel($refundId, 'paid twice', $refunder);
        $this->advances->cancel($advanceId, 'taken by mistake', $refunder);

        $this->assertSame('0.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testCancel_RefusesAnotherEmployeesAdvanceAsOneThatDoesNotExistForAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $advance = $this->advances->pay($this->supplierId, '120.00', '2026-10-20', 'Cash', null, '', '', $this->clerk);
        $second  = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);

        (new RoleService())->setRecordScope($second, 'own', null);

        $this->assertSame(AccountingError::AdvanceNotFound, $this->refusal(fn () => $this->advances->cancel($advance, 'by a stranger', $second))->error);
        $this->assertNull($this->advances->branchOf(SupplierAdvances::SOURCE, $advance, $second));
        $this->assertNotNull($this->advances->branchOf(SupplierAdvances::SOURCE, $advance, $this->clerk));
        $this->assertNotSame('cancelled', (string) $this->accountingDb()->table('supplier_advances')->select('status')->where('advance_id', $advance)->get()->getRow()->status);
    }

    public function testCancelOpening_RefusesAnotherEmployeesOpeningBalanceAsOneThatDoesNotExistForAnEmployeeWhoSeesTheirOwnRecords(): void
    {
        $opening = $this->advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '200.00', '2026-01-01', 'before the books', $this->clerk);
        $second  = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]);

        (new RoleService())->setRecordScope($second, 'own', null);

        $this->assertSame(AccountingError::OpeningNotFound, $this->refusal(fn () => $this->advances->cancelOpening($opening, 'by a stranger', $second))->error);
        $this->assertNull($this->advances->branchOf(SupplierAdvances::OPENING_TYPE, $opening, $second));
    }
}
