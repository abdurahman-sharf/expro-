<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingError;
use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AccountingMessage;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\Currencies;
use App\Libraries\Accounting\CustomerAdvances;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
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
    use ParityCaseTrait;
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

    public function testSettle_SendsACreditNoteToTheOldestOpenInvoiceAndNotToItsOwn(): void
    {
        $other = $this->invoiceOf('100.00');
        $own   = $this->invoiceOf('300.00');
        $note  = $this->creditNoteOf($own, '150.00');

        $this->assertSame(['credit_note:' . $note . '->' . $other . ':100.00', 'credit_note:' . $note . '->' . $own . ':50.00'], $this->applications());
        $this->assertSame('0.00', bcadd($this->position()->invoice($other)->open(), '0', 2));
        $this->assertSame('250.00', bcadd($this->position()->invoice($own)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_SendsAWholeCreditNoteToTheOldestOpenInvoiceBeforeItsOwn(): void
    {
        $oldest = $this->invoiceOf('200.00');
        $own    = $this->invoiceOf('100.00');
        $note   = $this->creditNoteOf($own, '150.00');

        $this->assertSame(['credit_note:' . $note . '->' . $oldest . ':150.00'], $this->applications());
        $this->assertSame('50.00', bcadd($this->position()->invoice($oldest)->open(), '0', 2));
        $this->assertSame('100.00', bcadd($this->position()->invoice($own)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_SendsACreditNoteToItsOwnInvoiceWhenThatInvoiceIsTheOldestByDate(): void
    {
        $own   = $this->invoiceDated('300.00', $this->daysAgo(10));
        $other = $this->invoiceDated('100.00', $this->daysAgo(3));
        $note  = $this->creditNoteOf($own, '150.00');

        $this->assertSame(['credit_note:' . $note . '->' . $own . ':150.00'], $this->applications());
        $this->assertSame('100.00', bcadd($this->position()->invoice($other)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_SendsACreditNoteToAnOlderInvoiceByDateEvenWhenItIsCreatedAfterItsOrigin(): void
    {
        $own   = $this->invoiceDated('300.00', $this->daysAgo(3));
        $older = $this->invoiceDated('100.00', $this->daysAgo(10));
        $note  = $this->creditNoteOf($own, '120.00');

        $this->assertGreaterThan($own, $older);
        $this->assertSame(['credit_note:' . $note . '->' . $older . ':100.00', 'credit_note:' . $note . '->' . $own . ':20.00'], $this->applications());
        $this->assertTies();
    }

    public function testSettle_FollowsTheDateTypedOnTheInvoiceAndNotTheOrderTheyWereSavedIn(): void
    {
        $savedFirst  = $this->invoiceDated('100.00', $this->daysAgo(2));
        $savedSecond = $this->invoiceDated('100.00', $this->daysAgo(20));
        $payer       = $this->invoiceOf('10.00');

        $this->addPayment($payer, '60.00');

        $this->assertSame(['invoice_excess:' . $payer . '->' . $savedSecond . ':50.00'], $this->applications());
        $this->assertSame('100.00', bcadd($this->position()->invoice($savedFirst)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_BreaksATieOfDatesByTheOrderTheInvoicesWereCreatedInAsTheReferenceSystemDoes(): void
    {
        $case    = $this->receivable('credit-goes-to-the-invoice-created-first-when-the-dates-tie', 'exact');
        $first   = $this->invoiceOf($case['inputs']['invoices'][0]['open']);
        $second  = $this->invoiceOf($case['inputs']['invoices'][1]['open']);
        $day     = $this->daysAgo(5);
        $service = $this->item('0', false);

        $this->accountingDb()->table('sales')->whereIn('sale_id', [$first, $second])->update(['sale_time' => $day . ' 10:00:00']);
        $this->accountingDb()->table('sales')->where('sale_id', $first)->update(['invoice_number' => $case['inputs']['invoices'][0]['number']]);
        $this->accountingDb()->table('sales')->where('sale_id', $second)->update(['invoice_number' => $case['inputs']['invoices'][1]['number']]);

        $names = [$first => 'created_first', $second => 'created_second'];

        foreach ($case['inputs']['credits'] as $credit) {
            $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '-1', $credit)], [], [], SALE_TYPE_RETURN));
        }

        $applied = array_map(
            static fn (object $row): array => ['invoice' => $names[(int) $row->invoice_sale_id], 'amount' => bcadd((string) $row->amount, '0', 2)],
            (new CustomerLedger())->applications($this->customerId),
        );

        $this->assertLessThan((int) $second, (int) $first);
        $this->assertSame([], ParityFixture::differences($case['reference'], ['applied' => $applied]));
        $this->assertSame([], ParityFixture::differences(['applied' => $applied], $case['reference']));
        $this->assertTies();
    }

    public function testSettle_BreaksATieOfDatesByCreationAndNotByInvoiceNumberOrAmount(): void
    {
        $large   = $this->invoiceOf('500.00');
        $small   = $this->invoiceOf('40.00');
        $service = $this->item('0', false);

        $this->accountingDb()->table('sales')->whereIn('sale_id', [$large, $small])->update(['sale_time' => $this->daysAgo(5) . ' 10:00:00']);
        $this->accountingDb()->table('sales')->where('sale_id', $large)->update(['invoice_number' => '000099']);
        $this->accountingDb()->table('sales')->where('sale_id', $small)->update(['invoice_number' => '000001']);

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '-1', '60.00')], [], [], SALE_TYPE_RETURN));
        $this->assertSame('440.00', bcadd($this->position()->invoice($large)->open(), '0', 2));
        $this->assertSame('40.00', bcadd($this->position()->invoice($small)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_SpendsAnAdvanceOnTheOldestOpenInvoiceByDateAndThenByCreation(): void
    {
        $later   = $this->invoiceDated('100.00', $this->daysAgo(2));
        $earlier = $this->invoiceDated('100.00', $this->daysAgo(9));
        $tieA    = $this->invoiceDated('100.00', $this->daysAgo(5));
        $tieB    = $this->invoiceDated('100.00', $this->daysAgo(5));

        $advance = (new CustomerAdvances())->receive($this->customerId, '250.00', date('Y-m-d'), 'Cash', null, '', '', $this->advanceTaker());

        $this->assertSame(
            [
                CreditSource::CUSTOMER_ADVANCE . ':' . $advance . '->' . $earlier . ':100.00',
                CreditSource::CUSTOMER_ADVANCE . ':' . $advance . '->' . $tieA . ':100.00',
                CreditSource::CUSTOMER_ADVANCE . ':' . $advance . '->' . $tieB . ':50.00',
            ],
            $this->applications(),
        );
        $this->assertSame('100.00', bcadd($this->position()->invoice($later)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_SpendsTheOlderCreditFirstWhenACreditNoteAndAnAdvanceWaitForOneInvoice(): void
    {
        $first = $this->invoiceDated('10.00', $this->daysAgo(20), null, null, [$this->payment(lang('Sales.cash'), '10.00')]);
        $note  = $this->creditNoteOf($first, '40.00');

        $this->accountingDb()->table('sales')->where('sale_id', $note)->update(['sale_time' => $this->daysAgo(8) . ' 10:00:00']);

        $advance = (new CustomerAdvances())->receive($this->customerId, '40.00', $this->daysAgo(3), 'Cash', null, '', '', $this->advanceTaker());
        $invoice = $this->invoiceOf('60.00');

        $this->assertSame(
            ['credit_note:' . $note . '->' . $invoice . ':40.00', CreditSource::CUSTOMER_ADVANCE . ':' . $advance . '->' . $invoice . ':20.00'],
            $this->applications(),
        );
        $this->assertTies();
    }

    public function testSettle_ReleasesWhatAnAdvanceSpentWhenItIsCancelledAndKeepsTheHistoryOfTheRelease(): void
    {
        $invoice = $this->invoiceOf('70.00');
        $taker   = $this->advanceTaker();
        $advance = (new CustomerAdvances())->receive($this->customerId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $taker);

        $this->assertSame([CreditSource::CUSTOMER_ADVANCE . ':' . $advance . '->' . $invoice . ':70.00'], $this->applications());

        (new CustomerAdvances())->cancel($advance, 'taken by mistake', $taker);

        $rows = $this->accountingDb()->table('customer_credit_applications')->where('invoice_sale_id', $invoice)->get()->getResult();

        $this->assertSame([], $this->applications());
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]->released_at);
        $this->assertSame((string) $taker, (string) $rows[0]->released_by);
        $this->assertSame('70.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_CutsTheAdvanceSpentOnAnInvoiceWhenCashIsPaidIntoItAfterwards(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $advance = (new CustomerAdvances())->receive($this->customerId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $this->advanceTaker());

        $this->assertSame('0.00', bcadd($this->position()->open(), '0', 2));

        $this->addPayment($invoice, '30.00');

        $this->assertSame([CreditSource::CUSTOMER_ADVANCE . ':' . $advance . '->' . $invoice . ':70.00'], $this->applications());
        $this->assertSame('30.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_RunAgainChangesNothingWhenAnAdvanceAndARefundStand(): void
    {
        $this->invoiceOf('60.00');

        $taker = $this->advanceTaker();

        (new CustomerAdvances())->receive($this->customerId, '200.00', date('Y-m-d'), 'Cash', null, '', '', $taker);
        (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($this->customerId, '50.00', date('Y-m-d'), 'Cash', null, '', '', $taker);

        $rows = $this->accountingDb()->table('customer_credit_applications')->get()->getResultArray();

        $this->settle();
        $this->settle();

        $this->assertSame($rows, $this->accountingDb()->table('customer_credit_applications')->get()->getResultArray());
        $this->assertSame('90.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_NeverSpendsCreditThatWasPaidBackInCash(): void
    {
        $taker = $this->advanceTaker();

        (new CustomerAdvances())->receive($this->customerId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $taker);
        (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($this->customerId, '70.00', date('Y-m-d'), 'Cash', null, '', '', $taker);

        $invoice = $this->invoiceOf('200.00');

        $this->assertSame('170.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('0.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertSame('170.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    private function advanceTaker(): int
    {
        return $this->employeeWithGrants([AccountingGuard::RECEIVABLES_MANAGE, AccountingGuard::RECEIVABLES_REFUND]);
    }

    private function dollarsAt(string $rate): void
    {
        $currencies = new Currencies();
        $currencies->setActive('USD', true);
        $currencies->rates()->add(['currency_code' => 'USD', 'rate' => $rate, 'effective_date' => $this->daysAgo(30)]);
    }

    public function testSettle_RefusesByNameToSpendAnAdvanceInTheBaseOnAnInvoiceInDollars(): void
    {
        $this->dollarsAt('3.75');
        $this->sales->useCurrency('USD');

        $invoice = $this->invoiceOf('100.00');
        $number  = (string) $this->accountingDb()->table('sales')->select('invoice_number')->where('sale_id', $invoice)->get()->getRow()->invoice_number;

        try {
            (new CustomerAdvances())->receive($this->customerId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $this->advanceTaker());
            $this->fail('The advance was taken although it would have been spent on an invoice in dollars.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditInForeignCurrency, $refusal->error);
            $this->assertSame('USD', $refusal->context['code']);
            $this->assertSame($number !== '' ? $number : 'POS ' . $invoice, $refusal->context['name']);
            $this->assertStringContainsString('USD', AccountingMessage::of($refusal));
        }

        $this->assertSame([], $this->applications());
        $this->assertSame(0, $this->accountingDb()->table('customer_advances')->countAllResults(), 'the advance went with the refusal');
        $this->assertSame('375.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('375.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testSettle_RefusesByNameAnInvoiceInDollarsForACustomerWhoHoldsAdvanceCredit(): void
    {
        $this->dollarsAt('3.75');

        $advance = (new CustomerAdvances())->receive($this->customerId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $this->advanceTaker());
        $this->sales->useCurrency('USD');
        $sales  = $this->countRows('sales');
        $before = $this->countJournals();

        $this->assertSame(ACCOUNTING_FAILED, $this->invoiceOf('100.00'));

        $failure = $this->sales->accountingFailure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::CreditInForeignCurrency, $failure->error);
        $this->assertSame([$sales, $before], [$this->countRows('sales'), $this->countJournals()]);
        $this->assertSame([], $this->applications());
        $this->assertSame('100.00', bcadd($this->position()->credit(), '0', 2), 'the advance is still free ' . $advance);
        $this->assertTies();
    }

    public function testSettle_SettlesAnInvoiceInDollarsPaidInFullAtOnceByItsPaymentWithoutTouchingTheCredit(): void
    {
        $this->dollarsAt('3.75');

        (new CustomerAdvances())->receive($this->customerId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $this->advanceTaker());
        $this->sales->useCurrency('USD');

        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '100.00')]);

        $this->assertGreaterThan(0, $invoice);
        $this->assertSame([], $this->applications());
        $this->assertSame('0.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('100.00', bcadd($this->position()->credit(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_RefusesByNameACreditNoteOfAnInvoiceInTheBaseWhenAnOlderInvoiceInDollarsIsOpen(): void
    {
        $this->dollarsAt('3.75');
        $this->grantInvoiceDate();

        $base = $this->invoiceOf('100.00');
        $this->sales->useCurrency('USD');
        $dollars = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));
        $this->sales->useCurrency(null);

        $this->assertGreaterThan(0, $dollars);

        $returns = $this->countRows('sales');

        $this->assertSame(ACCOUNTING_FAILED, $this->creditNoteOf($base, '40.00'));
        $this->assertSame(AccountingError::CreditInForeignCurrency, $this->sales->accountingFailure()->error);
        $this->assertSame($returns, $this->countRows('sales'));
        $this->assertSame([], $this->applications());
        $this->assertSame('375.00', bcadd($this->position()->invoice($dollars)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_RefusesByNameACreditNoteInDollarsAtAnotherRateOnAnInvoiceInDollars(): void
    {
        $this->dollarsAt('3.75');
        $this->grantInvoiceDate();

        $this->sales->useCurrency('USD');
        $first = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));
        (new Currencies())->rates()->add(['currency_code' => 'USD', 'rate' => '3.60', 'effective_date' => $this->daysAgo(3)]);
        $this->sales->useCurrency('USD');
        $second = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: date('Y-m-d'));

        $this->assertSame(['3.75000000', '3.60000000'], [$this->saleRow($first)->currency_rate, $this->saleRow($second)->currency_rate]);

        $returns = $this->countRows('sales');

        $this->assertSame(ACCOUNTING_FAILED, $this->creditNoteOf($second, '40.00'));
        $this->assertSame(AccountingError::CreditInForeignCurrency, $this->sales->accountingFailure()->error);
        $this->assertSame($returns, $this->countRows('sales'));
        $this->assertSame([], $this->applications());
        $this->assertTies();
    }

    public function testSettle_RefusesByNameToCancelAnInvoiceWhoseCreditWouldGoToAnOpenInvoiceInDollarsAndChangesNothing(): void
    {
        $this->dollarsAt('3.75');

        $advance = (new CustomerAdvances())->receive($this->customerId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $this->advanceTaker());
        $base    = $this->invoiceOf('100.00', null, [], SALE_TYPE_POS);

        $this->assertSame(['customer_advance:' . $advance . '->' . $base . ':100.00'], $this->applications());

        $this->grantInvoiceDate();
        $this->sales->useCurrency('USD');
        $dollars = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));

        $this->assertGreaterThan(0, $dollars);

        $journals = $this->countJournals();

        $this->assertFalse($this->sales->delete($base, false, true, $this->employeeId));
        $this->assertSame(AccountingError::CreditInForeignCurrency, $this->failure()->error);
        $this->assertSame($journals, $this->countJournals());
        $this->assertSame(['customer_advance:' . $advance . '->' . $base . ':100.00'], $this->applications(), 'the credit stays on the invoice it was on');
        $this->assertSame('375.00', bcadd($this->position()->invoice($dollars)->open(), '0', 2));
        $this->assertTies();
    }

    public function testSettle_RefusesByNameACreditNoteInEurosOnAnInvoiceInDollarsEvenAtTheSameRate(): void
    {
        $this->dollarsAt('3.75');
        (new Currencies())->setActive('EUR', true);
        (new Currencies())->rates()->add(['currency_code' => 'EUR', 'rate' => '3.75', 'effective_date' => $this->daysAgo(30)]);
        $this->grantInvoiceDate();

        $this->sales->useCurrency('USD');
        $dollars = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));
        $this->sales->useCurrency('EUR');
        $euros = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [$this->payment(lang('Sales.cash'), '100.00')], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(8));

        $this->assertSame(['USD', 'EUR'], [$this->saleRow($dollars)->currency_code, $this->saleRow($euros)->currency_code]);
        $this->assertSame(ACCOUNTING_FAILED, $this->creditNoteOf($euros, '40.00'));
        $this->assertSame(AccountingError::CreditInForeignCurrency, $this->sales->accountingFailure()->error);
        $this->assertSame([], $this->applications());
    }

    public function testSettle_RefusesACreditNoteInDollarsWhenTheRateOfNeitherItNorTheInvoiceWasStored(): void
    {
        $this->dollarsAt('3.75');

        $invoice = $this->foreignInvoice('100');
        $note    = $this->creditNoteOf($invoice, '40.00');

        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $this->customerId)->delete();
        $this->accountingDb()->table('sales')->whereIn('sale_id', [$invoice, $note])->update(['currency_rate' => null]);

        try {
            $this->settle();
            $this->fail('The credit note went to the invoice although the rate of one of them is not known.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditInForeignCurrency, $refusal->error);
        }

        $this->assertSame([], $this->applications());
    }

    public function testSettle_RefusesTheCreditNoteInDollarsOfAnInvoiceWhoseRateWasNotStored(): void
    {
        $this->dollarsAt('3.75');

        $invoice = $this->foreignInvoice('100');
        $this->creditNoteOf($invoice, '40.00');

        $this->accountingDb()->table('customer_credit_applications')->where('customer_id', $this->customerId)->delete();
        $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['currency_rate' => null]);

        try {
            $this->settle();
            $this->fail('The credit note went to the invoice although the rate of one of them is not known.');
        } catch (AccountingException $refusal) {
            $this->assertSame(AccountingError::CreditInForeignCurrency, $refusal->error);
        }

        $this->assertSame([], $this->applications());
    }

    public function testSettle_AppliesTheCreditNoteOfAnInvoiceInDollarsToAnOlderInvoiceInTheBase(): void
    {
        $this->dollarsAt('3.75');
        $this->grantInvoiceDate();

        $base = $this->save([$this->line($this->item('0', false), 0, '1', '500')], [], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(20));
        $this->sales->useCurrency('USD');
        $dollars = $this->save([$this->line($this->item('0', false), 0, '1', '100')], [], [$this->payment(lang('Sales.cash'), '100.00')], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, null, invoiceDate: $this->daysAgo(10));
        $this->sales->useCurrency(null);
        $note = $this->creditNoteOf($dollars, '40.00');

        $this->assertGreaterThan(0, $note);
        $this->assertSame(['credit_note:' . $note . '->' . $base . ':150.00'], $this->applications());
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

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixture T-34
    // ---------------------------------------------------------------------------------------

    /**
     * The invoices of a case, saved in the order of their names so that the date of each is not the
     * order it was saved in, and dated as the case says.
     *
     * @param list<array<string, string>> $invoices
     *
     * @return array<string, int> The invoice ids by name.
     */
    private function datedInvoices(array $invoices): array
    {
        $ids = [];

        foreach (array_reverse($invoices) as $invoice) {
            $ids[$invoice['name']] = $this->invoiceOf($invoice['open']);
            $this->accountingDb()->table('sales')->where('sale_id', $ids[$invoice['name']])->update(['sale_time' => $invoice['date'] . ' 10:00:00']);
        }

        return $ids;
    }

    /**
     * What the credit of the customer was applied to, as the invoices of the case and the amount.
     *
     * @param array<string, int> $ids
     *
     * @return list<array{invoice: string, amount: string}>
     */
    private function appliedTo(array $ids): array
    {
        $names   = array_flip($ids);
        $applied = [];

        foreach ($this->accountingDb()->table('customer_credit_applications')->where('released_at IS NULL', null, false)->orderBy('application_id')->get()->getResult() as $row) {
            $applied[] = ['invoice' => $names[(int) $row->invoice_sale_id], 'amount' => bcadd((string) $row->amount, '0', 2)];
        }

        return $applied;
    }

    public function testSettle_SendsTheCreditNoteOfAnInvoiceToTheOldestInvoiceAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-34', 'credit_order', 'credit-note-of-an-invoice-goes-to-the-oldest-invoice-first', 'exact');
        $ids  = $this->datedInvoices($case['inputs']['invoices']);

        $this->creditNoteOf($ids[$case['inputs']['credit_note']['of']], $case['inputs']['credit_note']['amount']);

        $this->assertSameAsFixture(['applied' => $case['expected']['applied']], ['applied' => $this->appliedTo($ids)], 'credit note of an invoice');
        $this->assertTies();
    }

    public function testSettle_AppliesACreditNoteWithNoInvoiceToTheOldestInvoicesFirstAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-34', 'credit_order', 'credit-without-an-invoice-goes-to-the-oldest-first', 'exact');
        $ids  = $this->datedInvoices($case['inputs']['invoices']);

        $service = $this->item('0', false);

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '-1', $case['inputs']['credit_note']['amount'])], [], [], SALE_TYPE_RETURN));
        $this->assertSameAsFixture(['applied' => $case['expected']['applied']], ['applied' => $this->appliedTo($ids)], 'credit note with no invoice');
        $this->assertTies();
    }

    public function testSettle_AppliesTheCreditAtOnceAndWritesNoJournalForTheApplicationAsTheReferenceSystemDoes(): void
    {
        $case    = $this->referenceCase('T-34', 'credit_application', 'applying-credit-writes-no-journal', 'exact');
        $ids     = $this->datedInvoices($case['inputs']['invoices']);
        $service = $this->item('0', false);
        $before  = $this->countJournals();

        $this->assertGreaterThan(0, $this->save([$this->line($service, 0, '-1', $case['inputs']['credit_note']['amount'])], [], [], SALE_TYPE_RETURN));

        $this->assertSameAsFixture($case['expected'], [
            'journals_for_the_application' => (string) ($this->countJournals() - $before - 1),
            'applied_without_any_action'   => $this->appliedTo($ids) !== [],
        ], 'application');
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the third write batch: fixture T-64
    // ---------------------------------------------------------------------------------------

    public function testSettle_ReleasesTheCreditPaidIntoAnInvoiceAtOnceWhenItsSourceIsGoneWhereTheReferenceSystemLeavesItStale(): void
    {
        $case   = $this->referenceCase('T-64', 'reference_quirk', 'credit-paid-into-an-invoice-is-released-when-its-source-is-gone', 'deliberate_difference');
        $first  = $this->invoiceOf('70.00');
        $second = $this->invoiceOf('230.00');
        $paid   = $this->addPayment($second, '300.00');

        $this->assertSame(['invoice_excess:' . $second . '->' . $first . ':70.00'], $this->applications());

        $this->removePayment($second, $paid);

        $this->assertSameAsFixture($case['expected'], ['credit_stays_on_the_invoice_after_its_source_is_gone' => $this->applications() !== []], 'credit after its source is removed');
        $this->assertTies();
    }

    private function opening(string $amount, int $daysAgo): int
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        try {
            return (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, $amount, $this->daysAgo($daysAgo), '', null);
        } finally {
            $this->setConfigValue('accounting_customer_opening', '0');
        }
    }

    public function testSettle_PaysAnOpeningBalanceBeforeAnyInvoiceBecauseItIsTheOldest(): void
    {
        $opening = $this->opening('300.00', 60);
        $invoice = $this->invoiceDated('200.00', $this->daysAgo(5));
        $extra   = $this->invoiceDated('50.00', $this->daysAgo(1), null, null, [$this->payment(lang('Sales.cash'), '120.00')]);

        $position = $this->position();

        $this->assertSame('230.00', bcadd($position->invoice(-$opening)->open(), '0', 2));
        $this->assertSame('200.00', bcadd($position->invoice($invoice)->open(), '0', 2));
        $this->assertSame('0.00', bcadd($position->invoice($extra)->open(), '0', 2));
        $this->assertSame(['invoice_excess:' . $extra . '->opening' . $opening . ':70.00'], array_map(
            static fn (object $row): string => $row->source_type . ':' . $row->source_id . '->' . ($row->invoice_sale_id ?? 'opening' . $row->opening_id) . ':' . bcadd($row->amount, '0', 2),
            (new CustomerLedger())->applications($this->customerId),
        ));
        $this->assertTies();
    }

    public function testSettle_GivesBackWhatWasPaidIntoAnOpeningBalanceWhenItIsCancelled(): void
    {
        $opening = $this->opening('300.00', 60);
        $this->invoiceDated('50.00', $this->daysAgo(1), null, null, [$this->payment(lang('Sales.cash'), '120.00')]);

        $this->setConfigValue('accounting_customer_opening', '1');
        (new CustomerOpening())->cancel($opening, 'Entered twice', null);
        $this->setConfigValue('accounting_customer_opening', '0');

        $this->assertSame([], (new CustomerLedger())->applications($this->customerId));
        $this->assertCount(1, (new CustomerLedger())->applications($this->customerId, true));
        $this->assertSame('70.0000', $this->position()->credit());
        $this->assertTies();
    }

    public function testSettle_ChangesNothingWhenRunAgainAfterAnOpeningBalanceHasBeenPaid(): void
    {
        $opening = $this->opening('300.00', 60);
        $this->invoiceDated('50.00', $this->daysAgo(1), null, null, [$this->payment(lang('Sales.cash'), '120.00')]);
        $before = (new CustomerLedger())->applications($this->customerId, true);

        $this->settle();
        $this->settle();

        $this->assertEquals($before, (new CustomerLedger())->applications($this->customerId, true));
        $this->assertSame('230.00', bcadd($this->position()->invoice(-$opening)->open(), '0', 2));
    }

    public function testSettle_CutsWhatWasSpentOnAnOpeningBalanceWhenTheCreditShrinksAndKeepsTheRestOnIt(): void
    {
        $opening = $this->opening('300.00', 60);
        $invoice = $this->invoiceDated('50.00', $this->daysAgo(1), null, null, [$this->payment(lang('Sales.cash'), '120.00')]);
        $payment = (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $invoice)->get()->getRow()->payment_id;

        $this->assertTrue($this->sales->update($invoice, ['comment' => 'less paid', 'payments' => [
            ['payment_id' => $payment, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '100', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $standing = array_map(
            static fn (object $row): string => ($row->invoice_sale_id ?? 'opening' . $row->opening_id) . ':' . bcadd($row->amount, '0', 2),
            (new CustomerLedger())->applications($this->customerId),
        );

        $this->assertSame(['opening' . $opening . ':50.00'], $standing, 'The excess is 50 now, and what was spent of the 70 before stays on the opening balance');
        $this->assertSame('250.00', bcadd($this->position()->invoice(-$opening)->open(), '0', 2));
    }

    private function refundOfTheCredit(string $amount): int
    {
        return (new CreditRefunds(CreditRefunds::CUSTOMER))->refund($this->customerId, $amount, date('Y-m-d'), 'Cash', null, '', '', $this->advanceTaker());
    }

    private function cancelRefund(int $refundId, string $reason): void
    {
        (new CreditRefunds(CreditRefunds::CUSTOMER))->cancel($refundId, $reason, $this->advanceTaker());
    }

    private function freeCredit(): string
    {
        return bcadd($this->position()->credit(), '0', 2);
    }

    public function testSettle_ForbidsDeletingThePaymentWhoseExcessWasPaidBackAndChangesNothing(): void
    {
        $invoice = $this->invoiceOf('230.00');
        $paid    = $this->addPayment($invoice, '300.00');

        $this->refundOfTheCredit('70.00');

        $journals = $this->countJournals();
        $before   = $this->accountingDb()->table('sales_payments')->where('payment_id', $paid)->get()->getRow();

        $this->assertFalse($this->sales->update($invoice, ['comment' => 'removed', 'payments' => [
            ['payment_id' => $paid, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '0', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $failure = $this->failure();

        $this->assertInstanceOf(AccountingException::class, $failure);
        $this->assertSame(AccountingError::CreditRefunded, $failure->error);
        $this->assertSame($journals, $this->countJournals());
        $this->assertEquals($before, $this->accountingDb()->table('sales_payments')->where('payment_id', $paid)->get()->getRow());
        $this->assertSame('0.00', $this->freeCredit());
        $this->assertTies();
    }

    public function testSettle_ForbidsCuttingThePaymentBelowWhatWasPaidBackAndAllowsCuttingItAboveIt(): void
    {
        $invoice = $this->invoiceOf('230.00');
        $paid    = $this->addPayment($invoice, '300.00');

        $this->refundOfTheCredit('50.00');

        $cut = fn (string $amount): bool => $this->sales->update($invoice, ['comment' => 'cut', 'payments' => [
            ['payment_id' => $paid, 'payment_type' => lang('Sales.cash'), 'payment_amount' => $amount, 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]);

        $this->assertFalse($cut('270.00'));
        $this->assertSame(AccountingError::CreditRefunded, $this->failure()->error);
        $this->assertSame('300.0000', bcadd($this->accountingDb()->table('sales_payments')->where('payment_id', $paid)->get()->getRow()->payment_amount, '0', 4));

        $this->assertTrue($cut('290.00'));
        $this->assertSame('10.00', $this->freeCredit());
        $this->assertTies();
    }

    public function testSettle_CutsWhatWasSpentOnAnInvoiceWhenTheCreditShrinksToWhatWasPaidBack(): void
    {
        $source = $this->invoiceOf('100.00');
        $paid   = $this->addPayment($source, '150.00');
        $waits  = $this->invoiceOf('30.00');

        $this->assertSame(['invoice_excess:' . $source . '->' . $waits . ':30.00'], $this->applications());

        $this->refundOfTheCredit('20.00');

        $this->assertTrue($this->sales->update($source, ['comment' => 'cut', 'payments' => [
            ['payment_id' => $paid, 'payment_type' => lang('Sales.cash'), 'payment_amount' => '120.00', 'cash_refund' => 0, 'cash_adjustment' => 0, 'employee_id' => $this->employeeId, 'reference_code' => null],
        ]]));

        $this->assertSame([], $this->applications());
        $this->assertSame('30.00', bcadd($this->position()->invoice($waits)->open(), '0', 2));
        $this->assertSame('0.00', $this->freeCredit());
        $this->assertTies();
    }

    public function testSettle_AllowsDeletingThePaymentOnceTheRefundIsCancelled(): void
    {
        $invoice  = $this->invoiceOf('230.00');
        $paid     = $this->addPayment($invoice, '300.00');
        $refundId = $this->refundOfTheCredit('70.00');

        $this->cancelRefund($refundId, 'paid twice');
        $this->removePayment($invoice, $paid);

        $this->assertSame('230.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertSame('0.00', $this->freeCredit());
        $this->assertTies();
    }
}
