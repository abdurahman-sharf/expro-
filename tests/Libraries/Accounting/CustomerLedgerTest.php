<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\CustomerPosition;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReturnState;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
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

        $this->assertSame(['0-30' => '40.0000', '31-60' => '40.0000', '61-90' => '70.0000', '91+' => '191.0000'], $aging);
        $this->assertSame(CustomerPosition::AGING_BUCKETS, array_keys($aging));
        $this->assertTies();
    }

    public function testPosition_LeavesAPaidInvoiceOutOfTheAging(): void
    {
        $invoice = $this->invoiceOf('100.00');

        $this->addPayment($invoice, '100.00');

        $this->assertSame(['0-30' => '0.0000', '31-60' => '0.0000', '61-90' => '0.0000', '91+' => '0.0000'], $this->position()->aging(date('Y-m-d')));
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

        $this->assertSameAsFixture($case['expected'], ['ages_from' => $aging['31-60'] === '100.0000' && $aging['0-30'] === '0.0000' ? 'invoice date' : 'something else'], 'aging');
    }

    public function testAging_UsesBucketsOfThirtyDaysWhereTheReportsOfTheReferenceSystemStartWithTodayAndOneToSevenDays(): void
    {
        $case = $this->referenceCase('T-31', 'aging', 'aging-buckets-of-the-reports', 'differs');

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

    public function testCustomersWithBalance_ListsTheBalanceAloneWhereTheBalanceReportOfTheReferenceSystemSplitsItInColumns(): void
    {
        $case = $this->referenceCase('T-35', 'customer_reports', 'balance-report-columns', 'differs');

        $this->invoiceOf('100.00');

        $row = (array) (new CustomerLedger())->customersWithBalance()['rows'][0];

        unset($row['customer_id'], $row['account_id']);

        $this->assertSameAsFixture($case['expected']['columns'], array_keys($row), 'columns of the balance list');
    }
}
