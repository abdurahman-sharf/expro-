<?php

namespace Tests\Libraries\Portal;

use App\Libraries\Portal\PortalDocuments;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

/**
 * The documents of a customer as the portal reads them: bound to the customer given, whatever number is asked
 * for, and holding what the customer may see.
 *
 * @internal
 */
final class PortalDocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ItemFixtureTrait;
    use SaleAccountingFixtureTrait;

    protected $migrate     = true;
    protected $migrateOnce = true;
    protected $refresh     = false;
    protected $namespace;
    private int $other;

    public static function setUpBeforeClass(): void
    {
        $seeder = Database::seeder('tests');
        $seeder->call('TestDatabaseBootstrapSeeder');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->startSelling();
        $this->other = $this->anotherCustomer();
    }

    protected function tearDown(): void
    {
        $this->stopSelling();

        parent::tearDown();
    }

    private function documents(): PortalDocuments
    {
        return new PortalDocuments();
    }

    private function paymentOf(int $saleId): int
    {
        return (int) $this->accountingDb()->table('sales_payments')->where('sale_id', $saleId)->get()->getRow()->payment_id;
    }

    public function testInvoiceFindsAnInvoiceOfTheCustomerAndNotOneOfAnotherCustomer(): void
    {
        $mine   = $this->invoiceOf('100.00');
        $theirs = $this->invoiceOf('200.00', $this->other);

        $this->assertSame($mine, $this->documents()->invoice($this->customerId, $mine)['sale_id']);
        $this->assertNull($this->documents()->invoice($this->customerId, $theirs));
        $this->assertNull($this->documents()->invoice($this->other, $mine));
        $this->assertSame($theirs, $this->documents()->invoice($this->other, $theirs)['sale_id']);
    }

    public function testInvoiceFindsNothingForANumberThatIsNoSaleAndForADraftAndAQuote(): void
    {
        $draft = $this->draftInvoice(null, $this->customerId);
        $quote = $this->quote(null, null, $this->customerId);

        $this->assertNull($this->documents()->invoice($this->customerId, 987654));
        $this->assertNull($this->documents()->invoice($this->customerId, $draft));
        $this->assertNull($this->documents()->invoice($this->customerId, $quote));
    }

    public function testInvoiceFindsNothingForAReturnAndCreditNoteFindsNothingForAnInvoice(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '30.00');

        $this->assertNull($this->documents()->invoice($this->customerId, $note));
        $this->assertNull($this->documents()->creditNote($this->customerId, $invoice));
        $this->assertSame($note, $this->documents()->creditNote($this->customerId, $note)['sale_id']);
    }

    public function testCreditNoteFindsACreditNoteOfTheCustomerAndNotOneOfAnotherCustomer(): void
    {
        $foreign = $this->creditNoteOf($this->invoiceOf('100.00', $this->other), '30.00', $this->other);

        $this->assertNull($this->documents()->creditNote($this->customerId, $foreign));
        $this->assertNotNull($this->documents()->creditNote($this->other, $foreign));
    }

    public function testPaymentFindsAPaymentOfTheCustomerAndNotOneOfAnotherCustomer(): void
    {
        $mine   = $this->paymentOf($this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]));
        $theirs = $this->paymentOf($this->invoiceOf('100.00', $this->other, [$this->payment(lang('Sales.cash'), '40.00')]));

        $this->assertNotNull($this->documents()->payment($this->customerId, $mine));
        $this->assertNull($this->documents()->payment($this->customerId, $theirs));
        $this->assertNull($this->documents()->payment($this->customerId, 987654));
        $this->assertNull($this->documents()->payment($this->other, $mine));
    }

    public function testPaymentFindsNothingForWhatWasLeftDue(): void
    {
        $due = $this->paymentOf($this->invoiceOf('100.00', null, [$this->payment(lang('Sales.due'), '100.00')]));

        $this->assertNull($this->documents()->payment($this->customerId, $due));
    }

    public function testListsHoldTheDocumentsOfTheCustomerAloneAndCountThem(): void
    {
        $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]);
        $this->invoiceOf('100.00');
        $foreign = $this->invoiceOf('100.00', $this->other, [$this->payment(lang('Sales.cash'), '40.00')]);
        $this->creditNoteOf($foreign, '10.00', $this->other);

        $this->assertSame(2, $this->documents()->invoices($this->customerId, 1)['total']);
        $this->assertSame(1, $this->documents()->payments($this->customerId, 1)['total']);
        $this->assertSame(0, $this->documents()->creditNotes($this->customerId, 1)['total']);
        $this->assertSame(1, $this->documents()->invoices($this->other, 1)['total']);
        $this->assertSame(1, $this->documents()->creditNotes($this->other, 1)['total']);
        $this->assertCount(2, $this->documents()->invoices($this->customerId, 1)['rows']);
    }

    public function testInvoicesPageTwentyFiveAtATimeNewestFirstAndAnEmptyPageBeyondTheLast(): void
    {
        $ids = [];

        for ($index = 0; $index < 27; $index++) {
            $ids[] = $this->invoiceOf('1.00');
        }

        $first  = $this->documents()->invoices($this->customerId, 1);
        $second = $this->documents()->invoices($this->customerId, 2);

        $this->assertSame(27, $first['total']);
        $this->assertCount(PortalDocuments::PAGE_SIZE, $first['rows']);
        $this->assertCount(2, $second['rows']);
        $this->assertSame(end($ids), $first['rows'][0]['sale_id']);
        $this->assertSame([], $this->documents()->invoices($this->customerId, 3)['rows']);
        $this->assertCount(PortalDocuments::PAGE_SIZE, $this->documents()->invoices($this->customerId, 0)['rows']);
        $this->assertCount(PortalDocuments::PAGE_SIZE, $this->documents()->invoices($this->customerId, -3)['rows']);
    }

    public function testInvoiceCarriesTheLinesTheFiguresAndThePaymentsAndNothingOfTheCost(): void
    {
        $cycle   = $this->referenceInvoice();
        $invoice = $this->save($cycle['items'], $cycle['taxes'], [$this->payment(lang('Sales.cash'), '800.00')]);
        $this->accountingDb()->table('sales_items')->where('sale_id', $invoice)->update(['item_cost_price' => '41.3377']);

        $document = $this->documents()->invoice($this->customerId, $invoice);

        $this->assertCount(2, $document['lines']);
        $this->assertSame('816.50 SAR', $document['total']);
        $this->assertSame('106.50 SAR', $document['tax']);
        $this->assertSame('710.00 SAR', $document['goods']);
        $this->assertSame('800.00 SAR', $document['payments'][0]['amount']);
        $this->assertSame('16.50 SAR', $document['open']);
        $this->assertStringNotContainsString('41.3377', json_encode($document));
        $this->assertStringNotContainsString('41.34', json_encode($document));
        $this->assertStringNotContainsString('cost', strtolower(json_encode(array_keys($document))));
        $this->assertSame([], array_intersect(['cost', 'cost_price', 'item_cost_price', 'margin', 'profit', 'journal', 'employee', 'treasury'], array_keys($document)));
    }

    public function testInvoiceShowsNoPaidOrRemainingFiguresWhileAccountingIsOff(): void
    {
        $invoice = $this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '40.00')]);
        $this->setConfigValue('accounting_enabled', '0');

        $document = $this->documents()->invoice($this->customerId, $invoice);

        $this->assertNull($document['open']);
        $this->assertNull($document['settled']);
        $this->assertSame('100.00 SAR', $document['total']);
    }

    public function testCreditNoteShowsTheAmountsAsPositiveFiguresAndTheInvoiceItCameFrom(): void
    {
        $invoice = $this->invoiceOf('100.00');
        $note    = $this->creditNoteOf($invoice, '30.00');

        $document = $this->documents()->creditNote($this->customerId, $note);

        $this->assertSame('30.00 SAR', $document['total']);
        $this->assertSame('POS ' . $invoice, $document['origin']);
        $this->assertSame('1', $document['lines'][0]['quantity']);
    }

    public function testCreditNoteHidesTheInvoiceNumberWhenTheInvoiceBelongsToAnotherCustomer(): void
    {
        $foreign = $this->invoiceOf('100.00', $this->other);
        $note    = $this->creditNoteOf($foreign, '30.00');

        $this->assertNull($this->documents()->creditNote($this->customerId, $note)['origin']);
    }

    public function testPaymentShowsNeitherTheTreasuryNorTheEmployeeWhoTookIt(): void
    {
        $payment = $this->paymentOf($this->invoiceOf('100.00', null, [$this->payment(lang('Sales.cash'), '60.00')]));

        $labels = array_column($this->documents()->payment($this->customerId, $payment)->rows, 0);

        $this->assertNotContains(lang('Documents.treasury'), $labels);
        $this->assertNotContains(lang('Documents.received_by'), $labels);
        $this->assertContains(lang('Documents.method'), $labels);
    }

    public function testCustomerNameIsTheNameOfTheCustomerGiven(): void
    {
        $this->assertSame('Omar Khaled', $this->documents()->customerName($this->customerId));
        $this->assertSame('Hind Saad', $this->documents()->customerName($this->other));
        $this->assertSame('', $this->documents()->customerName(987654));
    }

    public function testLists_ShowTheDocumentsOfEveryBranchThatNamesTheCustomer(): void
    {
        $second = $this->createBranch('B2', 'Second branch');
        $first  = $this->invoiceOf('100.00');
        $remote = $this->invoiceInBranch($second, '200.00');

        $rows = $this->documents()->invoices($this->customerId, 1)['rows'];

        $this->assertEqualsCanonicalizing([$first, $remote], array_column($rows, 'sale_id'));
        $this->assertNotNull($this->documents()->invoice($this->customerId, $remote));
        $this->assertNull($this->documents()->invoice($this->other, $remote));
    }
}
