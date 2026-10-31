<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\BalanceTable;
use App\Libraries\Accounting\CustomerBalanceReport;
use App\Libraries\Accounting\CustomerOpening;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\ParityCaseTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The balance of every customer over a period, split the way the reference system splits it: the
 * balance before, what was sold, what came back, the net, what was paid, what credit notes settled and
 * the balance. Each balance is the account of the customer, so the columns tie to the ledger.
 */
class CustomerBalanceReportTest extends CIUnitTestCase
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

    private function report(?string $from, ?string $to = null, bool $hideEmpty = true): BalanceTable
    {
        return (new CustomerBalanceReport())->build(ReportPeriod::of($from, $to ?? date('Y-m-d')), $hideEmpty);
    }

    /**
     * @return array<string, string>
     */
    private function rowOf(BalanceTable $table, int $customerId): array
    {
        foreach ($table->rows as $row) {
            if ($row->partyId === $customerId) {
                return ['before' => $row->before, 'sales' => $row->sales, 'returns' => $row->returns, 'net_sales' => $row->net(), 'payments' => $row->payments, 'settlements' => $row->settlements, 'other' => $row->other, 'balance' => $row->balance];
            }
        }

        $this->fail("The customer {$customerId} is not in the report.");
    }

    public function testBuild_SplitsTheBalanceInTheColumnsOfTheReferenceSystemAndTheyReconcile(): void
    {
        $first  = $this->invoiceOf('1000.00');
        $second = $this->invoiceOf('1541.50');

        $this->addPayment($first, '1000.00');
        $this->addPayment($second, '563.50');
        $this->creditNoteOf($second, '253.00');

        $table = $this->report(date('Y-m-d'));
        $row   = $this->rowOf($table, $this->customerId);

        $this->assertSame(['before' => '0.0000', 'sales' => '2541.5000', 'returns' => '0.0000', 'net_sales' => '2541.5000', 'payments' => '1563.5000', 'settlements' => '-253.0000', 'other' => '0.0000', 'balance' => '725.0000'], $row);
        $this->assertSame($this->balance($this->customerAccount()), bcadd($row['balance'], '0', 2));
        $this->assertTrue($table->rows[0]->adds());
        $this->assertFalse($table->hasOther());
        $this->assertTies();
    }

    public function testBuild_PutsARefundInvoiceUnderReturnsAndACreditNoteUnderSettlements(): void
    {
        $invoice = $this->invoiceOf('500.00');

        $this->refundInvoiceOf($invoice, '120.00');
        $this->creditNoteOf($invoice, '30.00');

        $table = $this->report(date('Y-m-d'));
        $row   = $this->rowOf($table, $this->customerId);

        $this->assertSame(['before' => '0.0000', 'sales' => '500.0000', 'returns' => '-120.0000', 'net_sales' => '380.0000', 'payments' => '0.0000', 'settlements' => '-30.0000', 'other' => '0.0000', 'balance' => '350.0000'], $row);
        $this->assertSame($this->balance($this->customerAccount()), bcadd($row['balance'], '0', 2));
        $this->assertTies();
    }

    public function testBuild_HasTheColumnsTheReferenceSystemHasAsTheFixtureRecordsThem(): void
    {
        $case = $this->referenceCase('T-35', 'customer_reports', 'balance-report-columns', 'exact');

        $this->assertSameAsFixture($case['expected']['columns'], BalanceTable::COLUMNS, 'columns of the balance report');
    }

    public function testBuild_CarriesWhatPrecedesTheStartIntoTheBalanceBefore(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(1));

        $this->addPayment($invoice, '40.00');

        $today = date('Y-m-d');
        $first = $this->rowOf($this->report($today, $today), $this->customerId);
        $later = $this->rowOf($this->report(date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day'))), $this->customerId);
        $whole = $this->rowOf($this->report($this->daysAgo(1), $today), $this->customerId);

        $this->assertSame(['before' => '100.0000', 'sales' => '0.0000', 'payments' => '40.0000', 'balance' => '60.0000'], array_intersect_key($first, array_flip(['before', 'sales', 'payments', 'balance'])));
        $this->assertSame(['before' => '60.0000', 'sales' => '0.0000', 'payments' => '0.0000', 'balance' => '60.0000'], array_intersect_key($later, array_flip(['before', 'sales', 'payments', 'balance'])));
        $this->assertSame(['before' => '0.0000', 'sales' => '100.0000', 'payments' => '40.0000', 'balance' => '60.0000'], array_intersect_key($whole, array_flip(['before', 'sales', 'payments', 'balance'])));
    }

    public function testBuild_TakesTheClosingBalanceOfOnePeriodAsTheBalanceBeforeTheNext(): void
    {
        $invoice = $this->invoiceDated('250.00', $this->daysAgo(5));

        $this->addPayment($invoice, '100.00');
        $this->invoiceDated('30.00', $this->daysAgo(2));

        $first  = $this->rowOf($this->report($this->daysAgo(10), $this->daysAgo(3)), $this->customerId);
        $second = $this->rowOf($this->report($this->daysAgo(2), date('Y-m-d')), $this->customerId);

        $this->assertSame('250.0000', $first['balance']);
        $this->assertSame($first['balance'], $second['before']);
        $this->assertSame('180.0000', $second['balance']);
    }

    public function testBuild_TotalsEveryColumnOverTheCustomersAndTheBalanceIsTheOfTheirAccounts(): void
    {
        $other = $this->anotherCustomer();

        $this->addPayment($this->invoiceOf('300.00'), '120.00');
        $this->addPayment($this->invoiceOf('80.00', $other), '80.00');
        $this->invoiceDated('45.00', $this->daysAgo(3), null, $other);

        $table = $this->report(null);

        $this->assertCount(2, $table->rows);
        $this->assertSame('425.0000', $table->total('sales'));
        $this->assertSame('200.0000', $table->total('payments'));
        $this->assertSame('225.0000', $table->total('balance'));
        $this->assertSame(bcadd($this->balance($this->customerAccount()), $this->balance($this->customerAccount($other)), 4), $table->total('balance'));
        $this->assertSame('0.0000', $table->total('before'));
    }

    public function testBuild_ListsTheCustomersByName(): void
    {
        $zed = $this->anotherCustomer('Zed', 'Zahra');
        $abe = $this->anotherCustomer('Abe', 'Amer');

        $this->invoiceOf('10.00', $zed);
        $this->invoiceOf('10.00', $abe);
        $this->invoiceOf('10.00');

        $names = array_map(static fn ($row): string => $row->name, $this->report(null)->rows);

        $sorted = $names;

        sort($sorted);

        $this->assertSame($sorted, $names);
        $this->assertCount(3, $names);
    }

    public function testBuild_CountsAReversalWithTheDocumentItReversesSoACancelledSaleLeavesNothing(): void
    {
        $cycle = $this->referenceInvoice();
        $sale  = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '200.00')], SALE_TYPE_POS);

        $this->assertTrue($this->sales->delete($sale, false, true, $this->employeeId));

        $row = $this->rowOf($this->report(null, null, false), $this->customerId);

        $this->assertSame(['before' => '0.0000', 'sales' => '0.0000', 'payments' => '0.0000', 'settlements' => '0.0000', 'other' => '0.0000', 'balance' => '0.0000'], array_intersect_key($row, array_flip(['before', 'sales', 'payments', 'settlements', 'other', 'balance'])));
        $this->assertSame([], $this->report(null)->rows);
    }

    public function testBuild_KeepsWhatAManualJournalPutOnTheAccountApartAsOther(): void
    {
        $this->invoiceOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->customerAccount(), debit: '25.00'), new JournalLineDraft($this->accountId('sales_revenue'), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $table = $this->report(null);
        $row   = $this->rowOf($table, $this->customerId);

        $this->assertSame('25.0000', $row['other']);
        $this->assertSame('100.0000', $row['sales']);
        $this->assertSame('125.0000', $row['balance']);
        $this->assertTrue($table->rows[0]->adds());
        $this->assertTrue($table->hasOther());
    }

    public function testBuild_CarriesAnOpeningBalanceInTheBalanceBeforeWhenItPrecedesThePeriodAndUnderOtherWhenItIsInsideIt(): void
    {
        $this->setConfigValue('accounting_customer_opening', '1');

        try {
            (new CustomerOpening())->create($this->customerId, CustomerOpening::OWES_US, '500.00', date('Y-m-d', strtotime('-30 days')), '', null);
        } finally {
            $this->setConfigValue('accounting_customer_opening', '0');
        }

        $this->invoiceOf('100.00');

        $after  = $this->rowOf($this->report(date('Y-m-d', strtotime('-10 days'))), $this->customerId);
        $inside = $this->rowOf($this->report(date('Y-m-d', strtotime('-40 days'))), $this->customerId);

        $this->assertSame(['before' => '500.0000', 'sales' => '100.0000', 'other' => '0.0000', 'balance' => '600.0000'], array_intersect_key($after, array_flip(['before', 'sales', 'other', 'balance'])));
        $this->assertSame(['before' => '0.0000', 'sales' => '100.0000', 'other' => '500.0000', 'balance' => '600.0000'], array_intersect_key($inside, array_flip(['before', 'sales', 'other', 'balance'])));
        $this->assertTies();
    }

    public function testBuild_HidesACustomerWithNothingToShowOnRequestAndShowsItOtherwise(): void
    {
        $invoice = $this->invoiceDated('100.00', $this->daysAgo(10));

        $this->addPayment($invoice, '100.00');

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $after    = date('Y-m-d', strtotime('+2 days'));

        $this->assertSame([], $this->report($tomorrow, $after, true)->rows);

        $shown = $this->report($tomorrow, $after, false);

        $this->assertCount(1, $shown->rows);
        $this->assertSame('0.0000', $shown->rows[0]->balance);
    }

    public function testBuild_ShowsACustomerWhoOwesNothingButMovedMoneyInThePeriod(): void
    {
        $invoice = $this->invoiceOf('100.00');

        $this->addPayment($invoice, '100.00');

        $row = $this->rowOf($this->report(date('Y-m-d')), $this->customerId);

        $this->assertSame('100.0000', $row['sales']);
        $this->assertSame('100.0000', $row['payments']);
        $this->assertSame('0.0000', $row['balance']);
    }

    public function testBuild_AgreesWithThePositionOfTheCustomerOnTheLastDayOfThePeriod(): void
    {
        $old = $this->invoiceDated('300.00', $this->daysAgo(20));

        $this->addPayment($old, '100.00');
        $this->invoiceDated('50.00', $this->daysAgo(4));
        $this->creditNoteOf($old, '30.00');

        foreach ([$this->daysAgo(10), $this->daysAgo(3), date('Y-m-d')] as $day) {
            $row      = $this->rowOf($this->report(null, $day), $this->customerId);
            $position = $this->position(null, $day);

            $this->assertSame(bcadd($position->accountBalance, '0', 4), $row['balance'], $day);
            $this->assertTrue($position->agrees(), $day);
        }
    }

    public function testBuild_RefusesAPeriodThatEndsBeforeItStarts(): void
    {
        $this->expectException(AccountingException::class);

        $this->report(date('Y-m-d'), $this->daysAgo(3));
    }
}
