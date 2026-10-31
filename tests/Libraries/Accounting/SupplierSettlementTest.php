<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\PostingException;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\TreasuryService;
use App\Libraries\Organization\BranchException;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\ParityFixture;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What happens to the credit held with a supplier, driven through the real purchase model: a
 * debit note is applied to its purchase, credit beyond it waits, a new purchase is settled from
 * credit at once, and credit that no longer holds is taken back, so that what is owed on the open
 * purchases less the credit always adds up to the account of the supplier.
 */
class SupplierSettlementTest extends CIUnitTestCase
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

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startBuying();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded: fixture T-13
    // ---------------------------------------------------------------------------------------

    public function testSettle_SettlesANewPurchaseFromTheCreditOfADebitNoteAtOnceWithoutAJournalAsTheReferenceSystemDoes(): void
    {
        $case  = $this->payable('debit-note-credit-settles-a-new-purchase-at-once', 'exact');
        $first = $this->purchaseOf('115.00', null, lang('Sales.cash'));
        $note  = $this->debitNoteOf($first, $case['inputs']['debit_note'] === '115.00 from it' ? '115.00' : '0');

        $this->assertSame($case['expected']['credit_after_the_note'], bcadd($this->supplierPosition()->credit(), '0', 2));

        $before = $this->countRows('accounting_journals');
        $second = $this->purchaseOf('115.00');
        $made   = $this->countRows('accounting_journals') - $before;

        $figures = $this->supplierPosition()->purchase($second);

        $this->assertSame($case['expected']['applied_to_the_new_purchase'], bcadd($figures->applied, '0', 2));
        $this->assertSame($case['expected']['open'], bcadd($figures->open(), '0', 2));
        $this->assertSame($case['expected']['payment_state'], $figures->paymentState()->value);
        $this->assertSame(1, $made - $case['expected']['journals_for_the_application'], 'The new purchase writes its own journal and the application writes none.');
        $this->assertContains('debit_note:' . $note . '->' . $second . ':115.00', $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testSettle_TakesBackTheCreditAPaymentMakesUnneededWhereTheReferenceSystemShowsThePurchaseInExcess(): void
    {
        $case  = $this->payable('payment-beyond-a-purchase-the-credit-settled', 'differs');
        $first = $this->purchaseOf('115.00', null, lang('Sales.cash'));

        $this->debitNoteOf($first, '115.00');

        $second  = $this->purchaseOf('115.00');
        $payment = $this->payPurchase($second, '50.00');

        $figures  = $this->supplierPosition()->purchase($second);
        $journals = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'supplier_payment', 'source_id' => $payment])->get()->getResult();

        $this->assertSame($case['expected']['settled'], bcadd($figures->settled(), '0', 2));
        $this->assertSame($case['expected']['payment_state'], $figures->paymentState()->value);
        $this->assertSame('50.00', bcadd($figures->paid, '0', 2));
        $this->assertSame('65.00', bcadd($figures->applied, '0', 2), 'The credit that the money made unneeded went back to the supplier');
        $this->assertCount(1, $journals);
        $this->assertSame($case['expected']['payment_journal'], bcadd($journals[0]->total_debit, '0', 2));
        $this->assertSame($case['expected']['credit_held'], bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame('-50.00', $this->owed());
        $this->assertSupplierTies();
    }

    // ---------------------------------------------------------------------------------------
    // The rules of settling
    // ---------------------------------------------------------------------------------------

    public function testSettle_LeavesWhatADebitNoteHasBeyondItsPurchaseAsCredit(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '100.00');

        $position = $this->supplierPosition();

        $this->assertSame('0.00', bcadd($position->open(), '0', 2));
        $this->assertSame(['debit_note:' . $note . '->' . $purchase . ':100.00'], $this->supplierApplications());
        $this->assertSupplierTies();

        $paid = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $this->debitNoteOf($paid, '40.00');

        $this->assertSame('40.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame('-40.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testSettle_SpendsAPaymentBeyondAPurchaseOnTheOldestOpenPurchasesFirst(): void
    {
        $oldest = $this->purchaseOf('100.00');
        $newer  = $this->purchaseOf('100.00');
        $small  = $this->purchaseOf('50.00');

        $this->payPurchase($small, '170.00');

        $this->assertSame(['invoice_excess:' . $small . '->' . $oldest . ':100.00', 'invoice_excess:' . $small . '->' . $newer . ':20.00'], $this->supplierApplications());
        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($oldest)->open(), '0', 2));
        $this->assertSame('80.00', bcadd($this->supplierPosition()->purchase($newer)->open(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testSettle_AppliesADebitNoteToItsOwnPurchaseBeforeAnyOther(): void
    {
        $other = $this->purchaseOf('100.00');
        $own   = $this->purchaseOf('300.00');
        $note  = $this->debitNoteOf($own, '150.00');

        $this->assertSame(['debit_note:' . $note . '->' . $own . ':150.00'], $this->supplierApplications());
        $this->assertSame('100.00', bcadd($this->supplierPosition()->purchase($other)->open(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testSettle_SendsTheRestOfADebitNoteToTheOldestOpenPurchase(): void
    {
        $oldest = $this->purchaseOf('200.00');
        $own    = $this->purchaseOf('100.00');
        $note   = $this->debitNoteOf($own, '100.00');

        $this->assertSame(['debit_note:' . $note . '->' . $own . ':100.00'], $this->supplierApplications());

        $paid = $this->purchaseOf('300.00', null, lang('Sales.cash'));
        $big  = $this->debitNoteOf($paid, '150.00');

        $this->assertContains('debit_note:' . $big . '->' . $oldest . ':150.00', $this->supplierApplications());
        $this->assertSame('50.00', bcadd($this->supplierPosition()->purchase($oldest)->open(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testSettle_GivesBackTheCreditAnotherPurchaseSpentWhenThePaymentThatGaveItIsRemoved(): void
    {
        $first   = $this->purchaseOf('70.00');
        $second  = $this->purchaseOf('230.00');
        $payment = $this->payPurchase($second, '300.00');

        $this->assertSame('0.00', bcadd($this->supplierPosition()->open(), '0', 2));
        $this->assertSame(['invoice_excess:' . $second . '->' . $first . ':70.00'], $this->supplierApplications());

        $this->assertTrue($this->changePayment($second, $payment, ['payment_amount' => '0']));

        $position = $this->supplierPosition();

        $this->assertSame('70.00', bcadd($position->purchase($first)->open(), '0', 2));
        $this->assertSame('230.00', bcadd($position->purchase($second)->open(), '0', 2));
        $this->assertSame('300.00', $this->owed());
        $this->assertSame([], $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testSettle_TakesBackWhatWasAppliedToAPurchaseThatIsDeleted(): void
    {
        $first = $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->debitNoteOf($first, '100.00');

        $second = $this->purchaseOf('172.50');

        $this->assertSame('72.50', bcadd($this->supplierPosition()->purchase($second)->open(), '0', 2));

        $this->assertTrue($this->purchases->delete_value($second, $this->employeeId));

        $this->assertSame('100.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame([], array_filter($this->supplierApplications(), static fn (string $row): bool => str_contains($row, '->' . $second . ':')));
        $this->assertSame('-100.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testSettle_CutsTheNewestCreditAppliedToAPurchaseWhenMoneyIsPaidIntoItAfterwards(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $note     = $this->debitNoteOf($purchase, '100.00');

        $this->assertSame('0.00', bcadd($this->supplierPosition()->open(), '0', 2));

        $this->payPurchase($purchase, '30.00');

        $figures = $this->supplierPosition()->purchase($purchase);

        $this->assertSame('70.00', bcadd($figures->applied, '0', 2));
        $this->assertSame('30.00', bcadd($figures->paid, '0', 2));
        $this->assertSame('0.00', bcadd($figures->excess(), '0', 2));
        $this->assertSame('30.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertContains('debit_note:' . $note . '->' . $purchase . ':70.00', $this->supplierApplications());
        $this->assertSame('-30.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testSettle_KeepsTheHistoryOfWhatItReleasedInsteadOfDeletingIt(): void
    {
        $first   = $this->purchaseOf('70.00');
        $second  = $this->purchaseOf('230.00');
        $payment = $this->payPurchase($second, '300.00');

        $this->assertTrue($this->changePayment($second, $payment, ['payment_amount' => '0']));

        $rows = $this->accountingDb()->table('supplier_credit_applications')->where('invoice_receiving_id', $first)->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->released_at);
        $this->assertSame((string) $this->employeeId, (string) $rows[0]->released_by);
    }

    public function testSettle_RunAgainChangesNothing(): void
    {
        $first  = $this->purchaseOf('70.00');
        $second = $this->purchaseOf('230.00');

        $this->payPurchase($second, '300.00');

        $rows = $this->accountingDb()->table('supplier_credit_applications')->get()->getResultArray();

        $this->settleSupplier();
        $this->settleSupplier();

        $this->assertSame($rows, $this->accountingDb()->table('supplier_credit_applications')->get()->getResultArray());
        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($first)->open(), '0', 2));
    }

    public function testSettle_SpendsCreditOnAPurchaseOfAnotherBranchBecauseTheCreditBelongsToTheSupplier(): void
    {
        $first  = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $second = $this->purchaseOf('60.00');
        $branch = $this->createBranch();

        $this->accountingDb()->table('receivings')->where('receiving_id', $second)->update(['branch_id' => $branch]);
        $this->debitNoteOf($first, '100.00');

        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($second)->open(), '0', 2));
        $this->assertSame('40.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testSettle_NeverMovesTheCreditOfOneSupplierToAnother(): void
    {
        $other    = $this->anotherSupplier();
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $this->debitNoteOf($purchase, '100.00');
        $this->purchaseOf('80.00', $other);

        $this->assertSame('100.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame('80.00', bcadd($this->supplierPosition($other)->open(), '0', 2));
        $this->assertSame([], $this->supplierApplications($other));
        $this->assertSupplierTies();
        $this->assertSupplierTies($other);
    }

    public function testSettle_CountsAReturnPaidBackInCashAgainstTheCreditItGave(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '5', '100.00')], -1, lang('Sales.cash'));
        $first    = $this->buy([$this->purchaseLine($item, 0, '-2', '100.00')], -1, null, $purchase);
        $refunded = $this->buy([$this->purchaseLine($item, 0, '-1', '100.00')], -1, lang('Sales.cash'), $purchase);

        $sources = [];

        foreach ($this->supplierPosition()->credits as $source) {
            $sources[$source->sourceId] = bcadd($source->available(), '0', 2);
        }

        $this->assertSame([$first => '200.00', $refunded => '0.00'], $sources);
        $this->assertSame('-200.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testSettle_LeavesAPurchaseWithoutASupplierOutOfTheLedger(): void
    {
        $item     = $this->item('0.00');
        $purchase = $this->buy([$this->purchaseLine($item, 0, '1', '40.00')], null, lang('Sales.cash'));

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame([], $this->supplierApplications());
        $this->assertSame([], $this->supplierPosition()->purchases);
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in observation package F: fixtures T-17 and T-19
    // ---------------------------------------------------------------------------------------

    /**
     * @param array<string, int> $purchases
     *
     * @return array<string, string>
     */
    private function figuresOf(array $purchases): array
    {
        $figures = [];

        foreach ($purchases as $name => $id) {
            $figures[$name . '_applied'] = bcadd($this->supplierPosition()->purchase($id)->applied, '0', 2);
            $figures[$name . '_open']    = bcadd($this->supplierPosition()->purchase($id)->open(), '0', 2);
        }

        $figures['owed'] = $this->owed();

        return $figures;
    }

    /**
     * Two unpaid purchases of 69, a paid purchase of 345 and a debit note of 230 from it: 3 units
     * at 115 and a return of 2.
     *
     * @return array{older: int, newer: int, note: int}
     */
    private function twoOpenPurchasesAndADebitNote(): array
    {
        $item  = $this->item('0.00');
        $paid  = $this->buy([$this->purchaseLine($item, 0, '3', '115')], -1, lang('Sales.cash'));
        $older = $this->purchaseOf('69.00');
        $newer = $this->purchaseOf('69.00');
        $note  = $this->buy([$this->purchaseLine($item, 0, '-2', '115')], -1, null, $paid);

        $this->assertGreaterThan(0, $paid);
        $this->assertGreaterThan(0, $note);

        return ['older' => $older, 'newer' => $newer, 'note' => $note];
    }

    public function testSettle_SpendsTheCreditOldestFirstWhenANewPurchaseIsSavedAsTheReferenceSystemDoes(): void
    {
        $case = $this->payable('credit-is-spent-oldest-first-when-a-new-purchase-is-saved', 'exact', 'T-17');
        $made = $this->twoOpenPurchasesAndADebitNote();
        $new  = $this->purchaseOf('115.00');

        $figures = $this->figuresOf(['older' => $made['older'], 'newer' => $made['newer'], 'new' => $new]);

        $this->assertSame($case['expected']['older_applied'], $figures['older_applied']);
        $this->assertSame($case['expected']['newer_applied'], $figures['newer_applied']);
        $this->assertSame($case['expected']['new_applied'], $figures['new_applied']);
        $this->assertSame($case['expected']['new_open'], $figures['new_open']);
        $this->assertSame($case['expected']['owed'], $figures['owed']);
        $this->assertSupplierTies();
    }

    public function testSettle_SpendsTheCreditOfADebitNoteAtOnceWhereTheReferenceSystemWaitsForTheNextPurchase(): void
    {
        $case = $this->payable('credit-of-a-debit-note-waits-for-the-next-purchase', 'differs', 'T-17');
        $made = $this->twoOpenPurchasesAndADebitNote();

        $figures = $this->figuresOf(['older' => $made['older'], 'newer' => $made['newer']]);

        $this->assertSame($case['expected']['older_open'], $figures['older_open']);
        $this->assertSame($case['expected']['newer_open'], $figures['newer_open']);
        $this->assertSame($case['expected']['owed'], $figures['owed'], 'What the supplier is owed is the same either way');
        $this->assertSupplierTies();
    }

    public function testSettle_SpendsTheExcessOfAPaymentOnALaterPurchaseAsTheReferenceSystemDoes(): void
    {
        $case  = $this->payable('excess-of-a-payment-settles-a-later-purchase', 'exact', 'T-19');
        $first = $this->purchaseOf('115.00');

        $this->payPurchase($first, '165.00');

        $this->assertSame($case['expected']['first_state'], $this->supplierPosition()->purchase($first)->paymentState()->value);
        $this->assertSame($case['expected']['owed_after_the_payment'], $this->owed());

        $second = $this->purchaseOf('57.50');
        $figures = $this->figuresOf(['second' => $second]);

        $this->assertSame($case['expected']['second_applied'], $figures['second_applied']);
        $this->assertSame($case['expected']['second_open'], $figures['second_open']);
        $this->assertSame($case['expected']['owed_after_the_second'], $figures['owed']);
        $this->assertSupplierTies();
    }

    public function testSettle_TakesBackWhatTheDeletedPaymentSettledOnALaterPurchaseAsTheReferenceSystemDoes(): void
    {
        $case    = $this->payable('deleting-the-payment-takes-back-what-it-settled', 'exact', 'T-19');
        $first   = $this->purchaseOf('115.00');
        $payment = $this->payPurchase($first, '165.00');
        $second  = $this->purchaseOf('57.50');

        $this->assertTrue($this->changePayment($first, $payment, ['payment_amount' => '0']));

        $figures = $this->figuresOf(['first' => $first, 'second' => $second]);

        $this->assertSame($case['expected']['first_open'], $figures['first_open']);
        $this->assertSame($case['expected']['second_open'], $figures['second_open']);
        $this->assertSame($case['expected']['owed'], $figures['owed']);
        $this->assertSupplierTies();
    }

    /**
     * @return array<string, mixed>
     */
    private function payable(string $id, string $classification, string $fixture = 'T-13'): array
    {
        $case = ParityFixture::cases($fixture, 'payable')[$id][0];

        $this->assertSame($classification, $case['classification'], $id);

        if ($classification === 'exact') {
            $this->assertSame($case['reference'], $case['expected'], "{$id} is recorded as exact and must expect what the reference system recorded");
        } else {
            $this->assertNotSame($case['reference'], $case['expected'], "{$id} is recorded as a difference and cannot expect what the reference system does");
        }

        return $case;
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixture T-51
    // ---------------------------------------------------------------------------------------

    public function testSettle_KeepsTheExcessOfAPaymentAsCreditHeldWithTheSupplierAsTheReferenceSystemDoes(): void
    {
        $case     = $this->referenceCase('T-51', 'purchase_overpayment', 'the-excess-of-a-payment-is-credit-held-with-the-supplier', 'exact');
        $purchase = $this->purchaseOf($case['inputs']['purchase']);

        $this->payPurchase($purchase, $case['inputs']['payment']);

        $this->assertSameAsFixture($case['expected'], [
            'supplier_balance' => $this->owed(),
            'treasury_balance' => $this->balance($this->accountId('cash')),
            'credit_held'      => bcadd($this->supplierPosition()->credit(), '0', 2),
        ], 'excess of a payment');
        $this->assertSupplierTies();
    }

    public function testSettle_ReturnsTheExcessToTheTreasuryWhenThePaymentIsCutAsTheReferenceSystemDoesWithItsFirstOption(): void
    {
        $case     = $this->referenceCase('T-51', 'purchase_overpayment', 'removing-the-excess-returns-it-to-the-treasury', 'exact');
        $purchase = $this->purchaseOf($case['inputs']['purchase']);
        $payment  = $this->payPurchase($purchase, $case['inputs']['payment']);

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_amount' => '100.00']));
        $this->assertSameAsFixture($case['expected'], [
            'supplier_balance' => $this->owed(),
            'treasury_balance' => $this->balance($this->accountId('cash')),
            'purchase_state'   => $this->supplierPosition()->purchase($purchase)->paymentState()->value,
        ], 'payment cut to the purchase');
        $this->assertSupplierTies();
    }

    public function testSettle_HasNoButtonThatSettlesAnOverpaidPurchaseWhereTheReferenceSystemHasOne(): void
    {
        $case    = $this->referenceCase('T-51', 'purchase_overpayment', 'a-button-that-settles-an-overpaid-purchase', 'deliberate_difference');
        $entries = [...ParityFixture::routesMatching('overpaid'), ...ParityFixture::routesMatching('overpay'), ...ParityFixture::routesMatching('excess')];

        $this->assertSameAsFixture($case['expected'], ['settle_button' => $entries !== [], 'options' => $entries], 'settle button');
    }

    // ---------------------------------------------------------------------------------------
    // Being paid back the credit held with a supplier (WP23)
    // ---------------------------------------------------------------------------------------

    private function refundsFromSuppliers(): CreditRefunds
    {
        return new CreditRefunds(CreditRefunds::SUPPLIER);
    }

    private function treasurer(): int
    {
        return $this->employeeWithGrants([AccountingGuard::PAYABLES_REFUND, AccountingGuard::PAYABLES_MANAGE, AccountingGuard::TREASURIES_MANAGE]);
    }

    private function supplierRefund(string $amount, ?int $employeeId = null, ?int $treasuryId = null, ?string $date = null, ?string $key = null): int
    {
        return $this->refundsFromSuppliers()->refund($this->supplierId, $amount, $date ?? date('Y-m-d'), 'Cash', $treasuryId, '', '', $employeeId ?? $this->treasurer(), $key);
    }

    private function supplierRefusal(callable $action): AccountingException|PostingException|BranchException
    {
        try {
            $action();
        } catch (AccountingException|PostingException|BranchException $exception) {
            return $exception;
        }

        $this->fail('The change was not refused.');
    }

    private function advanceToSupplier(string $amount, ?int $employeeId = null, ?int $treasuryId = null): int
    {
        return (new SupplierAdvances())->pay($this->supplierId, $amount, date('Y-m-d'), 'Cash', $treasuryId, '', '', $employeeId ?? $this->treasurer());
    }

    public function testRefund_DebitsTheTreasuryAndCreditsTheSupplierWhenTheyPayTheCreditBack(): void
    {
        $this->advanceToSupplier('300.00');

        $refundId = $this->supplierRefund('100.00');
        $journal  = $this->accountingDb()->table('accounting_journals')->where(['source_type' => CreditRefunds::SUPPLIER_SOURCE, 'source_id' => $refundId, 'status' => 'posted'])->get()->getRow();
        $lines    = array_map(
            static fn (object $line): string => $line->account_id . '=' . bcadd($line->debit, '0', 2) . '/' . bcadd($line->credit, '0', 2),
            $this->accountingDb()->table('accounting_journal_lines')->where('journal_id', $journal->journal_id)->orderBy('line_no')->get()->getResult(),
        );

        $this->assertSame([$this->accountId('cash') . '=100.00/0.00', $this->supplierAccount() . '=0.00/100.00'], $lines);
        $this->assertSame('200.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame('-200.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testRefund_TiesTheTreasuryItCameThroughToItsPosition(): void
    {
        $treasuries = new TreasuryService();
        $drawer     = $treasuries->create(['name' => 'Supplier receipts', 'kind' => 'cash'], $this->treasurer());

        $this->advanceToSupplier('300.00', null, $drawer);
        $this->supplierRefund('120.00', null, $drawer);

        $this->assertSame('-180.0000', $treasuries->position($drawer)->balance);
        $this->assertSame($this->balance($treasuries->accountOf($drawer)), bcadd($treasuries->position($drawer)->balance, '0', 2));
        $this->assertSupplierTies();
    }

    public function testRefund_RequiresThePayablesRefundPermissionAndNotTheOneOfCustomers(): void
    {
        $this->advanceToSupplier('300.00');

        $customerSide = $this->employeeWithGrants([AccountingGuard::RECEIVABLES_REFUND, AccountingGuard::PAYABLES_MANAGE]);

        $this->assertSame(AccountingError::PermissionDenied, $this->supplierRefusal(fn () => $this->supplierRefund('10.00', $customerSide))->error);
        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));
        $this->assertSame('300.00', bcadd($this->supplierPosition()->credit(), '0', 2));
    }

    public function testRefund_RefusesATreasuryTheEmployeeMayNotDepositIntoAndAcceptsOneTheyMay(): void
    {
        $treasurer  = $this->treasurer();
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $locked     = $treasuries->create(['name' => 'Locked drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$other]], $treasurer);
        $own        = $treasuries->create(['name' => 'Own drawer', 'kind' => 'cash', 'deposit_scope' => 'staff', 'deposit_members' => [$treasurer]], $treasurer);

        $this->advanceToSupplier('300.00');

        $refusal = $this->supplierRefusal(fn () => $this->supplierRefund('50.00', $treasurer, $locked));

        $this->assertSame(AccountingError::TreasuryDepositDenied, $refusal->error);
        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));

        $this->supplierRefund('50.00', $treasurer, $own);

        $this->assertSame('50.0000', $treasuries->position($own)->balance);
    }

    public function testRefund_DoesNotLetTheWithdrawScopeStandInForTheDepositScope(): void
    {
        $treasurer  = $this->treasurer();
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Pay-out only', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $treasurer);

        $this->advanceToSupplier('300.00');
        $this->supplierRefund('50.00', $treasurer, $drawer);

        $this->assertSame('50.0000', $treasuries->position($drawer)->balance);
    }

    public function testRefund_RefusesABranchTheEmployeeDoesNotWorkIn(): void
    {
        $this->advanceToSupplier('300.00');

        $other   = $this->createBranch('B2');
        $limited = $this->employeeWithGrants([AccountingGuard::PAYABLES_REFUND], [$other]);
        $drawer  = (new TreasuryService())->create(['name' => 'Branch one drawer', 'kind' => 'cash', 'branch_id' => 1], $this->treasurer());

        $this->supplierRefusal(fn () => $this->supplierRefund('50.00', $limited, $drawer));

        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));
    }

    public function testRefund_IsRefusedInAClosedPeriodAndSavesNothing(): void
    {
        $this->advanceToSupplier('300.00');
        $this->closeThrough(date('Y-m-d'));

        $exception = $this->supplierRefusal(fn () => $this->supplierRefund('50.00'));

        $this->assertStringContainsString(date('Y-m-d'), $exception->getMessage());
        $this->assertSame(0, $this->countRows('supplier_credit_refunds'));
        $this->assertSame(0, $this->countRows('supplier_credit_refund_sources'));
        $this->assertSame('300.00', bcadd($this->supplierPosition()->credit(), '0', 2));
    }

    public function testRefund_RefusesMoreThanTheCreditThatIsFreeBecauseWhatWasSpentOnAPurchaseIsNotFree(): void
    {
        $this->advanceToSupplier('100.00');
        $this->purchaseOf('60.00');

        $this->assertSame('40.00', bcadd($this->supplierPosition()->credit(), '0', 2));

        $refusal = $this->supplierRefusal(fn () => $this->supplierRefund('40.01'));

        $this->assertSame(AccountingError::RefundExceedsCredit, $refusal->error);
        $this->assertSame('40.00', $refusal->context['max']);

        $this->supplierRefund('40.00');

        $this->assertSame('0.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testRefund_TakesTheCreditOfTheOldestSourcesFirst(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));
        $note     = $this->debitNoteOf($purchase, '40.00');
        $advance  = (new SupplierAdvances())->pay($this->supplierId, '100.00', $this->daysAgo(3), 'Cash', null, '', '', $this->treasurer());

        $this->accountingDb()->table('receivings')->where('receiving_id', $note)->update(['receiving_time' => $this->daysAgo(1) . ' 10:00:00']);
        $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'purchase', 'source_id' => $note])->update(['journal_date' => $this->daysAgo(1)]);

        $refundId = $this->supplierRefund('120.00');
        $by       = [];

        foreach ($this->accountingDb()->table('supplier_credit_refund_sources')->where('refund_id', $refundId)->get()->getResult() as $row) {
            $by[$row->source_type . ':' . $row->source_id] = bcadd($row->amount, '0', 2);
        }

        $this->assertEquals(['supplier_advance:' . $advance => '100.00', 'debit_note:' . $note => '20.00'], $by);
        $this->assertSupplierTies();
    }

    public function testRefund_ForbidsCuttingThePaymentWhoseExcessWasPaidBackAndChangesNothing(): void
    {
        $purchase = $this->purchaseOf('230.00');
        $payment  = $this->payPurchase($purchase, '300.00');

        $this->supplierRefund('70.00');

        $journals = $this->countRows('accounting_journals');

        $this->assertFalse($this->changePayment($purchase, $payment, ['payment_amount' => '230.00']));
        $this->assertSame(AccountingError::CreditRefunded, $this->purchases->accountingFailure()->error);
        $this->assertSame($journals, $this->countRows('accounting_journals'));
        $this->assertSame('300.0000', bcadd($this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->get()->getRow()->payment_amount, '0', 4));
        $this->assertSupplierTies();
    }

    public function testRefund_ForbidsCuttingThePaymentBelowWhatWasPaidBackAndAllowsCuttingItAboveIt(): void
    {
        $purchase = $this->purchaseOf('230.00');
        $payment  = $this->payPurchase($purchase, '300.00');

        $this->supplierRefund('50.00');

        $this->assertFalse($this->changePayment($purchase, $payment, ['payment_amount' => '270.00']));
        $this->assertSame(AccountingError::CreditRefunded, $this->purchases->accountingFailure()->error);
        $this->assertSame('300.0000', bcadd($this->accountingDb()->table('receivings_payments')->where('payment_id', $payment)->get()->getRow()->payment_amount, '0', 4));

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_amount' => '290.00']));
        $this->assertSame('10.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testRefund_AllowsCuttingThePaymentOnceTheRefundIsCancelled(): void
    {
        $purchase = $this->purchaseOf('230.00');
        $payment  = $this->payPurchase($purchase, '300.00');
        $refundId = $this->supplierRefund('70.00');

        $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $this->treasurer());

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_amount' => '230.00']));
        $this->assertSupplierTies();
    }

    public function testCancel_ReversesTheJournalGivesTheCreditBackAndSpendsItOnThePurchaseThatWaited(): void
    {
        $treasurer = $this->treasurer();

        $this->advanceToSupplier('100.00');

        $refundId = $this->supplierRefund('100.00', $treasurer);
        $purchase = $this->purchaseOf('80.00');

        $this->assertSame('80.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));

        $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $treasurer);

        $this->assertSame('0.00', bcadd($this->supplierPosition()->purchase($purchase)->open(), '0', 2));
        $this->assertSame('20.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSame('cancelled', $this->accountingDb()->table('supplier_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->status);
        $this->assertSupplierTies();
    }

    public function testCancel_RefusesAnEmployeeWhoMayNotWithdrawFromTheTreasuryTheRefundWentInto(): void
    {
        $treasurer  = $this->treasurer();
        $treasuries = new TreasuryService();
        $other      = $this->employeeWithGrants([]);
        $drawer     = $treasuries->create(['name' => 'Supplier receipts', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$treasurer]], $treasurer);

        $this->advanceToSupplier('300.00');

        $refundId = $this->supplierRefund('50.00', $treasurer, $drawer);

        $treasuries->update($drawer, ['name' => 'Supplier receipts', 'kind' => 'cash', 'withdraw_scope' => 'staff', 'withdraw_members' => [$other]], $treasurer);

        $this->assertSame(AccountingError::TreasuryWithdrawDenied, $this->supplierRefusal(fn () => $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $treasurer))->error);
        $this->assertSame('completed', $this->accountingDb()->table('supplier_credit_refunds')->where('refund_id', $refundId)->get()->getRow()->status);
    }

    public function testRefund_ForbidsCancellingTheAdvanceWhoseCreditWasPaidBackUntilTheRefundIsCancelled(): void
    {
        $treasurer = $this->treasurer();
        $advanceId = $this->advanceToSupplier('100.00');
        $refundId  = $this->supplierRefund('60.00', $treasurer);

        $this->assertSame(AccountingError::CreditRefunded, $this->supplierRefusal(fn () => (new SupplierAdvances())->cancel($advanceId, 'taken by mistake', $treasurer))->error);

        $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $treasurer);
        (new SupplierAdvances())->cancel($advanceId, 'taken by mistake', $treasurer);

        $this->assertSame('0.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testRefund_SavesOneRefundWhenTheFormIsSentTwiceAndWritesItsAuditEntries(): void
    {
        $treasurer = $this->treasurer();

        $this->advanceToSupplier('300.00');

        $first  = $this->supplierRefund('50.00', $treasurer, null, null, 'supplier-form-1');
        $second = $this->supplierRefund('50.00', $treasurer, null, null, 'supplier-form-1');

        $this->assertSame($first, $second);
        $this->assertSame(1, $this->countRows('supplier_credit_refunds'));
        $this->assertSame(1, $this->accountingDb()->table('accounting_journals')->where('source_type', CreditRefunds::SUPPLIER_SOURCE)->countAllResults());

        $this->refundsFromSuppliers()->cancel($first, 'paid twice', $treasurer);

        $entries = $this->accountingDb()->table('accounting_audit_log')->where(['entity_type' => 'supplier_refund', 'entity_id' => $first])->orderBy('audit_id')->get()->getResult();

        $this->assertSame(['created', 'cancelled'], array_column($entries, 'action'));
        $this->assertSame((string) $treasurer, (string) $entries[0]->employee_id);
    }

    public function testRefund_NeverTakesTheCreditOfAnotherSupplier(): void
    {
        $other = $this->anotherSupplier();

        $this->advanceToSupplier('100.00');

        $this->assertSame(AccountingError::RefundExceedsCredit, $this->supplierRefusal(fn () => $this->refundsFromSuppliers()->refund($other, '10.00', date('Y-m-d'), 'Cash', null, '', '', $this->treasurer()))->error);
        $this->assertSame(AccountingError::RefundPartyMissing, $this->supplierRefusal(fn () => $this->refundsFromSuppliers()->refund(99999999, '10.00', date('Y-m-d'), 'Cash', null, '', '', $this->treasurer()))->error);
    }
}
