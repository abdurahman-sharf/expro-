<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AccountingException;
use App\Libraries\Accounting\AccountingGuard;
use App\Libraries\Accounting\BalanceTable;
use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Accounting\SupplierBalanceReport;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The balance of every supplier over a period, split the way the reference system splits it: the
 * balance before, what was bought, what was returned, the net, what was paid, what debit notes
 * settled and the balance. Each balance is the account of the supplier, so the columns tie to the ledger.
 */
class SupplierBalanceReportTest extends CIUnitTestCase
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

    private function report(?string $from, ?string $to = null, bool $hideEmpty = true): BalanceTable
    {
        return (new SupplierBalanceReport())->build(ReportPeriod::of($from, $to ?? date('Y-m-d')), $hideEmpty);
    }

    /**
     * @return array<string, string>
     */
    private function rowOf(BalanceTable $table, int $supplierId): array
    {
        foreach ($table->rows as $row) {
            if ($row->partyId === $supplierId) {
                return ['before' => $row->before, 'sales' => $row->sales, 'returns' => $row->returns, 'net_sales' => $row->net(), 'payments' => $row->payments, 'settlements' => $row->settlements, 'other' => $row->other, 'balance' => $row->balance];
            }
        }

        $this->fail("The supplier {$supplierId} is not in the report.");
    }

    public function testBuild_SplitsTheBalanceInTheColumnsOfTheReferenceSystemAndTheyReconcile(): void
    {
        $first  = $this->purchaseOf('1000.00');
        $second = $this->purchaseOf('1500.00');

        $this->payPurchase($first, '1000.00');
        $this->payPurchase($second, '500.00');
        $this->debitNoteOf($second, '300.00');

        $table = $this->report(date('Y-m-d'));
        $row   = $this->rowOf($table, $this->supplierId);

        $this->assertSame(['before' => '0.0000', 'sales' => '2500.0000', 'returns' => '0.0000', 'net_sales' => '2500.0000', 'payments' => '1500.0000', 'settlements' => '-300.0000', 'other' => '0.0000', 'balance' => '700.0000'], $row);
        $this->assertSame($this->owed(), bcadd($row['balance'], '0', 2));
        $this->assertTrue($table->rows[0]->adds());
        $this->assertFalse($table->hasOther());
        $this->assertSupplierTies();
    }

    public function testBuild_CarriesWhatPrecedesTheStartIntoTheBalanceBefore(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(1));

        $this->payPurchase($purchase, '40.00');

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $first    = $this->rowOf($this->report(date('Y-m-d'), date('Y-m-d')), $this->supplierId);
        $later    = $this->rowOf($this->report($tomorrow, $tomorrow), $this->supplierId);
        $whole    = $this->rowOf($this->report($this->daysAgo(1)), $this->supplierId);
        $keys     = array_flip(['before', 'sales', 'payments', 'balance']);

        $this->assertSame(['before' => '100.0000', 'sales' => '0.0000', 'payments' => '40.0000', 'balance' => '60.0000'], array_intersect_key($first, $keys));
        $this->assertSame(['before' => '60.0000', 'sales' => '0.0000', 'payments' => '0.0000', 'balance' => '60.0000'], array_intersect_key($later, $keys));
        $this->assertSame(['before' => '0.0000', 'sales' => '100.0000', 'payments' => '40.0000', 'balance' => '60.0000'], array_intersect_key($whole, $keys));
    }

    public function testBuild_TakesTheClosingBalanceOfOnePeriodAsTheBalanceBeforeTheNext(): void
    {
        $purchase = $this->purchaseDated('250.00', $this->daysAgo(5));

        $this->payPurchase($purchase, '100.00');
        $this->purchaseDated('30.00', $this->daysAgo(2));

        $first  = $this->rowOf($this->report($this->daysAgo(10), $this->daysAgo(3)), $this->supplierId);
        $second = $this->rowOf($this->report($this->daysAgo(2)), $this->supplierId);

        $this->assertSame('250.0000', $first['balance']);
        $this->assertSame($first['balance'], $second['before']);
        $this->assertSame('180.0000', $second['balance']);
    }

    public function testBuild_CountsAnAdvanceAsAPaymentAndAnOpeningBalanceOwedAsAPurchase(): void
    {
        $clerk    = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]);
        $advances = new SupplierAdvances();

        $this->receivingIds[] = $advances->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '500.00', date('Y-m-d'), '', $clerk);
        $advances->pay($this->supplierId, '120.00', date('Y-m-d'), lang('Sales.cash'), null, '', '', $clerk);

        $row = $this->rowOf($this->report(date('Y-m-d')), $this->supplierId);

        $this->assertSame('500.0000', $row['sales']);
        $this->assertSame('120.0000', $row['payments']);
        $this->assertSame('380.0000', $row['balance']);
        $this->assertSame($this->owed(), bcadd($row['balance'], '0', 2));
    }

    public function testBuild_CountsAnOpeningBalanceOwedUnderOtherWhenAskedToKeepItApart(): void
    {
        $clerk = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE]);

        $this->receivingIds[] = (new SupplierAdvances())->openingBalance($this->supplierId, SupplierAdvances::WE_OWE, '500.00', date('Y-m-d'), '', $clerk);
        $this->purchaseOf('100.00');

        $today = ReportPeriod::of(date('Y-m-d'), date('Y-m-d'));
        $apart = $this->rowOf((new SupplierBalanceReport())->build($today, true, null, true), $this->supplierId);
        $as    = $this->rowOf((new SupplierBalanceReport())->build($today), $this->supplierId);

        $this->assertSame(['100.0000', '500.0000', '600.0000'], [$apart['sales'], $apart['other'], $apart['balance']]);
        $this->assertSame(['600.0000', '0.0000', '600.0000'], [$as['sales'], $as['other'], $as['balance']]);
    }

    public function testBuild_TotalsEveryColumnOverTheSuppliersAndTheBalanceIsTheOfTheirAccounts(): void
    {
        $other = $this->anotherSupplier();

        $this->payPurchase($this->purchaseOf('300.00'), '120.00');
        $this->payPurchase($this->purchaseOf('80.00', $other), '80.00');
        $this->purchaseDated('45.00', $this->daysAgo(3), null, $other);

        $table = $this->report(null);

        $this->assertCount(2, $table->rows);
        $this->assertSame('425.0000', $table->total('sales'));
        $this->assertSame('200.0000', $table->total('payments'));
        $this->assertSame('225.0000', $table->total('balance'));
        $this->assertSame('0.0000', $table->total('before'));
        $this->assertSame(bcadd($this->owed(), $this->owed($other), 2), bcadd($table->total('balance'), '0', 2));
    }

    public function testBuild_ListsTheSuppliersByName(): void
    {
        $zed = $this->anotherSupplier('Zed Zahra');
        $abe = $this->anotherSupplier('Abe Amer');

        $this->purchaseOf('10.00', $zed);
        $this->purchaseOf('10.00', $abe);
        $this->purchaseOf('10.00');

        $names  = array_map(static fn ($row): string => $row->name, $this->report(null)->rows);
        $sorted = $names;

        sort($sorted);

        $this->assertSame($sorted, $names);
        $this->assertCount(3, $names);
    }

    public function testBuild_CountsAReversalWithTheDocumentItReversesSoADeletedPurchaseLeavesNothing(): void
    {
        $purchase = $this->purchaseOf('60.00');

        $this->assertTrue($this->purchases->delete_value($purchase, $this->employeeId), (string) $this->purchases->accountingFailure()?->getMessage());

        $row = $this->rowOf($this->report(null, null, false), $this->supplierId);

        $this->assertSame(['before' => '0.0000', 'sales' => '0.0000', 'payments' => '0.0000', 'settlements' => '0.0000', 'other' => '0.0000', 'balance' => '0.0000'], array_intersect_key($row, array_flip(['before', 'sales', 'payments', 'settlements', 'other', 'balance'])));
        $this->assertSame([], $this->report(null)->rows);
    }

    public function testBuild_KeepsWhatAManualJournalPutOnTheAccountApartAsOther(): void
    {
        $this->purchaseOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '25.00'), new JournalLineDraft($this->supplierAccount(), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $table = $this->report(null);
        $row   = $this->rowOf($table, $this->supplierId);

        $this->assertSame('25.0000', $row['other']);
        $this->assertSame('100.0000', $row['sales']);
        $this->assertSame('125.0000', $row['balance']);
        $this->assertTrue($table->rows[0]->adds());
        $this->assertTrue($table->hasOther());
    }

    public function testBuild_HidesASupplierWithNothingToShowOnRequestAndShowsItOtherwise(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(10));

        $this->payPurchase($purchase, '100.00');

        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $after    = date('Y-m-d', strtotime('+2 days'));

        $this->assertSame([], $this->report($tomorrow, $after, true)->rows);

        $shown = $this->report($tomorrow, $after, false);

        $this->assertCount(1, $shown->rows);
        $this->assertSame('0.0000', $shown->rows[0]->balance);
    }

    public function testBuild_ShowsASupplierWhoIsOwedNothingButMovedMoneyInThePeriod(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->payPurchase($purchase, '100.00');

        $row = $this->rowOf($this->report(date('Y-m-d')), $this->supplierId);

        $this->assertSame('100.0000', $row['sales']);
        $this->assertSame('100.0000', $row['payments']);
        $this->assertSame('0.0000', $row['balance']);
    }

    public function testBuild_AgreesWithThePositionOfTheSupplierOnTheLastDayOfThePeriod(): void
    {
        $old = $this->purchaseDated('300.00', $this->daysAgo(20));

        $this->payPurchase($old, '100.00');
        $this->purchaseDated('50.00', $this->daysAgo(4));
        $this->debitNoteOf($old, '30.00');

        foreach ([$this->daysAgo(10), $this->daysAgo(3), date('Y-m-d')] as $day) {
            $row      = $this->rowOf($this->report(null, $day), $this->supplierId);
            $position = $this->supplierPosition(null, $day);

            $this->assertSame(bcadd($position->accountBalance, '0', 4), $row['balance'], $day);
            $this->assertTrue($position->agrees(), $day);
        }
    }

    public function testBuild_KeepsCustomersOutOfTheReportOfSuppliers(): void
    {
        $this->invoiceOf('500.00');
        $this->purchaseOf('70.00');

        $table = $this->report(null);

        $this->assertCount(1, $table->rows);
        $this->assertSame('70.0000', $table->total('balance'));
    }

    public function testBuild_RefusesAPeriodThatEndsBeforeItStarts(): void
    {
        $this->expectException(AccountingException::class);

        $this->report(date('Y-m-d'), $this->daysAgo(3));
    }

    public function testBuild_CountsACashRefundFromTheSupplierUnderPaymentsAsAReductionOfThemAndItReconciles(): void
    {
        $this->payPurchase($this->purchaseOf('1000.00'), '1000.00');

        $clerk = $this->employeeWithGrants([AccountingGuard::PAYABLES_MANAGE, AccountingGuard::PAYABLES_REFUND]);

        (new SupplierAdvances())->pay($this->supplierId, '100.00', date('Y-m-d'), 'Cash', null, '', '', $clerk);
        (new CreditRefunds(CreditRefunds::SUPPLIER))->refund($this->supplierId, '40.00', date('Y-m-d'), 'Cash', null, '', '', $clerk);

        $table = $this->report(date('Y-m-d'));
        $row   = $this->rowOf($table, $this->supplierId);

        $this->assertSame('1000.0000', $row['sales']);
        $this->assertSame('1060.0000', $row['payments']);
        $this->assertSame('0.0000', $row['other']);
        $this->assertSame('-60.0000', $row['balance']);
        $this->assertTrue($table->rows[0]->adds());
        $this->assertFalse($table->hasOther());
        $this->assertSame($this->owed(), bcadd($row['balance'], '0', 2));
    }
}
