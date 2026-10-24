<?php

namespace Tests\Libraries\Accounting;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
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

    /**
     * @return array<string, mixed>
     */
    private function payable(string $id, string $classification): array
    {
        $case = ParityFixture::cases('T-13', 'payable')[$id][0];

        $this->assertSame($classification, $case['classification'], $id);

        if ($classification === 'exact') {
            $this->assertSame($case['reference'], $case['expected'], "{$id} is recorded as exact and must expect what the reference system recorded");
        } else {
            $this->assertNotSame($case['reference'], $case['expected'], "{$id} is recorded as a difference and cannot expect what the reference system does");
        }

        return $case;
    }
}
