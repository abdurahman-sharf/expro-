<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\AgingBuckets;
use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\JournalLineDraft;
use App\Libraries\Accounting\PaymentState;
use App\Libraries\Accounting\PostingService;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReturnState;
use App\Libraries\Accounting\SupplierLedger;
use App\Libraries\Accounting\SupplierPosition;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\ChequeFixtureTrait;
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
        $this->startCheques();
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

        $this->assertSame(['today' => '0.0000', '1-30' => '40.0000', '31-60' => '40.0000', '61-90' => '70.0000', '91-120' => '191.0000', '120+' => '0.0000'], $aging);
        $this->assertSame(SupplierPosition::AGING_BUCKETS, array_keys($aging));
        $this->assertSupplierTies();
    }

    public function testPosition_LeavesAPaidPurchaseOutOfTheAging(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->payPurchase($purchase, '100.00');

        $this->assertSame(['today' => '0.0000', '1-30' => '0.0000', '31-60' => '0.0000', '61-90' => '0.0000', '91-120' => '0.0000', '120+' => '0.0000'], $this->supplierPosition()->aging(date('Y-m-d')));
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

    // ---------------------------------------------------------------------------------------
    // The six buckets, the due date and the position of a supplier on a day (WP13)
    // ---------------------------------------------------------------------------------------

    public function testAging_SpreadsWhatIsOpenOverTheSixBucketsByTheEdgesOfTheirRanges(): void
    {
        $amounts = ['0' => '1.00', '1' => '2.00', '30' => '4.00', '31' => '8.00', '60' => '16.00', '61' => '32.00', '90' => '64.00', '91' => '128.00', '120' => '256.00', '121' => '512.00'];

        foreach ($amounts as $age => $total) {
            $this->purchaseDated($total, $this->daysAgo((int) $age));
        }

        $aging = $this->supplierPosition()->aging(date('Y-m-d'));

        $this->assertSame(['today' => '1.0000', '1-30' => '6.0000', '31-60' => '24.0000', '61-90' => '96.0000', '91-120' => '384.0000', '120+' => '512.0000'], $aging);
        $this->assertSame($this->supplierPosition()->open(), AgingBuckets::total(array_values($aging)));
        $this->assertSupplierTies();
    }

    public function testAging_AgesFromTheDayOfThePurchaseAndIgnoresItsDueDate(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(5), 30);

        $position = $this->supplierPosition();

        $this->assertSame('100.0000', $position->aging(date('Y-m-d'))['1-30']);
        $this->assertSame('0.0000', $position->aging(date('Y-m-d'))['today']);
        $this->assertSame('0.00', bcadd($position->overdue(date('Y-m-d')), '0', 2));
    }

    public function testAging_LeavesOutAPurchaseDatedAfterTheDayOfTheReport(): void
    {
        $this->purchaseDated('100.00', $this->daysAgo(2));
        $this->purchaseDated('50.00', $this->daysAgo(0));

        $position = $this->supplierPosition();

        $this->assertSame('100.0000', $position->aging($this->daysAgo(1))['1-30']);
        $this->assertSame('0.0000', AgingBuckets::total(array_values($position->aging($this->daysAgo(3)))));

        $later = $position->aging(date('Y-m-d', strtotime('+60 days')));

        $this->assertSame('50.0000', $later['31-60']);
        $this->assertSame('100.0000', $later['61-90']);
    }

    public function testPosition_OnADayOfThePastCountsOnlyWhatWasDatedByThenAndTiesToTheBalanceOfThatDay(): void
    {
        $first = $this->purchaseDated('100.00', $this->daysAgo(2));

        $this->purchaseDated('50.00', $this->daysAgo(2));
        $this->payPurchase($first, '100.00');

        $yesterday = $this->supplierPosition(null, $this->daysAgo(1));
        $today     = $this->supplierPosition();

        $this->assertSame('150.00', bcadd($yesterday->open(), '0', 2));
        $this->assertSame('150.00', bcadd($yesterday->accountBalance, '0', 2));
        $this->assertTrue($yesterday->agrees());
        $this->assertSame('150.0000', $yesterday->aging($this->daysAgo(1))['1-30']);
        $this->assertSame('50.00', bcadd($today->open(), '0', 2));
        $this->assertSame($this->daysAgo(1), $yesterday->asOf);
        $this->assertSupplierTies();
    }

    public function testPosition_OnADayBeforeAPurchaseLeavesItOutWithItsPaymentAndDebitNote(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(2));

        $this->payPurchase($purchase, '30.00');
        $this->debitNoteOf($purchase, '20.00');

        $before = $this->supplierPosition(null, $this->daysAgo(3));

        $this->assertSame([], $before->purchases);
        $this->assertSame([], $before->credits);
        $this->assertSame('0.00', bcadd($before->accountBalance, '0', 2));
        $this->assertTrue($before->agrees());
    }

    public function testPosition_OnADayAfterAPurchaseWasDeletedLeavesItOutAndBeforeItKeepsIt(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(3));

        $this->assertTrue($this->purchases->delete_value($purchase, $this->employeeId), (string) $this->purchases->accountingFailure()?->getMessage());

        $reversals = $this->accountingDb()->table('accounting_journals')->where('reverses_journal_id IS NOT NULL')->get()->getResult();

        $this->assertNotSame([], $reversals);

        foreach ($reversals as $reversal) {
            $this->assertSame($this->daysAgo(3), $reversal->journal_date, 'A reversal made while the period is open takes the day of what it reverses');
        }

        $this->assertNull($this->supplierPosition(null, $this->daysAgo(1))->purchase($purchase));
        $this->assertSame('0.00', bcadd($this->supplierPosition(null, $this->daysAgo(1))->accountBalance, '0', 2));
        $this->assertNull($this->supplierPosition(null, $this->daysAgo(4))->purchase($purchase));
    }

    public function testPosition_LeavesOutADocumentWhoseRecordIsDatedAfterTheDayEvenIfItsJournalIsOlder(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(3));

        $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->update(['invoice_date' => $this->daysAgo(1)]);

        $this->assertNull($this->supplierPosition(null, $this->daysAgo(2))->purchase($purchase));
        $this->assertNotNull($this->supplierPosition(null, $this->daysAgo(1))->purchase($purchase));
    }

    public function testPosition_ReadsAPurchaseWithNoTypedDayFromTheDayItWasSavedAndDueThatSameDay(): void
    {
        $purchase = $this->purchaseOf('100.00');

        $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->update(['receiving_time' => $this->daysAgo(40) . ' 10:00:00']);

        $position = $this->supplierPosition();
        $figures  = $position->purchase($purchase);

        $this->assertNull($this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->get()->getRow()->invoice_date);
        $this->assertSame($this->daysAgo(40), $figures->date);
        $this->assertSame($this->daysAgo(40), $figures->dueDate);
        $this->assertNull($figures->termsDays);
        $this->assertSame('100.0000', $position->aging(date('Y-m-d'))['31-60']);
        $this->assertTrue($figures->isOverdue(date('Y-m-d')));
        $this->assertSame(40, $figures->daysOverdue(date('Y-m-d')));
    }

    public function testPosition_PutsThePurchasesInTheOrderOfTheDaysTheyAreDatedThenTheOrderTheyWereMade(): void
    {
        $recent = $this->purchaseDated('10.00', $this->daysAgo(1));
        $old    = $this->purchaseDated('20.00', $this->daysAgo(9));
        $today  = $this->purchaseOf('30.00');

        $this->assertSame([$old, $recent, $today], array_map(static fn ($purchase): int => $purchase->receivingId, $this->supplierPosition()->purchases));
    }

    public function testIsOverdue_IsTrueOnlyForWhatIsOpenAndFellDueBeforeToday(): void
    {
        $dueYesterday = $this->purchaseDated('10.00', $this->daysAgo(3), 2);
        $dueToday     = $this->purchaseDated('20.00', $this->daysAgo(3), 3);
        $dueLater     = $this->purchaseDated('30.00', $this->daysAgo(3), 33);
        $noTerms      = $this->purchaseDated('40.00', $this->daysAgo(1));
        $dateToday    = $this->purchaseOf('50.00');
        $paid         = $this->purchaseDated('60.00', $this->daysAgo(9), 1);

        $this->payPurchase($paid, '60.00');

        $position = $this->supplierPosition();
        $today    = date('Y-m-d');

        $this->assertTrue($position->purchase($dueYesterday)->isOverdue($today));
        $this->assertFalse($position->purchase($dueToday)->isOverdue($today));
        $this->assertFalse($position->purchase($dueLater)->isOverdue($today));
        $this->assertTrue($position->purchase($noTerms)->isOverdue($today));
        $this->assertFalse($position->purchase($dateToday)->isOverdue($today));
        $this->assertFalse($position->purchase($paid)->isOverdue($today));
        $this->assertSame('50.00', bcadd($position->overdue($today), '0', 2));
        $this->assertSame(1, $position->purchase($dueYesterday)->daysOverdue($today));
        $this->assertSame(0, $position->purchase($dueToday)->daysOverdue($today));
        $this->assertSame(0, $position->purchase($dueLater)->daysOverdue($today));
        $this->assertSame(0, $position->purchase($paid)->daysOverdue($today));
        $this->assertSupplierTies();
    }

    public function testPosition_CountsAnApplicationOnlyFromTheDayItWasMadeUntilTheDayItWasReleased(): void
    {
        $purchase = $this->purchaseDated('100.00', $this->daysAgo(6));
        $note     = $this->debitNoteOf($purchase, '40.00');
        $db       = $this->accountingDb();

        $db->table('receivings')->where('receiving_id', $note)->update(['receiving_time' => $this->daysAgo(5) . ' 10:00:00']);
        $db->table('accounting_journals')->where(['source_type' => ReceivingPostingBuilder::SOURCE_PURCHASE, 'source_id' => $note])->update(['journal_date' => $this->daysAgo(5)]);

        $this->assertSame(1, $db->table('supplier_credit_applications')->where('supplier_id', $this->supplierId)->countAllResults());

        $db->table('supplier_credit_applications')->where('supplier_id', $this->supplierId)->update(['created_at' => $this->daysAgo(3) . ' 09:00:00', 'released_at' => $this->daysAgo(1) . ' 09:00:00']);

        $beforeIt = $this->supplierPosition(null, $this->daysAgo(4));
        $whileIt  = $this->supplierPosition(null, $this->daysAgo(2));
        $afterIt  = $this->supplierPosition(null, date('Y-m-d'));

        $this->assertSame(['100.00', '40.00'], [bcadd($beforeIt->open(), '0', 2), bcadd($beforeIt->credit(), '0', 2)]);
        $this->assertSame(['60.00', '0.00'], [bcadd($whileIt->open(), '0', 2), bcadd($whileIt->credit(), '0', 2)]);
        $this->assertSame(['100.00', '40.00'], [bcadd($afterIt->open(), '0', 2), bcadd($afterIt->credit(), '0', 2)]);

        foreach ([$beforeIt, $whileIt, $afterIt] as $position) {
            $this->assertTrue($position->agrees());
            $this->assertSame('60.00', bcadd($position->accountBalance, '0', 2));
        }
    }

    public function testPosition_TiesSupplierAndAccountForPurchasesOfAnyDayWithTermsPaymentsAndDebitNotes(): void
    {
        $old    = $this->purchaseDated('300.00', $this->daysAgo(45), 15);
        $recent = $this->purchaseDated('120.00', $this->daysAgo(3), 30);
        $today  = $this->purchaseOf('80.00');

        $this->payPurchase($old, '100.00');
        $this->debitNoteOf($recent, '30.00');
        $this->payPurchase($today, '80.00');

        $position = $this->supplierPosition();

        $this->assertSame('290.00', bcadd($position->open(), '0', 2));
        $this->assertSame(['today' => '0.0000', '1-30' => '90.0000', '31-60' => '200.0000', '61-90' => '0.0000', '91-120' => '0.0000', '120+' => '0.0000'], $position->aging(date('Y-m-d')));
        $this->assertSame('200.00', bcadd($position->overdue(date('Y-m-d')), '0', 2));
        $this->assertSupplierTies();
    }

    public function testPosition_HoldsAChequeDeliveredToTheSupplierAsCreditUntilItBounces(): void
    {
        $purchase = $this->purchaseOf('300.00');
        $cheque   = $this->issueCheque('300.00', date('Y-m-d', strtotime('-3 days')), date('Y-m-d', strtotime('-3 days')));

        $this->chequeService->reject($cheque, date('Y-m-d', strtotime('-1 day')), $this->chequeClerk);

        $ledger = new SupplierLedger();

        $this->assertSame('0.0000', $ledger->position($this->supplierId, date('Y-m-d', strtotime('-2 days')))->open(), 'It paid the purchase by then');
        $this->assertTrue($ledger->position($this->supplierId, date('Y-m-d', strtotime('-2 days')))->agrees());
        $this->assertSame('300.0000', $ledger->position($this->supplierId)->open());
        $this->assertTrue($ledger->position($this->supplierId)->agrees());
        $this->assertSame($purchase, $ledger->position($this->supplierId)->purchases[0]->receivingId);
    }

    public function testPosition_ListsTheChequeAsACreditSourceOfItsOwnKindThatIsGoneOnceItBounces(): void
    {
        $cheque = $this->issueCheque('90.00', date('Y-m-d'), date('Y-m-d'));

        $credits = (new SupplierLedger())->position($this->supplierId)->credits;

        $this->assertSame(['cheque', $cheque, '90.0000'], [$credits[0]->type, $credits[0]->sourceId, $credits[0]->available()]);

        $this->chequeService->reject($cheque, date('Y-m-d'), $this->chequeClerk);

        $this->assertSame([], (new SupplierLedger())->position($this->supplierId)->credits);
    }

    public function testPosition_CountsNoCreditForAChequeThatWasOnlyIssuedOrWasCancelled(): void
    {
        $issued    = $this->issueCheque('50.00', date('Y-m-d'));
        $cancelled = $this->issueCheque('60.00', date('Y-m-d'));

        $this->chequeService->cancel($cancelled, $this->chequeClerk);

        $this->assertSame([], (new SupplierLedger())->position($this->supplierId)->credits);
        $this->assertGreaterThan($issued, $cancelled);
        $this->assertSupplierTies();
    }
}
