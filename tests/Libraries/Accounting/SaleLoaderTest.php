<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\ReturnDocument;
use App\Libraries\Accounting\ReturnOverride;
use App\Libraries\Accounting\SaleLoader;
use App\Libraries\Accounting\ShippingCompanies;
use App\Libraries\HeaderCharges;
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

    public function testLoad_ReadsTheShippingCompanyASaleNamedAndTheAccountOwedToIt(): void
    {
        $this->setConfigValue('accounting_sales_shipping', '1');

        $companies = new ShippingCompanies();
        $account   = $this->customAccount('2112', '21121', 'Owed to Fast Ship');

        $companies->setEnabled(true);

        $company = $companies->create(['name' => 'Fast Ship', 'account_id' => $account]);
        $without = $companies->create(['name' => 'Gulf Express']);
        $cycle   = $this->referenceInvoice();

        $named = (new SaleLoader())->load($this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $company)));
        $bare  = (new SaleLoader())->load($this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20', shippingCompanyId: $without)));
        $none  = (new SaleLoader())->load($this->save($cycle['items'], $cycle['taxes'], headerCharges: new HeaderCharges(shipping: '20')));

        $this->assertSame([$company, $account], [$named->charges->shippingCompanyId, $named->charges->shippingAccountId]);
        $this->assertSame([$without, null], [$bare->charges->shippingCompanyId, $bare->charges->shippingAccountId]);
        $this->assertSame([null, null], [$none->charges->shippingCompanyId, $none->charges->shippingAccountId]);
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
        $let  = $this->save([$this->line($item, 0, '-1', '100', '66.00')], [], [], SALE_TYPE_RETURN, -1, true, null, COMPLETED, NEW_ENTRY, null, ReturnDocument::CreditNote, returnOverrideReason: 'lost the receipt');
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

    public function testLoad_ReadsTheTaxInsideThePriceTheTaxAddedToItAndTheTaxOfEachAccountOfALine(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $saleId  = $this->save(
            [$this->line($item, 0, '1', '100')],
            [$this->taxRow($item, 0, 'VAT', '15.0000', 0, '13.0435', $account), $this->taxRow($item, 0, 'Service', '5.0000', 1, '4.3478')],
        );

        $line = (new SaleLoader())->load($saleId)->lines[0];

        $this->assertSame(['13.0435', '4.3478'], [bcadd($line->includedTax, '0', 4), bcadd($line->addedTax, '0', 4)]);
        $this->assertSame([$account => '13.0435'], $line->accountTaxes);
    }

    public function testLoad_ReadsTheTaxOfAccountsAsAmountsThatAreNotNegativeInAReturn(): void
    {
        $account = $this->customAccount('215', '215003', 'Tax on services');
        $item    = $this->item('0', false);
        $invoice = $this->save([$this->line($item, 0, '1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 1, '15.00', $account)]);
        $return  = $this->save([$this->line($item, 0, '-1', '100')], [$this->taxRow($item, 0, 'VAT', '15.0000', 1, '-15.00', $account)], [], SALE_TYPE_RETURN, -1, true, $invoice);

        $line = (new SaleLoader())->load($return)->lines[0];

        $this->assertSame([$account => '15.0000'], $line->accountTaxes);
        $this->assertSame('-15.0000', bcadd($line->addedTax, '0', 4));
    }

    public function testLoad_ReadsASaleWhoseTaxesHaveNoAccountOfTheirOwnAsOneWithNone(): void
    {
        $cycle  = $this->referenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes']);

        $document = (new SaleLoader())->load($saleId);

        $this->assertSame([[], []], array_map(static fn ($line): array => $line->accountTaxes, $document->lines));
    }
}
