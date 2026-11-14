<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\SupplierAdvances;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\CreditRefundFixtureTrait;
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
    use CreditRefundFixtureTrait;

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
    // What the credit a supplier refund took from protects (WP23)
    // ---------------------------------------------------------------------------------------

    public function testSettle_ForbidsCuttingThePaymentWhoseExcessWasPaidBackAndChangesNothing(): void
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

    public function testSettle_ForbidsCuttingThePaymentBelowWhatWasPaidBackAndAllowsCuttingItAboveIt(): void
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

    public function testSettle_AllowsCuttingThePaymentOnceTheRefundIsCancelled(): void
    {
        $purchase = $this->purchaseOf('230.00');
        $payment  = $this->payPurchase($purchase, '300.00');
        $refundId = $this->supplierRefund('70.00');

        $this->refundsFromSuppliers()->cancel($refundId, 'paid twice', $this->supplierTreasurer());

        $this->assertTrue($this->changePayment($purchase, $payment, ['payment_amount' => '230.00']));
        $this->assertSupplierTies();
    }

    public function testSettle_RefusesByNameToSpendAnAdvanceToTheSupplierOnAPurchaseInDollars(): void
    {
        $this->enableDollars();
        $purchase = $this->foreignPurchase();

        try {
            (new SupplierAdvances())->pay($this->supplierId, '100.00', date('Y-m-d'), lang('Sales.cash'), null, '', '', $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]));
            $this->fail('The advance was paid although it would have been spent on a purchase in dollars.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditInForeignPurchase, $refusal->error);
            $this->assertSame(['USD', 'RECV ' . $purchase], [$refusal->context['code'], $refusal->context['name']]);
            $this->assertStringContainsString('USD', AccountingMessage::of($refusal));
        }

        $this->assertSame([], $this->supplierApplications());
        $this->assertSame(0, $this->countRows('supplier_advances'), 'the advance went with the refusal');
        $this->assertSame('750.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testSettle_RefusesByNameAPurchaseInDollarsForASupplierWhoHoldsAnAdvance(): void
    {
        $this->enableDollars();
        (new SupplierAdvances())->pay($this->supplierId, '100.00', date('Y-m-d'), lang('Sales.cash'), null, '', '', $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]));

        $purchases = $this->countRows('receivings');
        $journals  = $this->countJournals();

        $this->assertSame(ACCOUNTING_FAILED, $this->foreignPurchase());
        $this->assertSame(AccountingError::CreditInForeignPurchase, $this->purchases->accountingFailure()->error);
        $this->assertSame([$purchases, $journals], [$this->countRows('receivings'), $this->countJournals()]);
        $this->assertSame([], $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testSettle_AppliesTheDebitNoteOfAPurchaseInDollarsToItAtTheRateOfThePurchase(): void
    {
        $this->enableDollars();
        $purchase = $this->foreignPurchase();
        $note     = $this->debitNoteOf($purchase, '50.00');

        $this->assertGreaterThan(0, $note);
        $this->assertSame(['debit_note:' . $note . '->' . $purchase . ':187.50'], $this->supplierApplications());
        $this->assertSupplierTies();
    }

    public function testSettle_SettlesAPurchaseInDollarsPaidInFullAtOnceWithoutTouchingTheAdvance(): void
    {
        $this->enableDollars();
        (new SupplierAdvances())->pay($this->supplierId, '100.00', date('Y-m-d'), lang('Sales.cash'), null, '', '', $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]));

        $purchase = $this->foreignPurchase('4', '50.00', lang('Sales.cash'));

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame([], $this->supplierApplications());
        $this->assertSame('0.0000', $this->supplierPosition()->purchase($purchase)->open());
        $this->assertSupplierTies();
    }
}
