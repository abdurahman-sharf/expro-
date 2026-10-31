<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CreditSource;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\CustomerPosition;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\ReturnState;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\RegisterFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the books say a customer owes, split by invoice: the figures of an invoice, its status, the
 * aging of what is open, and the one rule that matters most, that the open invoices less the
 * credit are the balance of the account of the customer.
 */
class CustomerLedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use ParityCaseTrait;
    use RegisterFixtureTrait;
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

    /**
     * What an invoice of 100 is, by what was paid into it in cash and what a credit note took
     * back. A credit note is paid into its own invoice, so it counts as paid beside the cash.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function statusProvider(): array
    {
        return [
            'unpaid'                                  => ['0', '0', 'unpaid', 'none'],
            'partly paid'                             => ['40', '0', 'partial', 'none'],
            'paid'                                    => ['100', '0', 'paid', 'none'],
            'paid in excess'                          => ['130', '0', 'excess', 'none'],
            'a credit note paid into an unpaid one'   => ['0', '30', 'partial', 'partial'],
            'partly paid and partly returned'         => ['10', '30', 'partial', 'partial'],
            'paid and partly returned'                => ['100', '30', 'paid', 'partial'],
            'paid and returned in full'               => ['100', '100', 'paid', 'full'],
            'a credit note that pays the rest'        => ['70', '30', 'paid', 'partial'],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testPosition_NamesTheStateOfPaymentAndTheStateOfReturnOfAnInvoice(string $paid, string $returned, string $payment, string $return): void
    {
        $invoice = $this->invoiceOf('100.00');

        if ($paid !== '0') {
            $this->addPayment($invoice, $paid);
        }

        if ($returned !== '0') {
            $this->creditNoteOf($invoice, $returned);
        }

        $figures = $this->position()->invoice($invoice);

        $this->assertSame(PaymentState::from($payment), $figures->paymentState());
        $this->assertSame(ReturnState::from($return), $figures->returnState());
        $this->assertTies();
    }

    public function testPosition_CarriesTheFiguresOfAnInvoiceThatWasPaidInPartsAndPartlyReturned(): void
    {
        $invoice = $this->invoiceOf('1000.00');

        $this->addPayment($invoice, '300.00');
        $this->addPayment($invoice, '200.00', lang('Sales.check'));
        $this->creditNoteOf($invoice, '100.00');

        $figures = $this->position()->invoice($invoice);

        $this->assertSame('1000.00', bcadd($figures->total, '0', 2));
        $this->assertSame('500.00', bcadd($figures->paid, '0', 2));
        $this->assertSame('100.00', bcadd($figures->applied, '0', 2));
        $this->assertSame('100.00', bcadd($figures->returned, '0', 2));
        $this->assertSame('400.00', bcadd($figures->open(), '0', 2));
        $this->assertSame('400.00', $this->balance($this->customerAccount()));
        $this->assertTies();
    }

    public function testPosition_FollowsTheWholeReferenceCycleToAZeroBalance(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes']);

        $this->assertSame('816.50', bcadd($this->position()->open(), '0', 2));
        $this->assertTies();

        $this->save([$this->line($cycle['perfume'], 0, '-1', '220', '120.00')], [$this->tax($cycle['perfume'], 0, '-33.00')], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->assertSame('563.50', bcadd($this->position()->open(), '0', 2));
        $this->assertTies();

        $this->addPayment($invoice, '300.00');
        $this->addPayment($invoice, '163.50', lang('Sales.check'));
        $this->addPayment($invoice, '100.00', lang('Sales.bank_transfer'));

        $position = $this->position();

        $this->assertSame('0.00', bcadd($position->open(), '0', 2));
        $this->assertSame('0.00', bcadd($position->credit(), '0', 2));
        $this->assertSame('0.00', bcadd($position->accountBalance, '0', 2));
        $this->assertSame(PaymentState::Paid, $position->invoice($invoice)->paymentState());
        $this->assertSame(ReturnState::Partial, $position->invoice($invoice)->returnState());
        $this->assertTies();
    }

    public function testPosition_AgesWhatIsOpenByTheAgeOfTheInvoice(): void
    {
        $days = ['10' => '10.00', '40' => '40.00', '70' => '70.00', '100' => '100.00', '30' => '30.00', '91' => '91.00'];

        foreach ($days as $age => $total) {
            $invoice = $this->invoiceOf($total);

            $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime("-{$age} days"))]);
        }

        $aging = $this->position()->aging(date('Y-m-d'));

        $this->assertSame(['today' => '0.0000', '1-30' => '40.0000', '31-60' => '40.0000', '61-90' => '70.0000', '91-120' => '191.0000', '120+' => '0.0000'], $aging);
        $this->assertSame(CustomerPosition::AGING_BUCKETS, array_keys($aging));
        $this->assertTies();
    }

    public function testPosition_LeavesAPaidInvoiceOutOfTheAging(): void
    {
        $invoice = $this->invoiceOf('100.00');

        $this->addPayment($invoice, '100.00');

        $this->assertSame(['today' => '0.0000', '1-30' => '0.0000', '31-60' => '0.0000', '61-90' => '0.0000', '91-120' => '0.0000', '120+' => '0.0000'], $this->position()->aging(date('Y-m-d')));
    }

    public function testPosition_ShowsApartWhatAManualJournalPutOnTheAccountOfTheCustomer(): void
    {
        $this->invoiceOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '25.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $position = $this->position();

        $this->assertFalse($position->agrees());
        $this->assertSame('25.00', bcadd($position->unclassified(), '0', 2));
        $this->assertSame('125.00', bcadd($position->accountBalance, '0', 2));
        $this->assertSame('100.00', bcadd($position->open(), '0', 2));
    }

    public function testPosition_CountsNeitherACancelledSaleNorAPaymentThatWasRemoved(): void
    {
        $pos     = $this->invoiceOf('60.00', null, [], SALE_TYPE_POS);
        $invoice = $this->invoiceOf('100.00');
        $payment = $this->addPayment($invoice, '40.00');

        $this->assertTrue($this->sales->delete($pos, false, true, $this->employeeId));
        $this->removePayment($invoice, $payment);

        $position = $this->position();

        $this->assertNull($position->invoice($pos));
        $this->assertSame('0.00', bcadd($position->invoice($invoice)->paid, '0', 2));
        $this->assertSame('100.00', bcadd($position->open(), '0', 2));
        $this->assertTies();
    }

    public function testPosition_CountsOnlyTheSalesOfThatCustomer(): void
    {
        $other = $this->anotherCustomer();

        $this->invoiceOf('100.00');
        $this->invoiceOf('250.00', $other);

        $this->assertSame('100.00', bcadd($this->position()->open(), '0', 2));
        $this->assertSame('250.00', bcadd($this->position($other)->open(), '0', 2));
        $this->assertTies();
        $this->assertTies($other);
    }

    public function testPosition_GivesAnEmptyPositionToACustomerWithoutAnAccountOrASale(): void
    {
        $without = $this->insertCustomer('Nora', 'Fahd');
        $empty   = $this->position($without);

        $this->assertNull($empty->accountId);
        $this->assertSame([], $empty->invoices);
        $this->assertSame([], $empty->credits);
        $this->assertTrue($empty->agrees());
        $this->assertSame('0.0000', $empty->open());
    }

    public function testPosition_PutsTheInvoicesInTheOrderTheyWereIssued(): void
    {
        $first  = $this->invoiceOf('10.00');
        $second = $this->invoiceOf('20.00');
        $third  = $this->invoiceOf('30.00');

        $this->assertSame([$first, $second, $third], array_map(static fn ($invoice): int => $invoice->saleId, $this->position()->invoices));
    }

    // ---------------------------------------------------------------------------------------
    // What the reference system recorded in the second write batch: fixtures T-31 and T-35
    // ---------------------------------------------------------------------------------------

    public function testPosition_AgesAnOpenInvoiceFromItsDateAsTheReferenceSystemDoes(): void
    {
        $case    = $this->referenceCase('T-31', 'aging', 'aging-runs-from-the-invoice-date', 'exact');
        $invoice = $this->invoiceOf('100.00');

        $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['sale_time' => date('Y-m-d 10:00:00', strtotime('-40 days'))]);

        $aging = $this->position()->aging(date('Y-m-d'));

        $this->assertSameAsFixture($case['expected'], ['ages_from' => $aging['31-60'] === '100.0000' && $aging['today'] === '0.0000' ? 'invoice date' : 'something else'], 'aging');
    }

    public function testAging_UsesTheSixBucketsOfTheReportsOfTheReferenceSystem(): void
    {
        $case = $this->referenceCase('T-31', 'aging', 'aging-buckets-of-the-reports', 'exact');

        $this->assertSameAsFixture($case['expected']['aging_buckets'], CustomerPosition::AGING_BUCKETS, 'aging buckets');
    }

    public function testPosition_TiesTheFiguresOfACustomerAfterInvoicesCreditNotesAndCreditAsTheReferenceSystemDoes(): void
    {
        $case = $this->referenceCase('T-35', 'customer_reports', 'customer-figures-tie', 'exact');

        $first = $this->taxedItem('60.00', true, '15');
        $b     = $this->ringUp([['item' => $this->taxedItem('0', false, '15'), 'quantity' => '1', 'price' => '200.00'], ['item' => $this->taxedItem('0', false, '15'), 'quantity' => '1', 'price' => '155.55']], ['type' => PERCENT, 'value' => '3.3']);
        $a     = $this->ringUp([['item' => $first, 'quantity' => '3', 'price' => '100.00', 'discount' => '10']], ['type' => FIXED, 'value' => '20']);
        $c     = $this->ringUp([['item' => $first, 'quantity' => '2', 'price' => '100.00']], null, null, false);

        $this->assertGreaterThan(0, $b['sale']);
        $this->assertGreaterThan(0, $a['sale']);
        $this->assertGreaterThan(0, $c['sale']);
        $this->assertGreaterThan(0, $this->ringUp([['item' => $first, 'quantity' => '-1', 'price' => '100.00', 'discount' => '10']], null, $a['sale'])['sale']);

        $position = $this->position();
        $actual   = [
            'balance_equals_the_account'              => bcadd($position->accountBalance, '0', 2) === $this->balance((int) $position->accountId),
            'aging_adds_up_to_what_is_open'           => $position->open() === array_reduce($position->aging(date('Y-m-d')), static fn (string $sum, string $amount): string => bcadd($sum, $amount, 4), '0.0000'),
            'open_invoices_less_credit_equal_the_balance' => $position->agrees(),
        ];

        $this->assertSameAsFixture($case['expected'], $actual, 'figures of the customer');
        $this->assertTies();
    }

    public function testCustomersWithBalance_ListsEachCustomerWithTheAccountAndTheBalanceOfIt(): void
    {
        $this->invoiceOf('100.00');

        $row = (array) (new CustomerLedger())->customersWithBalance()['rows'][0];

        $this->assertSame(['customer_id', 'customer_name', 'account_id', 'balance'], array_keys($row));
        $this->assertSame('100.00', bcadd($row['balance'], '0', 2));
    }

    // ---------------------------------------------------------------------------------------
    // The six buckets, the due date and the position of a customer on a day (WP13)
    // ---------------------------------------------------------------------------------------

    public function testAging_SpreadsWhatIsOpenOverTheSixBucketsByTheEdgesOfTheirRanges(): void
    {
        $amounts = ['0' => '1.00', '1' => '2.00', '30' => '4.00', '31' => '8.00', '60' => '16.00', '61' => '32.00', '90' => '64.00', '91' => '128.00', '120' => '256.00', '121' => '512.00'];

        foreach ($amounts as $age => $total) {
            $this->invoiceDated($total, $this->daysAgo((int) $age));
        }

        $aging = $this->position()->aging(date('Y-m-d'));

        $this->assertSame(['today' => '1.0000', '1-30' => '6.0000', '31-60' => '24.0000', '61-90' => '96.0000', '91-120' => '384.0000', '120+' => '512.0000'], $aging);
        $this->assertSame($this->position()->open(), AgingBuckets::total(array_values($aging)));
        $this->assertTies();
    }

    public function testAging_AgesFromTheDayOfTheInvoiceAndIgnoresItsDueDate(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(5), 30);

        $position = $this->position();

        $this->assertSame('100.0000', $position->aging(date('Y-m-d'))['1-30']);
        $this->assertSame('0.0000', $position->aging(date('Y-m-d'))['today']);
        $this->assertSame('0.00', bcadd($position->overdue(date('Y-m-d')), '0', 2));
    }

    public function testAging_AgeIsTheDayOfTheReportLessTheDayOfTheInvoice(): void
    {
        $invoice = $this->invoiceOf('115.00');

        $this->addPayment($invoice, '90.00');

        $position = $this->position();

        $this->assertSame('25.0000', $position->aging(date('Y-m-d'))['today']);
        $this->assertSame('25.0000', $position->aging(date('Y-m-d', strtotime('+1 day')))['1-30']);
        $this->assertSame('0.0000', $position->aging(date('Y-m-d', strtotime('+1 day')))['today']);
    }

    public function testAging_LeavesOutAnInvoiceDatedAfterTheDayOfTheReport(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(2));
        $this->invoiceDated('50.00', $this->daysAgo(0));

        $position = $this->position();

        $this->assertSame('100.0000', $position->aging($this->daysAgo(1))['1-30']);
        $this->assertSame('0.0000', AgingBuckets::total(array_values($position->aging($this->daysAgo(3)))));

        $later = $position->aging(date('Y-m-d', strtotime('+60 days')));

        $this->assertSame('50.0000', $later['31-60']);
        $this->assertSame('100.0000', $later['61-90']);
    }

    public function testPosition_OnADayOfThePastCountsOnlyWhatWasDatedByThenAndTiesToTheBalanceOfThatDay(): void
    {
        $first  = $this->invoiceDated('100.00', $this->daysAgo(2));
        $second = $this->invoiceDated('50.00', $this->daysAgo(2));

        $this->addPayment($first, '100.00');

        $yesterday = $this->position(null, $this->daysAgo(1));
        $today     = $this->position();

        $this->assertSame('150.00', bcadd($yesterday->open(), '0', 2));
        $this->assertSame('150.00', bcadd($yesterday->accountBalance, '0', 2));
        $this->assertTrue($yesterday->agrees());
        $this->assertSame('150.0000', $yesterday->aging($this->daysAgo(1))['1-30']);
        $this->assertSame('0.0000', $yesterday->aging($this->daysAgo(1))['120+']);

        $this->assertSame('50.00', bcadd($today->open(), '0', 2));
        $this->assertSame('50.0000', $today->aging(date('Y-m-d'))['1-30']);
        $this->assertSame('0.0000', $today->aging(date('Y-m-d'))['120+']);
        $this->assertNotNull($today->invoice($second));
        $this->assertSame(date('Y-m-d', strtotime('-1 day')), $yesterday->asOf);
        $this->assertTies();
    }

    public function testPosition_OnADayBeforeAnInvoiceLeavesItOutWithItsPaymentAndCredit(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(2));

        $this->addPayment($invoice, '30.00');
        $this->creditNoteOf($invoice, '20.00');

        $before = $this->position(null, $this->daysAgo(3));

        $this->assertSame([], $before->invoices);
        $this->assertSame([], $before->credits);
        $this->assertSame('0.00', bcadd($before->accountBalance, '0', 2));
        $this->assertTrue($before->agrees());
    }

    public function testPosition_OnADayOfThePastStillCountsASaleThatWasCancelledAfterThatDay(): void
    {
        $cycle = $this->referenceInvoice();
        $sale  = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '200.00')], SALE_TYPE_POS);
        $past  = $this->daysAgo(3);
        $db    = $this->accountingDb();

        $db->table('sales')->where('sale_id', $sale)->update(['sale_time' => $past . ' 10:00:00']);
        $db->table('sales_payments')->where('sale_id', $sale)->update(['payment_time' => $past . ' 10:00:00']);
        $paymentIds = array_map('intval', array_column($db->table('sales_payments')->select('payment_id')->where('sale_id', $sale)->get()->getResultArray(), 'payment_id'));

        $db->table('accounting_journals')->where(['source_type' => 'sale', 'source_id' => $sale])->update(['journal_date' => $past]);
        $db->table('accounting_journals')->where('source_type', 'sale_payment')->whereIn('source_id', $paymentIds)->update(['journal_date' => $past]);

        $this->closeThrough($this->daysAgo(2));

        $this->assertTrue($this->sales->delete($sale, false, true, $this->employeeId));

        $thatDay = $this->position(null, $this->daysAgo(1));
        $now     = $this->position();

        $this->assertNotNull($thatDay->invoice($sale));
        $this->assertSame('616.50', bcadd($thatDay->open(), '0', 2));
        $this->assertSame('616.50', bcadd($thatDay->accountBalance, '0', 2));
        $this->assertTrue($thatDay->agrees());
        $this->assertNull($now->invoice($sale));
        $this->assertSame('0.00', bcadd($now->accountBalance, '0', 2));
    }

    public function testPosition_OnADayAfterASaleWasCancelledLeavesItOutAndBeforeItKeepsIt(): void
    {
        $service = $this->item('0', false);
        $sale    = $this->save([$this->line($service, 0, '1', '100.00')], [], [$this->payment(lang('Sales.cash'), '40.00')], SALE_TYPE_POS);
        $past    = $this->daysAgo(3);
        $db      = $this->accountingDb();

        $db->table('sales')->where('sale_id', $sale)->update(['sale_time' => $past . ' 10:00:00']);
        $db->table('sales_payments')->where('sale_id', $sale)->update(['payment_time' => $past . ' 10:00:00']);

        $paymentIds = array_map('intval', array_column($db->table('sales_payments')->select('payment_id')->where('sale_id', $sale)->get()->getResultArray(), 'payment_id'));

        $db->table('accounting_journals')->where(['source_type' => SalePostingBuilder::SOURCE_SALE, 'source_id' => $sale])->update(['journal_date' => $past]);
        $db->table('accounting_journals')->where('source_type', SalePostingBuilder::SOURCE_PAYMENT)->whereIn('source_id', $paymentIds)->update(['journal_date' => $past]);

        $this->assertTrue($this->sales->delete($sale, false, true, $this->employeeId));

        $reversals = $db->table('accounting_journals')->where('reverses_journal_id IS NOT NULL')->get()->getResult();

        $this->assertNotSame([], $reversals);

        foreach ($reversals as $reversal) {
            $this->assertSame($past, $reversal->journal_date, 'A reversal made while the period is open takes the day of what it reverses');
        }

        $before = $this->position(null, $this->daysAgo(4));
        $after  = $this->position(null, $this->daysAgo(1));

        $this->assertNull($after->invoice($sale));
        $this->assertSame('0.00', bcadd($after->accountBalance, '0', 2));
        $this->assertNull($before->invoice($sale), 'The sale is dated after that day');
    }

    public function testPosition_LeavesOutADocumentWhoseRecordIsDatedAfterTheDayEvenIfItsJournalIsOlder(): void
    {
        $sale = $this->invoiceDated('100.00', $this->daysAgo(3));

        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['invoice_date' => $this->daysAgo(1)]);

        $this->assertNull($this->position(null, $this->daysAgo(2))->invoice($sale));
        $this->assertNotNull($this->position(null, $this->daysAgo(1))->invoice($sale));
    }

    public function testPosition_ReadsAnInvoiceWithNoTypedDayFromTheDayItWasSavedAndDueThatSameDay(): void
    {
        $invoice = $this->invoiceOf('100.00');

        $this->accountingDb()->table('sales')->where('sale_id', $invoice)->update(['sale_time' => $this->daysAgo(40) . ' 10:00:00']);

        $position = $this->position();
        $figures  = $position->invoice($invoice);

        $this->assertNull($this->saleRow($invoice)->invoice_date);
        $this->assertNull($this->saleRow($invoice)->due_date);
        $this->assertSame($this->daysAgo(40), $figures->date);
        $this->assertSame($this->daysAgo(40), $figures->dueDate);
        $this->assertNull($figures->termsDays);
        $this->assertSame('100.0000', $position->aging(date('Y-m-d'))['31-60']);
        $this->assertTrue($figures->isOverdue(date('Y-m-d')));
        $this->assertSame(40, $figures->daysOverdue(date('Y-m-d')));
    }

    public function testPosition_PutsTheInvoicesInTheOrderOfTheDaysTheyAreDatedThenTheOrderTheyWereSaved(): void
    {
        $recent = $this->invoiceDated('10.00', $this->daysAgo(1));
        $old    = $this->invoiceDated('20.00', $this->daysAgo(9));
        $today  = $this->invoiceOf('30.00');

        $this->assertSame([$old, $recent, $today], array_map(static fn ($invoice): int => $invoice->saleId, $this->position()->invoices));
    }

    public function testIsOverdue_IsTrueOnlyForWhatIsOpenAndFellDueBeforeToday(): void
    {
        $dueYesterday = $this->invoiceDated('10.00', $this->daysAgo(3), 2);
        $dueToday     = $this->invoiceDated('20.00', $this->daysAgo(3), 3);
        $dueLater     = $this->invoiceDated('30.00', $this->daysAgo(3), 33);
        $noTerms      = $this->invoiceDated('40.00', $this->daysAgo(1));
        $dateToday    = $this->invoiceOf('50.00');
        $paid         = $this->invoiceDated('60.00', $this->daysAgo(9), 1);

        $this->addPayment($paid, '60.00');

        $position = $this->position();
        $today    = date('Y-m-d');

        $this->assertTrue($position->invoice($dueYesterday)->isOverdue($today));
        $this->assertFalse($position->invoice($dueToday)->isOverdue($today));
        $this->assertFalse($position->invoice($dueLater)->isOverdue($today));
        $this->assertTrue($position->invoice($noTerms)->isOverdue($today));
        $this->assertFalse($position->invoice($dateToday)->isOverdue($today));
        $this->assertFalse($position->invoice($paid)->isOverdue($today));
        $this->assertSame('50.00', bcadd($position->overdue($today), '0', 2));
        $this->assertSame(1, $position->invoice($dueYesterday)->daysOverdue($today));
        $this->assertSame(0, $position->invoice($dueToday)->daysOverdue($today));
        $this->assertSame(0, $position->invoice($dueLater)->daysOverdue($today));
        $this->assertSame(0, $position->invoice($paid)->daysOverdue($today));
        $this->assertTies();
    }

    public function testIsOverdue_FollowsTheRuleOfTheReferenceSystemForDueDatesOfThreeKinds(): void
    {
        $case    = $this->referenceCase('T-31', 'overdue', 'overdue-is-open-and-due-before-today', 'exact');
        $fixture = DateTimeImmutable::createFromFormat('!d/m/Y', $case['inputs']['today']);
        $overdue = [];
        $not     = [];

        foreach ($case['inputs']['due_dates'] as $dueDate) {
            $offset  = (int) $fixture->diff(DateTimeImmutable::createFromFormat('!d/m/Y', $dueDate))->format('%r%a');
            $invoice = $this->invoiceDated('10.00', $this->daysAgo(5), 5 + $offset);

            if ($this->position()->invoice($invoice)->isOverdue(date('Y-m-d'))) {
                $overdue[] = $dueDate;
            } else {
                $not[] = $dueDate;
            }
        }

        $this->assertSameAsFixture($case['expected'], ['overdue' => $overdue, 'not_overdue' => $not], 'overdue invoices');
    }

    public function testPosition_CountsAnApplicationOnlyFromTheDayItWasMadeUntilTheDayItWasReleased(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(6));
        $note    = $this->creditNoteOf($invoice, '40.00');
        $db      = $this->accountingDb();

        $db->table('sales')->where('sale_id', $note)->update(['sale_time' => $this->daysAgo(5) . ' 10:00:00']);
        $db->table('accounting_journals')->where(['source_type' => SalePostingBuilder::SOURCE_SALE, 'source_id' => $note])->update(['journal_date' => $this->daysAgo(5)]);

        $this->assertSame(1, $db->table('customer_credit_applications')->where('customer_id', $this->customerId)->countAllResults());

        $db->table('customer_credit_applications')->where('customer_id', $this->customerId)->update(['created_at' => $this->daysAgo(3) . ' 09:00:00', 'released_at' => $this->daysAgo(1) . ' 09:00:00']);

        $beforeIt = $this->position(null, $this->daysAgo(4));
        $whileIt  = $this->position(null, $this->daysAgo(2));
        $afterIt  = $this->position(null, date('Y-m-d'));

        $this->assertSame(['100.00', '40.00'], [bcadd($beforeIt->open(), '0', 2), bcadd($beforeIt->credit(), '0', 2)]);
        $this->assertSame(['60.00', '0.00'], [bcadd($whileIt->open(), '0', 2), bcadd($whileIt->credit(), '0', 2)]);
        $this->assertSame(['100.00', '40.00'], [bcadd($afterIt->open(), '0', 2), bcadd($afterIt->credit(), '0', 2)]);

        foreach ([$beforeIt, $whileIt, $afterIt] as $position) {
            $this->assertTrue($position->agrees());
            $this->assertSame('60.00', bcadd($position->accountBalance, '0', 2));
        }
    }

    public function testPosition_TiesCustomerAndAccountForInvoicesOfAnyDayWithTermsPaymentsAndCreditNotes(): void
    {
        $old    = $this->invoiceDated('300.00', $this->daysAgo(45), 15);
        $recent = $this->invoiceDated('120.00', $this->daysAgo(3), 30);
        $today  = $this->invoiceOf('80.00');

        $this->addPayment($old, '100.00');
        $this->creditNoteOf($recent, '20.00');
        $this->addPayment($today, '80.00');

        $position = $this->position();

        $this->assertSame('300.00', bcadd($position->open(), '0', 2));
        $this->assertSame(['today' => '0.0000', '1-30' => '100.0000', '31-60' => '200.0000', '61-90' => '0.0000', '91-120' => '0.0000', '120+' => '0.0000'], $position->aging(date('Y-m-d')));
        $this->assertSame('200.00', bcadd($position->overdue(date('Y-m-d')), '0', 2));
        $this->assertTies();
    }

    private function openingOf(string $amount, int $daysAgo, string $side = CustomerOpening::OWES_US): int
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        try {
            return (new CustomerOpening())->create($this->customerId, $side, $amount, $this->daysAgo($daysAgo), '', null);
        } finally {
            $this->setConfigValue('accounting_customer_opening', '0');
        }
    }

    public function testPosition_TiesAnOpeningBalanceWithInvoicesPaymentsAndACreditNoteToTheBalanceTheAgingAndTheAccount(): void
    {
        $opening = $this->openingOf('500.00', 60);
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(10));

        $this->addPayment($invoice, '30.00');
        $this->creditNoteOf($invoice, '20.00');

        $position = $this->position();

        $this->assertSame('550.00', bcadd($position->open(), '0', 2));
        $this->assertSame('550.00', bcadd($position->accountBalance, '0', 2));
        $this->assertSame('550.00', $this->balance($this->customerAccount()));
        $this->assertSame('500.00', bcadd($position->invoice(-$opening)->open(), '0', 2));
        $this->assertSame('50.00', bcadd($position->invoice($invoice)->open(), '0', 2));
        $this->assertSame(['0.0000', '50.0000', '500.0000', '0.0000', '0.0000', '0.0000'], array_values($position->aging(date('Y-m-d'))));
        $this->assertTies();
    }

    public function testPosition_PutsTheOpeningBalanceFirstWhenItIsDatedTheSameDayAsAnInvoice(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(5));
        $opening = $this->openingOf('40.00', 5);

        $this->assertSame([-$opening, $invoice], array_map(static fn ($figures): int => $figures->saleId, $this->position()->invoices));
    }

    public function testPosition_CountsTheOpeningBalanceOnlyFromItsDayOnADayOfThePastAndTiesEveryDay(): void
    {
        $this->openingOf('500.00', 20);
        $this->invoiceDated('100.00', $this->daysAgo(10));

        foreach ([[21, '0.0000'], [20, '500.0000'], [11, '500.0000'], [10, '600.0000']] as [$ago, $owed]) {
            $position = $this->position(null, $this->daysAgo($ago));

            $this->assertSame($owed, $position->open(), "{$ago} days ago");
            $this->assertTrue($position->agrees(), "{$ago} days ago");
        }
    }

    public function testPosition_CountsAnOpeningBalanceCancelledAfterAClosedPeriodAsItWasOnADayBeforeItWasCancelled(): void
    {
        $opening = $this->openingOf('500.00', 30);
        $this->closeThrough($this->daysAgo(25));

        $this->setConfigValue('accounting_customer_opening', '1');
        (new CustomerOpening())->cancel($opening, 'Entered twice', null);
        $this->setConfigValue('accounting_customer_opening', '0');

        $before = $this->position(null, $this->daysAgo(10));
        $now    = $this->position();

        $this->assertSame('500.0000', $before->open(), 'On a day before the cancellation the books still carried it');
        $this->assertTrue($before->agrees());
        $this->assertSame('0.0000', $now->open());
        $this->assertSame('0.0000', $now->accountBalance);
        $this->assertTies();
    }

    public function testAgedLedger_AgreesWithThePositionOfACustomerWhoHasAnOpeningBalanceAndCreditSpentOnIt(): void
    {
        $this->openingOf('500.00', 60);
        $this->invoiceDated('100.00', $this->daysAgo(2), null, null, [$this->payment(lang('Sales.cash'), '300.00')]);

        $position = $this->position();
        $rows     = (new CustomerLedger())->agedLedger(date('Y-m-d'), 30, $this->customerId);

        $this->assertCount(1, $rows);
        $this->assertSame('300.0000', $position->open());
        $this->assertSame(bcsub($position->open(), $position->credit(), 4), $rows[0]->balance());
        $this->assertSame($position->accountBalance, $rows[0]->balance());
        $this->assertSame('0.0000', $rows[0]->credit);
    }

    public function testPosition_ReadsACreditAnOpeningBalanceHoldsAsCreditSpentOnTheNextInvoice(): void
    {
        $opening = $this->openingOf('300.00', 30, CustomerOpening::WE_OWE);

        $this->assertSame([CreditSource::OPENING, $opening, '300.0000'], [$this->position()->credits[0]->type, $this->position()->credits[0]->sourceId, $this->position()->credits[0]->amount]);

        $invoice = $this->invoiceDated('100.00', $this->daysAgo(1));

        $this->assertSame('200.0000', $this->position()->credit());
        $this->assertSame('0.00', bcadd($this->position()->invoice($invoice)->open(), '0', 2));
        $this->assertTies();
    }
}
