<?php

namespace Tests\Libraries\Accounting;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityFixture;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What happens to the credit of a customer, driven through the real sale model: a credit note is
 * paid into its invoice, credit beyond it waits, a new invoice is settled from credit at once,
 * and credit that no longer holds is taken back, so that what is owed on the open invoices always
 * adds up to the account of the customer.
 */
class CreditSettlementTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
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

        $this->startSelling();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded: fixture T-10
    // ---------------------------------------------------------------------------------------

    public function testSettle_PaysACreditNoteIntoTheInvoiceItCameFromAsTheReferenceSystemDoes(): void
    {
        $case    = $this->receivable('credit-note-is-paid-into-its-invoice', 'exact');
        $invoice = $this->invoiceOf($case['inputs']['invoice_total']);
        $note    = $this->creditNoteOf($invoice, $case['inputs']['credit_note']);

        $figures = $this->position()->invoice($invoice);

        $this->assertSame($case['expected']['applied'], bcadd($figures->applied, '0', 2));
        $this->assertSame($case['expected']['payment_state'], $figures->paymentState()->value);
        $this->assertSame($case['expected']['return_state'], $figures->returnState()->value);
        $this->assertSame(['credit_note:' . $note . '->' . $invoice . ':253.00'], $this->applications());
        $this->assertTies();
    }

    public function testSettle_LeavesWhatACreditNoteHasBeyondItsInvoiceAsCreditAsTheReferenceSystemDoes(): void
    {
        $case    = $this->receivable('credit-note-larger-than-the-invoice-leaves-credit', 'exact');
        $invoice = $this->invoiceOf($case['inputs']['invoice_total']);

        $this->creditNoteOf($invoice, $case['inputs']['credit_note']);

        $position = $this->position();

        $this->assertSame($case['expected']['credit'], bcadd($position->credit(), '0', 2));
        $this->assertSame($case['expected']['customer_balance'], bcadd($position->accountBalance, '0', 2));
        $this->assertSame('0.00', bcadd($position->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_SettlesANewInvoiceFromTheCreditAtOnceWithoutAJournalAsTheReferenceSystemDoes(): void
    {
        $case    = $this->receivable('credit-settles-a-new-invoice-at-once', 'exact');
        $first   = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($first, bcadd($case['inputs']['credit'], '100.00', 2));
        $before  = $this->countJournals();

        $this->assertSame($case['inputs']['credit'], bcadd($this->position()->credit(), '0', 2));

        $second  = $this->invoiceOf($case['inputs']['invoice_total']);
        $journal = $this->countJournals() - $before;

        $figures = $this->position()->invoice($second);

        $this->assertSame($case['expected']['applied'], bcadd($figures->applied, '0', 2));
        $this->assertSame($case['expected']['open'], bcadd($figures->open(), '0', 2));
        $this->assertSame(1, $journal, 'The new invoice writes its own journal and the application writes none.');
        $this->assertSame($case['expected']['journals_for_the_application'], $this->journalsOfApplications($second));
        $this->assertContains('credit_note:' . $note . '->' . $second . ':156.25', $this->applications());
        $this->assertTies();
    }

    public function testSettle_KeepsAnOverpaymentWholeAndShowsItAsPaidInExcessAsTheReferenceSystemDoes(): void
    {
        $case    = $this->receivable('overpayment-on-an-invoice', 'exact');
        $invoice = $this->invoiceOf($case['inputs']['invoice_total']);
        $payment = $this->addPayment($invoice, $case['inputs']['payment']);

        $payments = $this->accountingDb()->table('accounting_journals')->where(['source_type' => 'sale_payment', 'source_id' => $payment])->get()->getResult();

        $this->assertCount(1, $payments);
        $this->assertSame($case['expected']['payment_journal'], bcadd($payments[0]->total_debit, '0', 2));
        $this->assertSame($case['expected']['customer_balance'], $this->balance($this->customerAccount()));
        $this->assertSame($case['expected']['payment_state'], $this->position()->invoice($invoice)->paymentState()->value);
        $this->assertSame('70.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_LetsACreditNoteAndTwoExcessesPayOneInvoiceAsTheReferenceSystemDoes(): void
    {
        $case    = $this->receivable('three-credits-settle-one-invoice', 'exact');
        $invoice = $this->invoiceOf($case['inputs']['invoice_total']);

        $this->creditNoteOf($invoice, $case['inputs']['credit_note']);

        foreach ($case['inputs']['excesses'] as $excess) {
            $source = $this->invoiceOf('100.00');

            $this->addPayment($source, bcadd('100.00', $excess, 2));
        }

        $figures = $this->position()->invoice($invoice);

        $this->assertSame($case['expected']['applied_total'], bcadd($figures->applied, '0', 2));
        $this->assertSame($case['expected']['open'], bcadd($figures->open(), '0', 2));
        $this->assertSame($case['expected']['credit_left'], bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_GivesBackTheCreditAnotherInvoiceSpentWhenThePaymentThatGaveItIsDeleted(): void
    {
        $case   = $this->receivable('deleting-a-payment-after-its-excess-was-spent', 'differs');
        $first  = $this->invoiceOf('70.00');
        $second = $this->invoiceOf('230.00');
        $paid   = $this->addPayment($second, '300.00');

        $this->assertSame('0.00', bcadd($this->position()->open(), '0', 2));
        $this->assertSame(['invoice_excess:' . $second . '->' . $first . ':70.00'], $this->applications());

        $this->removePayment($second, $paid);

        $position = $this->position();

        $this->assertNotSame($case['reference']['first_invoice'], $case['expected']['first_invoice']);
        $this->assertSame('70.00', bcadd($position->invoice($first)->open(), '0', 2));
        $this->assertSame('230.00', bcadd($position->invoice($second)->open(), '0', 2));
        $this->assertSame('300.00', $this->balance($this->customerAccount()));
        $this->assertSame([], $this->applications());
        $this->assertTies();
    }

    // ---------------------------------------------------------------------------------------
    // The rules of settling
    // ---------------------------------------------------------------------------------------

    public function testSettle_SpendsAnOverpaymentOnTheOldestOpenInvoicesFirst(): void
    {
        $oldest = $this->invoiceOf('100.00');
        $newer  = $this->invoiceOf('100.00');
        $small  = $this->invoiceOf('50.00');

        $this->addPayment($small, '170.00');

        $this->assertSame(['invoice_excess:' . $small . '->' . $oldest . ':100.00', 'invoice_excess:' . $small . '->' . $newer . ':20.00'], $this->applications());
        $this->assertSame('0.00', bcadd($this->position()->invoice($oldest)->open(), '0', 2));
        $this->assertSame('80.00', bcadd($this->position()->invoice($newer)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_PaysACreditNoteIntoItsOwnInvoiceBeforeAnyOther(): void
    {
        $other = $this->invoiceOf('100.00');
        $own   = $this->invoiceOf('300.00');
        $note  = $this->creditNoteOf($own, '150.00');

        $this->assertSame(['credit_note:' . $note . '->' . $own . ':150.00'], $this->applications());
        $this->assertSame('100.00', bcadd($this->position()->invoice($other)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_SendsTheRestOfACreditNoteToTheOldestOpenInvoice(): void
    {
        $oldest = $this->invoiceOf('200.00');
        $own    = $this->invoiceOf('100.00');
        $note   = $this->creditNoteOf($own, '150.00');

        $this->assertSame(['credit_note:' . $note . '->' . $own . ':100.00', 'credit_note:' . $note . '->' . $oldest . ':50.00'], $this->applications());
        $this->assertSame('150.00', bcadd($this->position()->invoice($oldest)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_TakesBackWhatWasPaidIntoAnInvoiceThatIsCancelled(): void
    {
        $first = $this->invoiceOf('100.00');

        $this->creditNoteOf($first, '256.25');

        $pos = $this->invoiceOf('172.50', null, [], SALE_TYPE_POS);

        $this->assertSame('16.25', bcadd($this->position()->invoice($pos)->open(), '0', 2));

        $this->assertTrue($this->sales->delete($pos, false, true, $this->employeeId));

        $this->assertSame('156.25', bcadd($this->position()->credit(), '0', 2));
        $this->assertSame([], array_filter($this->applications(), static fn (string $row): bool => str_contains($row, '->' . $pos . ':')));
        $this->assertSame('-156.25', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testSettle_CutsTheNewestCreditPaidIntoAnInvoiceWhenCashIsPaidIntoItAfterwards(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '100.00');

        $this->assertSame('0.00', bcadd($this->position()->open(), '0', 2));

        $this->addPayment($invoice, '30.00');

        $figures = $this->position()->invoice($invoice);

        $this->assertSame('70.00', bcadd($figures->applied, '0', 2));
        $this->assertSame('30.00', bcadd($figures->paid, '0', 2));
        $this->assertSame('0.00', bcadd($figures->excess(), '0', 2));
        $this->assertSame('30.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertContains('credit_note:' . $note . '->' . $invoice . ':70.00', $this->applications());
        $this->assertSame('-30.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testSettle_KeepsTheHistoryOfWhatItReleasedInsteadOfDeletingIt(): void
    {
        $first  = $this->invoiceOf('70.00');
        $second = $this->invoiceOf('230.00');
        $paid   = $this->addPayment($second, '300.00');

        $this->removePayment($second, $paid);

        $rows = $this->accountingDb()->table('customer_credit_applications')->where('invoice_sale_id', $first)->get()->getResult();

        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->released_at);
        $this->assertSame((string) $this->employeeId, (string) $rows[0]->released_by);
    }

    public function testSettle_RunAgainChangesNothing(): void
    {
        $first  = $this->invoiceOf('70.00');
        $second = $this->invoiceOf('230.00');

        $this->addPayment($second, '300.00');

        $rows = $this->accountingDb()->table('customer_credit_applications')->get()->getResultArray();

        $this->settle();
        $this->settle();

        $this->assertSame($rows, $this->accountingDb()->table('customer_credit_applications')->get()->getResultArray());
        $this->assertSame('0.00', bcadd($this->position()->invoice($first)->open(), '0', 2));
    }

    public function testSettle_LeavesTheCreditUntouchedWhenTheSaleThatWouldHaveSpentItIsRefused(): void
    {
        $first = $this->invoiceOf('100.00');

        $this->creditNoteOf($first, '250.00');

        $before = $this->accountingDb()->table('customer_credit_applications')->get()->getResultArray();
        $sales  = $this->countSales();
        $cream  = $this->item('40.00');

        $this->accountingDb()->table('accounting_accounts')->where('account_id', $this->stockAccount())->update(['active' => 0]);

        $result = $this->save([$this->line($cream, 0, '1', '80', '40.00')]);

        $this->assertSame(ACCOUNTING_FAILED, $result);
        $this->assertSame($sales, $this->countSales());
        $this->assertSame($before, $this->accountingDb()->table('customer_credit_applications')->get()->getResultArray());
        $this->assertSame('150.00', bcadd($this->position()->credit(), '0', 2));
    }

    public function testSettle_SpendsCreditOnAnInvoiceOfAnotherBranchBecauseTheCreditBelongsToTheCustomer(): void
    {
        $first  = $this->invoiceOf('100.00');
        $second = $this->invoiceOf('60.00');
        $branch = $this->createBranch();

        $this->accountingDb()->table('sales')->where('sale_id', $second)->update(['branch_id' => $branch]);
        $this->creditNoteOf($first, '250.00');

        $this->assertSame('0.00', bcadd($this->position()->invoice($second)->open(), '0', 2));
        $this->assertSame('90.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_NeverMovesTheCreditOfOneCustomerToAnother(): void
    {
        $other   = $this->anotherCustomer();
        $invoice = $this->invoiceOf('100.00');

        $this->creditNoteOf($invoice, '250.00');
        $this->invoiceOf('80.00', $other);

        $this->assertSame('150.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertSame('80.00', bcadd($this->position($other)->open(), '0', 2));
        $this->assertSame([], $this->applications($other));
        $this->assertTies();
        $this->assertTies($other);
    }

    public function testSettle_CountsAReturnPaidBackInCashAgainstTheCreditItGave(): void
    {
        $item     = $this->item('60.00');
        $invoice  = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $first    = $this->save([$this->line($item, 0, '-2', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);
        $refunded = $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], [$this->payment(lang('Sales.cash'), '-100.00')], SALE_TYPE_RETURN, -1, true, $invoice);

        $sources = [];

        foreach ($this->position()->credits as $source) {
            $sources[$source->sourceId] = bcadd($source->available(), '0', 2);
        }

        $this->assertSame([$first => '200.00', $refunded => '0.00'], $sources);
        $this->assertSame('-200.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testSettle_LeavesASaleWithoutACustomerOutOfTheLedger(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '40')], [], [$this->payment(lang('Sales.cash'), '40.00')], SALE_TYPE_POS, null);

        $this->assertGreaterThan(0, $saleId);
        $this->assertSame([], $this->applications());
        $this->assertSame([], $this->position()->invoices);
    }

    /**
     * @return array<string, mixed>
     */
    private function receivable(string $id, string $classification): array
    {
        $case = ParityFixture::cases('T-10', 'receivable')[$id][0];

        $this->assertSame($classification, $case['classification'], $id);

        if ($classification === 'exact') {
            $this->assertSame($case['reference'], $case['expected'], "{$id} is recorded as exact and must expect what the reference system recorded");
        } else {
            $this->assertNotSame($case['reference'], $case['expected'], "{$id} is recorded as a difference and cannot expect what the reference system does");
        }

        return $case;
    }

    private function journalsOfApplications(int $invoiceId): int
    {
        return $this->accountingDb()->table('accounting_journals')->like('description', 'credit', 'both')->where('source_id', $invoiceId)->whereNotIn('source_type', ['sale', 'sale_payment'])->countAllResults();
    }
}
