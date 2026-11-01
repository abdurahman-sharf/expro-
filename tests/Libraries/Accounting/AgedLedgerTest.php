<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\AgedLedger;
use App\Libraries\Accounting\AgedLedgerRow;
use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\CustomerLedger;
use App\Libraries\Accounting\EntityAccountService;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\SalePostingBuilder;
use App\Libraries\Accounting\SalesDebitNotes;
use App\Libraries\Accounting\SupplierLedger;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The ledger of customers and of suppliers aged the way the reference system ages it by ledger: the
 * credit that came in goes to the oldest debit first, what is left of each debit is aged by its own
 * day, and what the buckets hold, less the credit left over, is the balance of the account on the day.
 */
class AgedLedgerTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use ChequeFixtureTrait;
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
        $this->accountingDb()->table('accounting_audit_log')->where('entity_type', 'sales_debit_note')->delete();
        $this->accountingDb()->table('app_config')->where('key', 'accounting_debit_note')->delete();
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @return list<AgedLedgerRow>
     */
    private function customers(string $asOf, int $period = AgingBuckets::DEFAULT_PERIOD, ?int $customerId = null): array
    {
        return (new AgedLedger())->build(EntityAccountService::CUSTOMER, $asOf, $period, $customerId);
    }

    /**
     * @return list<AgedLedgerRow>
     */
    private function suppliers(string $asOf, int $period = AgingBuckets::DEFAULT_PERIOD, ?int $supplierId = null): array
    {
        return (new AgedLedger())->build(EntityAccountService::SUPPLIER, $asOf, $period, $supplierId);
    }

    private function rowOf(array $rows, int $partyId): AgedLedgerRow
    {
        foreach ($rows as $row) {
            if ($row->partyId === $partyId) {
                return $row;
            }
        }

        $this->fail("The party {$partyId} is not in the aged ledger.");
    }

    private function dateSalePayments(int $saleId, string $day): void
    {
        $db         = $this->accountingDb();
        $paymentIds = array_map('intval', array_column($db->table('sales_payments')->select('payment_id')->where('sale_id', $saleId)->get()->getResultArray(), 'payment_id'));

        $db->table('accounting_journals')->where('source_type', SalePostingBuilder::SOURCE_PAYMENT)->whereIn('source_id', $paymentIds)->update(['journal_date' => $day]);
    }

    private function datePurchasePayments(int $receivingId, string $day): void
    {
        $db         = $this->accountingDb();
        $paymentIds = array_map('intval', array_column($db->table('receivings_payments')->select('payment_id')->where('receiving_id', $receivingId)->get()->getResultArray(), 'payment_id'));

        $db->table('accounting_journals')->where('source_type', ReceivingPostingBuilder::SOURCE_PAYMENT)->whereIn('source_id', $paymentIds)->update(['journal_date' => $day]);
    }

    /**
     * Invoices of 100 five days ago, 200 three days ago, 120 yesterday and 50 today, and the 120 paid
     * yesterday: the payment goes to the oldest invoice first, so 100 is settled and 20 of the 200.
     */
    private function customerCycle(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(5));
        $this->invoiceDated('200.00', $this->daysAgo(3));
        $this->invoiceDated('50.00', $this->daysAgo(0));

        $paid = $this->invoiceDated('120.00', $this->daysAgo(1));

        $this->addPayment($paid, '120.00');
        $this->dateSalePayments($paid, $this->daysAgo(1));
    }

    private function supplierCycle(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(5));
        $this->purchaseDated('200.00', $this->daysAgo(3));
        $this->purchaseDated('50.00', $this->daysAgo(0));

        $paid = $this->purchaseDated('120.00', $this->daysAgo(1));

        $this->payPurchase($paid, '120.00');
        $this->datePurchasePayments($paid, $this->daysAgo(1));
    }

    public function testBuild_AgesASalesDebitNoteFromItsOwnDayAndTheBucketsTieToTheAccount(): void
    {
        $this->setConfigValue('accounting_debit_note', '1');

        $clerk = $this->employeeWithGrants([AccountingGuard::DEBIT_NOTES_MANAGE]);

        (new SalesDebitNotes())->create(['customer_id' => $this->customerId, 'date' => $this->daysAgo(45), 'amount' => '100', 'tax_percent' => '15'], $clerk);
        $this->invoiceDated('50.00', $this->daysAgo(0));

        $row = $this->rowOf($this->customers(date('Y-m-d')), $this->customerId);

        $this->assertSame('115.00', bcadd($row->buckets[2], '0', 2), 'the 31 to 60 bucket');
        $this->assertSame('50.00', bcadd($row->buckets[0], '0', 2), 'the day itself');
        $this->assertSame('165.00', bcadd($row->total(), '0', 2));
        $this->assertSame($this->balance($this->customerAccount()), bcadd($row->total(), '0', 2));
        $this->assertSame([], array_filter($this->customers($this->daysAgo(50)), fn (AgedLedgerRow $other): bool => $other->partyId === $this->customerId), 'a day before the note nobody owed it');
    }

    public function testBuild_AppliesTheCreditToTheOldestDebitFirstAndAgesWhatIsLeftByItsOwnDay(): void
    {
        $this->customerCycle();

        $row = $this->rowOf($this->customers(date('Y-m-d'), 1), $this->customerId);

        $this->assertSame(['50.0000', '120.0000', '0.0000', '180.0000', '0.0000', '0.0000'], $row->buckets);
        $this->assertSame('350.0000', $row->total());
        $this->assertSame('0.0000', $row->credit);
        $this->assertSame('Omar Khaled', $row->name);
        $this->assertSame($this->customerAccount(), $row->accountId);
    }

    public function testBuild_AgesByTheLengthOfTheBucketsTheReportAsksFor(): void
    {
        $this->customerCycle();

        $thirty = $this->rowOf($this->customers(date('Y-m-d')), $this->customerId);
        $ten    = $this->rowOf($this->customers(date('Y-m-d'), 10), $this->customerId);
        $two    = $this->rowOf($this->customers(date('Y-m-d'), 2), $this->customerId);

        $this->assertSame(['50.0000', '300.0000', '0.0000', '0.0000', '0.0000', '0.0000'], $thirty->buckets);
        $this->assertSame($thirty->buckets, $ten->buckets);
        $this->assertSame(['50.0000', '120.0000', '180.0000', '0.0000', '0.0000', '0.0000'], $two->buckets);
    }

    public function testBuild_KeepsTheLengthOfTheBucketsBetweenOneDayAndTheLimit(): void
    {
        $this->invoiceDated('100.00', $this->daysAgo(400));

        $none  = $this->rowOf($this->customers(date('Y-m-d'), 0), $this->customerId);
        $huge  = $this->rowOf($this->customers(date('Y-m-d'), 99999), $this->customerId);
        $limit = $this->rowOf($this->customers(date('Y-m-d'), AgingBuckets::MAX_PERIOD), $this->customerId);
        $one   = $this->rowOf($this->customers(date('Y-m-d'), 1), $this->customerId);

        $this->assertSame($one->buckets, $none->buckets);
        $this->assertSame('100.0000', $none->buckets[5]);
        $this->assertSame($limit->buckets, $huge->buckets);
        $this->assertSame('100.0000', $huge->buckets[2]);
        $this->assertSame('0.0000', $huge->buckets[1]);
    }

    public function testBuild_CountsOnlyWhatIsDatedByTheDayAndTheBucketsLessTheCreditAreTheBalanceOfThatDay(): void
    {
        $this->customerCycle();

        $ledger = new CustomerLedger();

        foreach ([5, 4, 3, 2, 1, 0] as $age) {
            $day  = $this->daysAgo($age);
            $rows = $this->customers($day, 7);

            if ($rows === []) {
                $this->assertSame('0.00', bcadd($ledger->position($this->customerId, $day)->accountBalance, '0', 2), "Nothing is aged on day {$day} yet the account holds something");

                continue;
            }

            $row = $this->rowOf($rows, $this->customerId);

            $this->assertSame(bcadd($ledger->position($this->customerId, $day)->accountBalance, '0', 4), $row->balance(), "The aged ledger of {$day} is not the account of that day");
        }
    }

    public function testBuild_GivesTheSameTotalAndTheBalanceOfTheAccountForEveryLengthOfTheBuckets(): void
    {
        $this->customerCycle();
        $this->supplierCycle();

        $customer = bcadd((new CustomerLedger())->position($this->customerId)->accountBalance, '0', 4);
        $supplier = bcadd((new SupplierLedger())->position($this->supplierId)->accountBalance, '0', 4);

        foreach ([1, 2, 7, 10, 30, 90, 365] as $period) {
            $this->assertSame($customer, $this->rowOf($this->customers(date('Y-m-d'), $period), $this->customerId)->balance(), "customers, buckets of {$period} days");
            $this->assertSame($supplier, $this->rowOf($this->suppliers(date('Y-m-d'), $period), $this->supplierId)->balance(), "suppliers, buckets of {$period} days");
        }
    }

    public function testBuild_LeavesOutADayThatPrecedesTheFirstDocument(): void
    {
        $this->customerCycle();

        $this->assertSame([], $this->customers($this->daysAgo(6)));
    }

    public function testBuild_ListsWhoHoldsCreditAndWhoOwesNothingLeft(): void
    {
        $invoice = $this->invoiceOf('100.00');

        $this->creditNoteOf($invoice, '250.00');

        $settled = $this->anotherCustomer('Nora', 'Fahd');
        $paid    = $this->invoiceOf('40.00', $settled);

        $this->addPayment($paid, '40.00');

        $rows = $this->customers(date('Y-m-d'));

        $this->assertCount(1, $rows);
        $this->assertSame($this->customerId, $rows[0]->partyId);
        $this->assertSame('0.0000', $rows[0]->total());
        $this->assertSame('150.0000', $rows[0]->credit);
        $this->assertSame('-150.0000', $rows[0]->balance());
    }

    public function testBuild_ListsTheCustomersByNameAndOnlyTheOneAskedFor(): void
    {
        $this->invoiceOf('100.00');

        $other = $this->anotherCustomer('Aya', 'Zayed');

        $this->invoiceOf('30.00', $other);

        $all = $this->customers(date('Y-m-d'));
        $one = $this->customers(date('Y-m-d'), AgingBuckets::DEFAULT_PERIOD, $other);

        $this->assertSame([$other, $this->customerId], array_map(static fn (AgedLedgerRow $row): int => $row->partyId, $all));
        $this->assertCount(1, $one);
        $this->assertSame('30.0000', $one[0]->total());
    }

    public function testBuild_CountsASaleThatWasCancelledAfterTheDayAndNoLongerCountsItToday(): void
    {
        $service = $this->item('0', false);
        $sale    = $this->save([$this->line($service, 0, '1', '100.00')], [], [$this->payment(lang('Sales.cash'), '40.00')], SALE_TYPE_POS);
        $past    = $this->daysAgo(3);
        $db      = $this->accountingDb();

        $db->table('sales')->where('sale_id', $sale)->update(['sale_time' => $past . ' 10:00:00']);
        $db->table('sales_payments')->where('sale_id', $sale)->update(['payment_time' => $past . ' 10:00:00']);
        $db->table('accounting_journals')->where(['source_type' => SalePostingBuilder::SOURCE_SALE, 'source_id' => $sale])->update(['journal_date' => $past]);
        $this->dateSalePayments($sale, $past);
        $this->closeThrough($this->daysAgo(2));

        $this->assertTrue($this->sales->delete($sale, false, true, $this->employeeId), (string) $this->sales->accountingFailure()?->getMessage());

        $this->assertSame('60.0000', $this->rowOf($this->customers($this->daysAgo(1)), $this->customerId)->total());
        $this->assertSame([], $this->customers(date('Y-m-d')));
    }

    public function testBuild_AgreesWithTheAgedLedgerThePositionOfTheCustomerGives(): void
    {
        $this->customerCycle();

        $row      = $this->rowOf($this->customers(date('Y-m-d')), $this->customerId);
        $position = (new CustomerLedger())->position($this->customerId);

        $this->assertSame(bcadd($position->accountBalance, '0', 4), $row->balance());
        $this->assertSame(bcadd($position->open(), '0', 4), $row->total());
        $this->assertSame($row->buckets, (new CustomerLedger())->agedLedger(date('Y-m-d'))[0]->buckets);
    }

    public function testBuild_AppliesThePaymentToTheOldestPurchaseFirstForASupplier(): void
    {
        $this->supplierCycle();

        $row = $this->rowOf($this->suppliers(date('Y-m-d'), 1), $this->supplierId);

        $this->assertSame(['50.0000', '120.0000', '0.0000', '180.0000', '0.0000', '0.0000'], $row->buckets);
        $this->assertSame('350.0000', $row->total());
        $this->assertSame('Gulf Supplies', $row->name);
        $this->assertSame((int) $this->supplierAccount(), $row->accountId);
    }

    public function testBuild_CountsOnlyWhatIsDatedByTheDayForASupplierAndTiesToTheBalanceOfThatDay(): void
    {
        $this->supplierCycle();

        $ledger = new SupplierLedger();

        foreach ([5, 3, 2, 1, 0] as $age) {
            $day = $this->daysAgo($age);
            $row = $this->rowOf($this->suppliers($day, 7), $this->supplierId);

            $this->assertSame(bcadd($ledger->position($this->supplierId, $day)->accountBalance, '0', 4), $row->balance(), "The aged ledger of {$day} is not the account of that day");
        }

        $this->assertSame([], $this->suppliers($this->daysAgo(6)));
    }

    public function testBuild_ListsWhoIsOwedNothingAndHoldsCreditForASupplier(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->debitNoteOf($purchase, '100.00');
        $this->payPurchase($purchase, '30.00');

        $rows = $this->suppliers(date('Y-m-d'));

        $this->assertCount(1, $rows);
        $this->assertSame('30.0000', $rows[0]->credit);
        $this->assertSame('-30.0000', $rows[0]->balance());
    }

    public function testBuild_KeepsCustomersAndSuppliersApart(): void
    {
        $this->invoiceOf('100.00');
        $this->purchaseOf('70.00');

        $customers = $this->customers(date('Y-m-d'));
        $suppliers = $this->suppliers(date('Y-m-d'));

        $this->assertCount(1, $customers);
        $this->assertCount(1, $suppliers);
        $this->assertSame('100.0000', $customers[0]->total());
        $this->assertSame('70.0000', $suppliers[0]->total());
    }

    public function testBuild_AgesAReceivedChequeAsCreditAndABouncedOneAsADebitOfTheDayItBounced(): void
    {
        $this->startCheques();

        $this->invoiceDated('100.00', $this->daysAgo(5));

        $cheque = $this->receiveCheque('100.00', $this->daysAgo(2));

        $settled = $this->customers(date('Y-m-d'), 1);

        $this->assertSame([], $settled, 'The cheque settles the invoice, so the customer owes nothing');

        $this->chequeService->reject($cheque, date('Y-m-d'), $this->chequeClerk);

        $row = $this->rowOf($this->customers(date('Y-m-d'), 1), $this->customerId);

        $this->assertSame(['100.0000', '0.0000', '0.0000', '0.0000', '0.0000', '0.0000'], $row->buckets);
        $this->assertSame('0.0000', $row->credit);
        $this->assertSame('100.0000', $row->balance());
        $this->assertSame([], $this->customers($this->daysAgo(1), 1), 'The day before it bounced the books still held the cheque as paid');
    }

    public function testBuild_AgesAChequeDeliveredToASupplierAsDebitAndABouncedOneAsWhatIsOwedAgainFromThatDay(): void
    {
        $this->startCheques();

        $this->purchaseDated('200.00', $this->daysAgo(5));

        $cheque = $this->issueCheque('200.00', $this->daysAgo(2), $this->daysAgo(2));

        $this->assertSame([], $this->suppliers(date('Y-m-d'), 1));

        $this->chequeService->reject($cheque, date('Y-m-d'), $this->chequeClerk);

        $row = $this->rowOf($this->suppliers(date('Y-m-d'), 1), $this->supplierId);

        $this->assertSame(['200.0000', '0.0000', '0.0000', '0.0000', '0.0000', '0.0000'], $row->buckets);
        $this->assertSame('200.0000', $row->balance());
        $this->assertSame([], $this->suppliers($this->daysAgo(1), 1));
    }

    public function testBuild_ListsACustomerWhoHoldsAChequeBiggerThanTheirInvoicesAsCredit(): void
    {
        $this->startCheques();

        $this->invoiceDated('60.00', $this->daysAgo(3));
        $this->receiveCheque('100.00', $this->daysAgo(1));

        $row = $this->rowOf($this->customers(date('Y-m-d'), 1), $this->customerId);

        $this->assertSame('40.0000', $row->credit);
        $this->assertSame('-40.0000', $row->balance());
    }
}
