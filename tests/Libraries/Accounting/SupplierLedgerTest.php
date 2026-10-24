<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReturnState;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\SupplierPosition;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * What the books say is owed to a supplier, split by purchase: the figures of a purchase, its
 * status, the aging of what is open, and the one rule that matters most, that the open purchases
 * less the credit held with the supplier are the balance of the account of the supplier.
 */
class SupplierLedgerTest extends CIUnitTestCase
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

    /**
     * What a purchase of 100 is, by what was paid into it in cash and what a debit note took
     * back. A debit note is applied to its own purchase, so it counts beside the cash.
     *
     * @return array<string, array{string, string, string, string}>
     */
    public static function statusProvider(): array
    {
        return [
            'unpaid'                                => ['0', '0', 'unpaid', 'none'],
            'partly paid'                           => ['40', '0', 'partial', 'none'],
            'paid'                                  => ['100', '0', 'paid', 'none'],
            'paid in excess'                        => ['130', '0', 'excess', 'none'],
            'a debit note applied to an unpaid one' => ['0', '30', 'partial', 'partial'],
            'partly paid and partly returned'       => ['10', '30', 'partial', 'partial'],
            'paid and partly returned'              => ['100', '30', 'paid', 'partial'],
            'paid and returned in full'             => ['100', '100', 'paid', 'full'],
            'a debit note that pays the rest'       => ['70', '30', 'paid', 'partial'],
        ];
    }

    #[DataProvider('statusProvider')]
    public function testPosition_NamesTheStateOfPaymentAndTheStateOfReturnOfAPurchase(string $paid, string $returned, string $payment, string $return): void
    {
        $purchase = $this->purchaseOf('100.00');

        if ($paid !== '0') {
            $this->payPurchase($purchase, $paid);
        }

        if ($returned !== '0') {
            $this->debitNoteOf($purchase, $returned);
        }

        $figures = $this->supplierPosition()->purchase($purchase);

        $this->assertSame(PaymentState::from($payment), $figures->paymentState());
        $this->assertSame(ReturnState::from($return), $figures->returnState());
        $this->assertSupplierTies();
    }

    public function testPosition_CarriesTheFiguresOfAPurchaseThatWasPaidInPartsAndPartlyReturned(): void
    {
        $purchase = $this->purchaseOf('1000.00');

        $this->payPurchase($purchase, '300.00');
        $this->payPurchase($purchase, '200.00', lang('Sales.check'));
        $this->debitNoteOf($purchase, '100.00');

        $figures = $this->supplierPosition()->purchase($purchase);

        $this->assertSame('1000.00', bcadd($figures->total, '0', 2));
        $this->assertSame('500.00', bcadd($figures->paid, '0', 2));
        $this->assertSame('100.00', bcadd($figures->applied, '0', 2));
        $this->assertSame('100.00', bcadd($figures->returned, '0', 2));
        $this->assertSame('400.00', bcadd($figures->open(), '0', 2));
        $this->assertSame('400.00', $this->owed());
        $this->assertSupplierTies();
    }

    public function testPosition_CountsThePaymentMadeWhenThePurchaseWasSavedAsPaid(): void
    {
        $purchase = $this->purchaseOf('100.00', null, lang('Sales.cash'));

        $figures = $this->supplierPosition()->purchase($purchase);

        $this->assertSame('100.00', bcadd($figures->paid, '0', 2));
        $this->assertSame(PaymentState::Paid, $figures->paymentState());
        $this->assertSame('0.00', $this->owed());
        $this->assertSupplierTies();

        $this->payPurchase($purchase, '25.00');

        $this->assertSame(PaymentState::Excess, $this->supplierPosition()->purchase($purchase)->paymentState());
        $this->assertSame('25.00', bcadd($this->supplierPosition()->credit(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testPosition_FollowsAPurchaseWithTaxThroughItsPaymentsAndAReturnToAZeroBalance(): void
    {
        $item  = $this->item('0.00');
        $other = $this->item('0.00');

        $purchase = $this->buy([$this->purchaseLine($item, 0, '1', '800.00', '15'), $this->purchaseLine($other, 1, '1', '1200.00', '15')]);

        $this->assertGreaterThan(0, $purchase);
        $this->assertSame('2300.00', bcadd($this->supplierPosition()->open(), '0', 2));
        $this->assertSupplierTies();

        $this->buy([$this->purchaseLine($item, 0, '-1', '800.00', '15')], -1, null, $purchase);

        $this->assertSame('1380.00', bcadd($this->supplierPosition()->open(), '0', 2));
        $this->assertSupplierTies();

        $this->payPurchase($purchase, '1000.00');
        $this->payPurchase($purchase, '380.00', lang('Sales.bank_transfer'));

        $position = $this->supplierPosition();

        $this->assertSame('0.00', bcadd($position->open(), '0', 2));
        $this->assertSame('0.00', bcadd($position->credit(), '0', 2));
        $this->assertSame('0.00', bcadd($position->accountBalance, '0', 2));
        $this->assertSame(PaymentState::Paid, $position->purchase($purchase)->paymentState());
        $this->assertSame(ReturnState::Partial, $position->purchase($purchase)->returnState());
        $this->assertSupplierTies();
    }

    public function testPosition_AgesWhatIsOpenByTheAgeOfThePurchase(): void
    {
        $days = ['10' => '10.00', '40' => '40.00', '70' => '70.00', '100' => '100.00', '30' => '30.00', '91' => '91.00'];

        foreach ($days as $age => $total) {
            $purchase = $this->purchaseOf($total);

            $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->update(['receiving_time' => date('Y-m-d 10:00:00', strtotime("-{$age} days"))]);
        }

        $aging = $this->supplierPosition()->aging(date('Y-m-d'));

        $this->assertSame(['0-30' => '40.0000', '31-60' => '40.0000', '61-90' => '70.0000', '91+' => '191.0000'], $aging);
        $this->assertSame(SupplierPosition::AGING_BUCKETS, array_keys($aging));
        $this->assertSupplierTies();
    }

    public function testPosition_LeavesAPaidPurchaseOutOfTheAging(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->payPurchase($purchase, '100.00');

        $this->assertSame(['0-30' => '0.0000', '31-60' => '0.0000', '61-90' => '0.0000', '91+' => '0.0000'], $this->supplierPosition()->aging(date('Y-m-d')));
    }

    public function testPosition_ShowsApartWhatAManualJournalPutOnTheAccountOfTheSupplier(): void
    {
        $this->purchaseOf('100.00');

        (new PostingService())->post(new JournalDraft(
            date: date('Y-m-d'),
            lines: [new JournalLineDraft($this->accountId('cash'), debit: '25.00'), new JournalLineDraft($this->supplierAccount(), credit: '25.00')],
            sourceType: JournalDraft::SOURCE_MANUAL,
            employeeId: $this->employeeId,
            currencyCode: 'SAR',
        ));

        $position = $this->supplierPosition();

        $this->assertFalse($position->agrees());
        $this->assertSame('25.00', bcadd($position->unclassified(), '0', 2));
        $this->assertSame('125.00', bcadd($position->accountBalance, '0', 2));
        $this->assertSame('100.00', bcadd($position->open(), '0', 2));
    }

    public function testPosition_CountsNeitherADeletedPurchaseNorAPaymentThatWasRemoved(): void
    {
        $gone     = $this->purchaseOf('60.00');
        $purchase = $this->purchaseOf('100.00');
        $payment  = $this->payPurchase($purchase, '40.00');

        $deleted = $this->purchases->delete_value($gone, $this->employeeId);
        $this->assertTrue($deleted, (string) $this->purchases->accountingFailure()?->getMessage());
        $removed = $this->changePayment($purchase, $payment, ['payment_amount' => '0']);
        $this->assertTrue($removed, (string) $this->purchases->accountingFailure()?->getMessage());

        $position = $this->supplierPosition();

        $this->assertNull($position->purchase($gone));
        $this->assertSame('0.00', bcadd($position->purchase($purchase)->paid, '0', 2));
        $this->assertSame('100.00', bcadd($position->open(), '0', 2));
        $this->assertSupplierTies();
    }

    public function testPosition_CountsOnlyThePurchasesOfThatSupplier(): void
    {
        $other = $this->anotherSupplier();

        $this->purchaseOf('100.00');
        $this->purchaseOf('250.00', $other);

        $this->assertSame('100.00', bcadd($this->supplierPosition()->open(), '0', 2));
        $this->assertSame('250.00', bcadd($this->supplierPosition($other)->open(), '0', 2));
        $this->assertSupplierTies();
        $this->assertSupplierTies($other);
    }

    public function testPosition_GivesAnEmptyPositionToASupplierWithoutAnAccountOrAPurchase(): void
    {
        $without = $this->insertSupplier('Bare Supplier');
        $empty   = $this->supplierPosition($without);

        $this->assertNull($empty->accountId);
        $this->assertSame([], $empty->purchases);
        $this->assertSame([], $empty->credits);
        $this->assertTrue($empty->agrees());
        $this->assertSame('0.0000', $empty->open());
    }

    public function testPosition_PutsThePurchasesInTheOrderTheyWereMade(): void
    {
        $first  = $this->purchaseOf('10.00');
        $second = $this->purchaseOf('20.00');
        $third  = $this->purchaseOf('30.00');

        $this->assertSame([$first, $second, $third], array_map(static fn ($purchase): int => $purchase->receivingId, $this->supplierPosition()->purchases));
    }

    public function testSuppliersWithBalance_ListsTheSuppliersWhoseAccountHoldsSomethingBySizeAndName(): void
    {
        $other = $this->anotherSupplier('Najd Trading');
        $this->purchaseOf('100.00');
        $this->purchaseOf('250.00', $other);
        $this->anotherSupplier('Quiet Supplier');

        $ledger = new SupplierLedger();
        $rows   = $ledger->suppliersWithBalance();

        $this->assertSame(2, $rows['total']);
        $this->assertSame(['250.0000', '100.0000'], array_map(static fn ($row): string => bcadd((string) $row->balance, '0', 4), $rows['rows']));
        $this->assertSame([$other], array_map(static fn ($row): int => (int) $row->supplier_id, $ledger->suppliersWithBalance('Najd')['rows']));
        $this->assertSame([], $ledger->suppliersWithBalance('Quiet')['rows']);
        $this->assertSame(['Gulf Supplies', 'Najd Trading'], array_map(static fn ($row): string => $row->supplier_name, $ledger->suppliersWithBalance('', 'supplier_name', 'asc')['rows']));
        $this->assertSame(1, count($ledger->suppliersWithBalance('', 'balance', 'desc', 1, 1)['rows']));
    }
}
