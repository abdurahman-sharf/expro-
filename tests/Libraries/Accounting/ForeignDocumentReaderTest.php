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

    public function testSales_ListsTheSalesInAForeignCurrencyOfTheDaysOldestFirstAndNoneInTheBase(): void
    {
        $later   = $this->foreignInvoice('100');
        $earlier = $this->foreignInvoice('50');
        $base    = $this->invoiceOf('10');
        $this->saleTimeIs($later, '2031-03-14 10:00:00');
        $this->saleTimeIs($earlier, '2031-03-13 10:00:00');
        $this->saleTimeIs($base, '2031-03-14 11:00:00');

        $found = (new ForeignDocumentReader())->sales('2031-03-13', '2031-03-14', false);

        $this->assertSame(['POS ' . $earlier . ' (USD)', 'POS ' . $later . ' (USD)'], $this->labels($found));
    }

    public function testSales_LeavesOutTheDaysOutsideTheRange(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');

        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-15', '2031-03-16', false));
        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-12', '2031-03-13', false));
        $this->assertCount(1, (new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false));
    }

    public function testSales_ReadsTheHoursWhenTheReportIsAskedByTime(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');

        $reader = new ForeignDocumentReader();

        $this->assertCount(1, $reader->sales('2031-03-14 09:00:00', '2031-03-14 11:00:00', true));
        $this->assertSame([], $reader->sales('2031-03-14 10:30:00', '2031-03-14 11:00:00', true));
    }

    public function testSales_NamesASaleByItsInvoiceNumberWhenItHasOne(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');
        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['invoice_number' => 'INV-77']);

        $this->assertSame(['INV-77 (USD)'], $this->labels((new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false)));
    }

    public function testSales_LeavesOutADraftThatWasNotCompleted(): void
    {
        $sale = $this->foreignInvoice('100');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');
        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['sale_status' => SUSPENDED]);

        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false));
    }

    public function testSales_TakesASaleWithABlankCurrencyForOneInTheBase(): void
    {
        $sale = $this->invoiceOf('10');
        $this->saleTimeIs($sale, '2031-03-14 10:00:00');
        $this->accountingDb()->table('sales')->where('sale_id', $sale)->update(['currency_code' => '']);

        $this->assertSame([], (new ForeignDocumentReader())->sales('2031-03-14', '2031-03-14', false));
    }

    public function testReceivings_TakesAPurchaseWithABlankCurrencyForOneInTheBase(): void
    {
        $purchase = $this->purchaseOf('100.00');
        $this->accountingDb()->table('receivings')->where('receiving_id', $purchase)->update(['receiving_time' => '2031-03-14 09:00:00', 'currency_code' => '']);

        $this->assertSame([], (new ForeignDocumentReader())->receivings('2031-03-14', '2031-03-14', false));
    }

    public function testReceivings_ListsThePurchasesInAForeignCurrencyOfTheDaysAndNoneInTheBase(): void
    {
        $foreign = $this->foreignPurchase();
        $base    = $this->purchaseOf('100.00');
        $this->accountingDb()->table('receivings')->whereIn('receiving_id', [$foreign, $base])->update(['receiving_time' => '2031-03-14 09:00:00']);

        $found = (new ForeignDocumentReader())->receivings('2031-03-14', '2031-03-14', false);

        $this->assertSame(['RECV ' . $foreign . ' (USD)'], $this->labels($found));
        $this->assertSame([], (new ForeignDocumentReader())->receivings('2031-03-15', '2031-03-15', false));
    }
}
