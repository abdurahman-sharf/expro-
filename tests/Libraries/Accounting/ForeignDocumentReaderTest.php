<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ForeignDocument;
use App\Libraries\Accounting\ForeignDocumentReader;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The documents in a currency other than the base that fall in the days of a report, read for the
 * legacy reports that add up the amounts as they were typed.
 *
 * @internal
 */
final class ForeignDocumentReaderTest extends CIUnitTestCase
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
        $this->enableDollars();
    }

    protected function tearDown(): void
    {
        $this->stopBuying();

        parent::tearDown();
    }

    /**
     * @param list<ForeignDocument> $documents
     *
     * @return list<string>
     */
    private function labels(array $documents): array
    {
        return array_map(static fn (ForeignDocument $document): string => $document->label(), $documents);
    }

    private function saleTimeIs(int $saleId, string $time): void
    {
        $this->accountingDb()->table('sales')->where('sale_id', $saleId)->update(['sale_time' => $time]);
    }

    private function foreignSaleOfTheDay(string $price): int
    {
        $saleId = $this->foreignInvoice($price);
        $this->saleTimeIs($saleId, '2031-03-14 10:00:00');

        return $saleId;
    }

    private function foreignPurchaseOfTheDay(): int
    {
        $receivingId = $this->foreignPurchase();
        $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->update(['receiving_time' => '2031-03-14 09:00:00']);

        return $receivingId;
    }

    private function anotherBranch(): int
    {
        return $this->createBranch('R' . substr(md5(uniqid('', true)), 0, 8), 'Reader branch');
    }

    private function branchOfTheSale(int $saleId): int
    {
        return (int) $this->accountingDb()->table('sales')->where('sale_id', $saleId)->get()->getRow()->branch_id;
    }

    private function branchOfThePurchase(int $receivingId): int
    {
        return (int) $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->get()->getRow()->branch_id;
    }

    public function testSales_ListsTheSalesInAForeignCurrencyOfTheDaysOldestFirstAndNoneInTheBase(): void
    {
        $later   = $this->foreignInvoice('100');
        $earlier = $this->foreignInvoice('50');
        $base    = $this->invoiceOf('10');
        $this->saleTimeIs($later, '2031-03-14 10:00:00');
        $this->saleTimeIs($earlier, '2031-03-13 10:00:00');
        $this->saleTimeIs($base, '2031-03-14 11:00:00');

        $found = (new ForeignDocumentReader())->sales('2031-03-13', '2031-03-14', false, null);

        $this->assertSame(['POS ' . $earlier . ' (USD)', 'POS ' . $later . ' (USD)'], $this->labels($found));
    }

    public function testSales_LeavesOutTheDaysOutsideTheRange(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');

        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-15', '2031-03-16', false, null));
        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-12', '2031-03-13', false, null));
        $this->assertCount(1, (new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false, null));
    }

    public function testSales_ReadsTheHoursWhenTheReportIsAskedByTime(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');

        $reader = new ForeignDocumentReader();

        $this->assertCount(1, $reader->sales('2031-03-14 09:00:00', '2031-03-14 11:00:00', true, null));
        $this->assertSame([], $reader->sales('2031-03-14 10:30:00', '2031-03-14 11:00:00', true, null));
    }

    public function testSales_NamesASaleByItsInvoiceNumberWhenItHasOne(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');
        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['invoice_number' => 'INV-77']);

        $this->assertSame(['INV-77 (USD)'], $this->labels((new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false, null)));
    }

    public function testSales_LeavesOutADraftThatWasNotCompletedWhenTheReportReadsTheCompletedSales(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');
        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['sale_status' => SUSPENDED]);

        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false, null, 'sales'));
    }

    public function testSales_TakesASaleWithABlankCurrencyForOneInTheBase(): void
    {
        $sale = $this->invoiceOf('10');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');
        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['currency_code' => '']);

        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false, null));
    }

    public function testReceivings_TakesAPurchaseWithABlankCurrencyForOneInTheBase(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->update(['receiving_time' => '2031-03-14 09:00:00', 'currency_code' => '']);

        $this->assertSame([], (new ForeignDocumentReader())->receivings('2031-03-14', '2031-03-14', false, null));
    }

    public function testReceivings_ListsThePurchasesInAForeignCurrencyOfTheDaysAndNoneInTheBase(): void
    {
        $foreign = $this->foreignPurchase();
        $base    = $this->purchaseOf('100.00');
        $this->accountingDb()->table('receivings')->whereIn('receiving_id', [$foreign, $base])->update(['receiving_time' => '2031-03-14 09:00:00']);

        $found = (new ForeignDocumentReader())->receivings('2031-03-14', '2031-03-14', false, null);

        $this->assertSame(['RECV ' . $foreign . ' (USD)'], $this->labels($found));
        $this->assertSame([], (new ForeignDocumentReader())->receivings('2031-03-15', '2031-03-15', false, null));
    }

    public function testSales_ReadsOnlyTheDocumentsOfTheEmployeeGiven(): void
    {
        $mine = $this->foreignSaleOfTheDay('100');

        $author           = $this->employeeId;
        $this->employeeId = $this->employeeWithGrants([]);
        $theirs           = $this->foreignSaleOfTheDay('50');
        $this->employeeId = $author;
        $reader           = new ForeignDocumentReader();

        $this->assertSame(['POS ' . $mine . ' (USD)'], $this->labels($reader->sales('2031-03-14', '2031-03-14', false, null, 'all', 'all', $author)));
        $this->assertSame(['POS ' . $theirs . ' (USD)', 'POS ' . $mine . ' (USD)'], array_reverse($this->labels($reader->sales('2031-03-14', '2031-03-14', false, null))));
    }

    public function testSales_ReadsOnlyTheBranchesOfTheReport(): void
    {
        $inMain  = $this->foreignSaleOfTheDay('100');
        $inOther = $this->foreignSaleOfTheDay('50');
        $main    = $this->branchOfTheSale($inMain);
        $other   = $this->anotherBranch();
        $this->accountingDb()->table('sales')->where('sale_id', $inOther)->update(['branch_id' => $other]);
        $reader = new ForeignDocumentReader();

        $this->assertSame(['POS ' . $inMain . ' (USD)'], $this->labels($reader->sales('2031-03-14', '2031-03-14', false, [$main])));
        $this->assertSame(['POS ' . $inOther . ' (USD)'], $this->labels($reader->sales('2031-03-14', '2031-03-14', false, [$other])));
        $this->assertCount(2, $reader->sales('2031-03-14', '2031-03-14', false, [$main, $other]));
        $this->assertCount(2, $reader->sales('2031-03-14', '2031-03-14', false, null));
        $this->assertSame([], $reader->sales('2031-03-14', '2031-03-14', false, []));
    }

    public function testSales_ReadsTheKindOfSaleTheReportReads(): void
    {
        $db       = $this->accountingDb();
        $invoice  = $this->foreignSaleOfTheDay('100');
        $pos      = $this->foreignSaleOfTheDay('90');
        $return   = $this->foreignSaleOfTheDay('80');
        $canceled = $this->foreignSaleOfTheDay('70');
        $quote    = $this->foreignSaleOfTheDay('60');
        $order    = $this->foreignSaleOfTheDay('50');
        $db->table('sales')->where('sale_id', $pos)->update(['sale_type' => SALE_TYPE_POS]);
        $db->table('sales')->where('sale_id', $return)->update(['sale_type' => SALE_TYPE_RETURN]);
        $db->table('sales')->where('sale_id', $canceled)->update(['sale_status' => CANCELED]);
        $db->table('sales')->where('sale_id', $quote)->update(['sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_QUOTE]);
        $db->table('sales')->where('sale_id', $order)->update(['sale_status' => SUSPENDED, 'sale_type' => SALE_TYPE_WORK_ORDER]);
        $reader = new ForeignDocumentReader();

        $expected = [
            'complete'    => [$invoice, $pos, $return],
            'sales'       => [$invoice, $pos],
            'returns'     => [$return],
            'canceled'    => [$canceled],
            'quotes'      => [$quote],
            'work_orders' => [$order],
            'all'         => [$invoice, $pos, $return, $canceled, $quote, $order],
        ];

        foreach ($expected as $saleType => $saleIds) {
            $this->assertSame(
                array_map(static fn (int $saleId): string => 'POS ' . $saleId . ' (USD)', $saleIds),
                $this->labels($reader->sales('2031-03-14', '2031-03-14', false, null, $saleType)),
                $saleType,
            );
        }
    }

    public function testSales_ReadsOnlyTheSalesWithAnItemInTheLocationOfTheReport(): void
    {
        $db        = $this->accountingDb();
        $inMain    = $this->foreignSaleOfTheDay('100');
        $inOther   = $this->foreignSaleOfTheDay('50');
        $main      = (int) $db->table('sales_items')->where('sale_id', $inMain)->get()->getRow()->item_location;
        $other     = $this->anotherLocation('Reader store');
        $db->table('sales_items')->where('sale_id', $inOther)->update(['item_location' => $other]);
        $reader = new ForeignDocumentReader();

        $this->assertSame(['POS ' . $inOther . ' (USD)'], $this->labels($reader->sales('2031-03-14', '2031-03-14', false, null, 'all', (string) $other)));
        $this->assertSame(['POS ' . $inMain . ' (USD)'], $this->labels($reader->sales('2031-03-14', '2031-03-14', false, null, 'all', (string) $main)));
        $this->assertCount(2, $reader->sales('2031-03-14', '2031-03-14', false, null, 'all', 'all'));
    }

    public function testReceivings_ReadsOnlyTheBranchesOfTheReportAsTheTemporaryTableOfTheReportDoes(): void
    {
        $inMain  = $this->foreignPurchaseOfTheDay();
        $inOther = $this->foreignPurchaseOfTheDay();
        $main    = $this->branchOfThePurchase($inMain);
        $other   = $this->anotherBranch();
        $this->accountingDb()->table('receivings')->where('receiving_id', $inOther)->update(['branch_id' => $other]);
        $reader = new ForeignDocumentReader();

        $this->assertSame(['RECV ' . $inMain . ' (USD)'], $this->labels($reader->receivings('2031-03-14', '2031-03-14', false, [$main])));
        $this->assertSame(['RECV ' . $inOther . ' (USD)'], $this->labels($reader->receivings('2031-03-14', '2031-03-14', false, [$other])));
        $this->assertCount(2, $reader->receivings('2031-03-14', '2031-03-14', false, null));
        $this->assertSame([], $reader->receivings('2031-03-14', '2031-03-14', false, []));

        $this->accountingDb()->table('receivings')->where('receiving_id', $inOther)->update(['to_branch_id' => $main]);

        $this->assertCount(2, $reader->receivings('2031-03-14', '2031-03-14', false, [$main]));
    }

    public function testReceivings_ReadsOnlyThePurchasesWithAnItemInTheLocationOfTheReport(): void
    {
        $db      = $this->accountingDb();
        $inMain  = $this->foreignPurchaseOfTheDay();
        $inOther = $this->foreignPurchaseOfTheDay();
        $main    = (int) $db->table('receivings_items')->where('receiving_id', $inMain)->get()->getRow()->item_location;
        $other   = $this->anotherLocation('Reader store');
        $db->table('receivings_items')->where('receiving_id', $inOther)->update(['item_location' => $other]);
        $reader = new ForeignDocumentReader();

        $this->assertSame(['RECV ' . $inOther . ' (USD)'], $this->labels($reader->receivings('2031-03-14', '2031-03-14', false, null, (string) $other)));
        $this->assertSame(['RECV ' . $inMain . ' (USD)'], $this->labels($reader->receivings('2031-03-14', '2031-03-14', false, null, (string) $main)));
        $this->assertCount(2, $reader->receivings('2031-03-14', '2031-03-14', false, null, 'all'));
    }
}
