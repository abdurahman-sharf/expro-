<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\ReturnOverride;
use App\Libraries\Accounting\SaleLoader;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;

class SaleLoaderTest extends CIUnitTestCase
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

    public function testLoad_ReadsTheShareOfTheDiscountOnTheWholeOfTheSaleThatEachLineWasSavedWith(): void
    {
        $cycle  = $this->discountedReferenceInvoice(false);
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $document = (new SaleLoader())->load($saleId);

        $this->assertSame(['7.6100', '12.3900'], array_map(static fn ($line): string => bcadd($line->invoiceDiscountShare, '0', 4), $document->lines));
    }

    public function testLoad_ReadsASaleSavedBeforeTheDiscountExistedAsOneWithoutShares(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $this->accountingDb()->table('sales_items')->where('sale_id', $saleId)->update(['invoice_discount_share' => null]);

        $document = (new SaleLoader())->load($saleId);

        $this->assertSame(['0', '0'], array_map(static fn ($line): string => $line->invoiceDiscountShare, $document->lines));
    }

    public function testLoad_ReadsTheSharesOfAReturnWithTheSignOfItsLines(): void
    {
        $cycle     = $this->discountedReferenceInvoice();
        $invoiceId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);
        $returnId  = $this->save([$this->line($cycle['cream'], 0, '-1', '75', '40.00', '10')], [$this->tax($cycle['cream'], 0, '-9.1125')], [], SALE_TYPE_RETURN, -1, true, $invoiceId);

        $document = (new SaleLoader())->load($returnId);

        $this->assertSame('-6.7500', bcadd($document->lines[0]->invoiceDiscountShare, '0', 4));
        $this->assertSame('27.0000', bcadd($document->origin->lines[0]->invoiceDiscountShare, '0', 4));
    }

    public function testLoad_ReadsWhetherAReturnWithNoSaleWasLetThroughAndWhetherItIsARefundInvoice(): void
    {
        $this->grantReturnOverride();

        $item = $this->item('66.00');
        $let  = $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, null, null, 'lost the receipt');
        $plain = $this->save([$this->line($this->item('0', false), 0, '-1', '100')], [], [], SALE_TYPE_RETURN);
        $refund = $this->save([$this->line($this->item('0', false), 0, '-1', '100')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::RefundInvoice);

        $loader = new SaleLoader();

        $this->assertSame([true, false], [$loader->load($let)->returnOverride, $loader->load($let)->refundInvoice]);
        $this->assertSame([false, false], [$loader->load($plain)->returnOverride, $loader->load($plain)->refundInvoice]);
        $this->assertSame([false, true], [$loader->load($refund)->returnOverride, $loader->load($refund)->refundInvoice]);
        $this->assertTrue((new ReturnOverride())->exists($let));
    }

    public function testLoad_NeverCountsASaleThatNamesItsOriginAsLetThroughEvenIfARowSaysSo(): void
    {
        $item    = $this->item('60.00');
        $invoice = $this->save([$this->line($item, 0, '5', '100', '60.00')], [], [$this->payment(lang('Sales.cash'), '500.00')]);
        $return  = $this->save([$this->line($item, 0, '-1', '100', '60.00')], [], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $this->accountingDb()->table('sale_return_overrides')->insert(['sale_id' => $return, 'branch_id' => 1, 'employee_id' => $this->employeeId, 'reason' => 'forged']);

        $this->assertFalse((new SaleLoader())->load($return)->returnOverride);
        $this->assertFalse((new SaleLoader())->load($invoice)->returnOverride);
    }
}
