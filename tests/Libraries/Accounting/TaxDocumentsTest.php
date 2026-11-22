<?php

namespace Tests\Libraries\Accounting;

use App\Libraries\Accounting\InvoiceDiscount;
use App\Libraries\Accounting\TaxDocument;
use App\Libraries\Accounting\TaxDocuments;
use App\Libraries\Accounting\TaxSection;
use App\Libraries\HeaderCharges;
use App\Libraries\ShippingTaxOption;
use App\Libraries\Tax\VatCategoryTotal;
use App\Libraries\Tax\VatSnapshot;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Database;
use Config\OSPOS;
use Tests\Support\AccountingFixtureTrait;
use Tests\Support\EmployeeFixtureTrait;
use Tests\Support\ExpenseAccountingFixtureTrait;
use Tests\Support\ItemFixtureTrait;
use Tests\Support\PurchaseAccountingFixtureTrait;
use Tests\Support\ReportFixtureTrait;
use Tests\Support\SaleAccountingFixtureTrait;
use Tests\Support\StatementFixtureTrait;
use Tests\Support\TaxReportFixtureTrait;

/**
 * What the lines of sales and of purchases say about their tax: the part of a document that carries
 * tax, the tax on it and both by rate, after the discounts of the lines and of the whole document and
 * without the tax inside the prices.
 */
class TaxDocumentsTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use AccountingFixtureTrait;
    use EmployeeFixtureTrait;
    use ExpenseAccountingFixtureTrait;
    use ItemFixtureTrait;
    use ReportFixtureTrait;
    use SaleAccountingFixtureTrait;
    use PurchaseAccountingFixtureTrait;
    use StatementFixtureTrait;
    use TaxReportFixtureTrait;

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

        $this->startTaxBooks();
    }

    protected function tearDown(): void
    {
        $this->accountingDb()->table('sales_vat_totals')->truncate();
        $this->stopTaxBooks();

        parent::tearDown();
    }

    private function documents(int $decimals = 2, int $chunk = TaxDocuments::CHUNK): TaxDocuments
    {
        return new TaxDocuments(null, $decimals, $chunk);
    }

    private function sale(int $saleId, int $decimals = 2): TaxDocument
    {
        $document = $this->documents($decimals)->sales([$saleId])[$saleId] ?? null;

        $this->assertNotNull($document, 'The sale ' . $saleId . ' has no tax figures');

        return $document;
    }

    private function purchase(int $receivingId): TaxDocument
    {
        $document = $this->documents()->purchases([$receivingId])[$receivingId] ?? null;

        $this->assertNotNull($document, 'The purchase ' . $receivingId . ' has no tax figures');

        return $document;
    }

    public function testSales_ReadsTheBaseTheTaxAndTheRateOfAnInvoice(): void
    {
        $document = $this->sale($this->sell('100.00', '15.00'));

        $this->assertSame(TaxSection::SalesInvoices, $document->section);
        $this->assertSame(['100.0000', '15.0000'], [$document->base, $document->tax]);
        $this->assertSame(['15' => ['base' => '100.0000', 'tax' => '15.0000']], $document->rates);
        $this->assertFalse($document->discounted);
    }

    public function testSales_ReadsASaudiSaleFromTheCategoriesItsVatWasWorkedOutIn(): void
    {
        $saleId = $this->sell('0.30', '0.0450');
        (new VatSnapshot())->record($saleId, [
            'S|15.000|'            => new VatCategoryTotal('S', '15.000', null, null, '0.30', '0.05', false),
            'Z|0.000|VATEX-SA-32' => new VatCategoryTotal('Z', '0.000', 'VATEX-SA-32', 'Export of goods', '40.00', '0.00', false),
        ]);

        $document = $this->sale($saleId);

        $this->assertSame(['0.3000', '0.0500'], [$document->base, $document->tax], 'the VAT of the category, as the journal holds it, not the 0.045 of its rows');
        $this->assertSame(['15' => ['base' => '0.3000', 'tax' => '0.0500']], $document->rates);
    }

    public function testUntaxedSales_GivesWhatTheLinesWithoutTaxOfASaleSavedBeforeTheRulesCameTo(): void
    {
        $item     = $this->item('0', false);
        $mixed    = $this->save([$this->line($item, 0, '1', '100.00'), $this->line($item, 1, '2', '20.00')], [$this->tax($item, 0, '15.0000')]);
        $untaxed  = $this->save([$this->line($item, 0, '1', '30.00')], []);
        $returned = $this->save([$this->line($item, 0, '-1', '30.00')], [], [], SALE_TYPE_RETURN, -1, true, $untaxed);
        $saudi    = $this->save([$this->line($item, 0, '1', '40.00')], []);

        (new VatSnapshot())->record($saudi, ['Z|0.000|VATEX-SA-32' => new VatCategoryTotal('Z', '0.000', 'VATEX-SA-32', 'Export of goods', '40.00', '0.00', false)]);

        $this->assertSame([$mixed => '40.0000', $untaxed => '30.0000', $returned => '-30.0000'], $this->documents()->untaxedSales([$mixed, $untaxed, $returned, $saudi]), 'a sale saved under the rules is told by its categories, not here');
    }

    public function testSales_CountsTheShippingOfASaudiSaleOnceThroughItsCategory(): void
    {
        $this->turnOnShipping();

        $item   = $this->item('0', false);
        $saleId = $this->save([$this->line($item, 0, '1', '100.00')], [$this->tax($item, 0, '15.0000')], headerCharges: new HeaderCharges(shipping: '10', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'));
        (new VatSnapshot())->record($saleId, ['S|15.000|' => new VatCategoryTotal('S', '15.000', null, null, '110.00', '16.50', false, '10.00')]);

        $document = $this->sale($saleId);

        $this->assertSame(['110.0000', '16.5000'], [$document->base, $document->tax], 'the shipping is in the category, and not added again from the header');
    }

    public function testSales_GivesAReturnTheSectionOfReturnsAndAmountsWithoutASign(): void
    {
        $invoice  = $this->sell('1000.00', '150.00');
        $document = $this->sale($this->takeBack($invoice, '220.00', '33.00'));

        $this->assertSame(TaxSection::SalesReturns, $document->section);
        $this->assertSame(['220.0000', '33.0000'], [$document->base, $document->tax]);
        $this->assertSame(['15' => ['base' => '220.0000', 'tax' => '33.0000']], $document->rates);
    }

    public function testSales_CountsOnlyTheLinesThatCarryTax(): void
    {
        $taxed  = $this->item('0', false);
        $exempt = $this->item('0', false);
        $saleId = $this->save([$this->line($taxed, 0, '1', '100.00'), $this->line($exempt, 1, '1', '40.00')], [$this->tax($taxed, 0, '15.00')]);

        $document = $this->sale($saleId);

        $this->assertSame(['100.0000', '15.0000'], [$document->base, $document->tax]);
        $this->assertSame('100.0000', $document->rates['15']['base']);
    }

    public function testSales_TakesTheDiscountOfEachLineOffTheBaseWhetherItIsAPercentageOrAnAmountPerUnit(): void
    {
        $percent = $this->item('0', false);
        $fixed   = $this->item('0', false);
        $saleId  = $this->save(
            [$this->line($percent, 0, '4', '75', '0', '10'), $this->line($fixed, 1, '4', '75', '0', '5', FIXED)],
            [$this->tax($percent, 0, '40.50'), $this->tax($fixed, 1, '42.00')],
        );

        $document = $this->sale($saleId);

        $this->assertSame('550.0000', $document->base, '270 after 10 percent of 300 and 280 after 5 off each of 4 units');
        $this->assertSame('82.5000', $document->tax);
        $this->assertFalse($document->discounted, 'A discount on a line is not a discount on the whole document');
    }

    public function testSales_TakesTheShareOfTheDiscountOnTheWholeInvoiceOffTheBaseAndMarksTheDocument(): void
    {
        $cycle = $this->discountedReferenceInvoice();
        $saleId = $this->save($cycle['items'], $cycle['taxes'], [], SALE_TYPE_INVOICE, -1, true, null, COMPLETED, NEW_ENTRY, $cycle['discount']);

        $document = $this->sale($saleId);

        $this->assertSame(['639.0000', '95.8500'], [$document->base, $document->tax]);
        $this->assertTrue($document->discounted);
    }

    public function testSales_TakesTheTaxInsideThePriceOffTheBaseAndLeavesTheTaxAddedToItOn(): void
    {
        $included = $this->item('0', false);
        $added    = $this->item('0', false);
        $saleId   = $this->save([$this->line($included, 0, '1', '115.00'), $this->line($added, 1, '1', '100.00')], [[...$this->tax($included, 0, '15.00'), 'tax_type' => 0], $this->tax($added, 1, '15.00')]);

        $document = $this->sale($saleId);

        $this->assertSame(['200.0000', '30.0000'], [$document->base, $document->tax], '100 of a price of 115 with 15 inside it, and 100 with 15 added');
    }

    public function testSales_RoundsAPercentageDiscountToTheDecimalsGiven(): void
    {
        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '10.10', '0', '12.5')], [$this->tax($service, 0, '1.00')]);

        $this->assertSame('8.8400', $this->sale($saleId, 2)->base, '12.5 percent of 10.10 is 1.2625, kept as 1.26');
        $this->assertSame('9.1000', $this->sale($saleId, 0)->base, 'Kept as 1 when the shop works in whole units');
    }

    public function testSales_SplitsByRateAndCountsALineOfTwoTaxesInTheRowOfEach(): void
    {
        $first  = $this->item('0', false);
        $second = $this->item('0', false);
        $saleId = $this->save(
            [$this->line($first, 0, '1', '100.00'), $this->line($second, 1, '2', '50.00')],
            [$this->tax($first, 0, '15.00'), [...$this->tax($second, 1, '5.00'), 'name' => 'Reduced', 'percent' => '5.0000'], [...$this->tax($first, 0, '2.00'), 'name' => 'Levy', 'percent' => '2.5000']],
        );

        $document = $this->sale($saleId);

        $this->assertSame(['200.0000', '22.0000'], [$document->base, $document->tax], 'Each line counts once in the document');
        $this->assertEquals(['15' => ['base' => '100.0000', 'tax' => '15.0000'], '2.5' => ['base' => '100.0000', 'tax' => '2.0000'], '5' => ['base' => '100.0000', 'tax' => '5.0000']], $document->rates, 'The order of the rows is not the order of the rates', 0.0);
    }

    private function turnOnShipping(string $percent = '15'): void
    {
        $this->setConfigValue('accounting_sales_shipping', '1');
        $this->setConfigValue('accounting_shipping_tax_percent', $percent);
    }

    public function testSales_CountsTheShippingAndItsTaxInTheBaseTheTaxAndTheRateOfTheInvoice(): void
    {
        $this->turnOnShipping();

        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], headerCharges: new HeaderCharges(shipping: '20', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'));

        $document = $this->sale($saleId);

        $this->assertSame(['120.0000', '18.0000'], [$document->base, $document->tax]);
        $this->assertSame(['15' => ['base' => '120.0000', 'tax' => '18.0000']], $document->rates);
        $this->assertFalse($document->discounted);
    }

    public function testSales_SplitsTheShippingTaxIntoItsOwnRateWhenItIsNotTheRateOfTheLines(): void
    {
        $this->turnOnShipping('5');

        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], headerCharges: new HeaderCharges(shipping: '40', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '5'));

        $document = $this->sale($saleId);

        $this->assertSame(['140.0000', '17.0000'], [$document->base, $document->tax]);
        $this->assertEquals(['15' => ['base' => '100.0000', 'tax' => '15.0000'], '5' => ['base' => '40.0000', 'tax' => '2.0000']], $document->rates, '', 0.0);
    }

    public function testSales_TakesTheTaxInsideTheShippingOffItsBaseWhenThePricesIncludeTax(): void
    {
        $this->turnOnShipping();

        $settings = config(OSPOS::class)->settings;
        config(OSPOS::class)->settings['tax_included'] = '1';

        try {
            $service = $this->item('0', false);
            $saleId  = $this->save([$this->line($service, 0, '1', '115.00')], [[...$this->tax($service, 0, '15.00'), 'tax_type' => 0]], headerCharges: new HeaderCharges(shipping: '115', shippingTaxOption: ShippingTaxOption::Included, shippingTaxPercent: '15'));
        } finally {
            config(OSPOS::class)->settings = $settings;
        }

        $this->assertGreaterThan(0, $saleId);

        $document = $this->sale($saleId);

        $this->assertSame(['200.0000', '30.0000'], [$document->base, $document->tax], '100 of a price of 115 and 100 of a shipping of 115, with 15 inside each');
    }

    public function testSales_CountsTheTaxOnTheShippingOfAnInvoiceWhoseGoodsAreFree(): void
    {
        $this->turnOnShipping();

        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '0')], [], headerCharges: new HeaderCharges(shipping: '20', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'));

        $document = $this->sale($saleId);

        $this->assertSame(['20.0000', '3.0000'], [$document->base, $document->tax]);
    }

    public function testSales_LeavesTheBaseAloneWhenTheShippingCarriesNoTax(): void
    {
        $this->turnOnShipping();

        $service = $this->item('0', false);
        $saleId  = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], headerCharges: new HeaderCharges(shipping: '20'));

        $document = $this->sale($saleId);

        $this->assertSame(['100.0000', '15.0000'], [$document->base, $document->tax], 'shipping without tax is not part of what the report counts');
    }

    public function testSales_GivesACreditNoteTheTaxOnItsShippingWithoutASign(): void
    {
        $this->turnOnShipping();

        $invoice = $this->sell('1000.00', '150.00');
        $service = $this->item('0', false);
        $return  = $this->save([$this->line($service, 0, '-1', '100.00')], [$this->tax($service, 0, '-15.00')], [], SALE_TYPE_RETURN, -1, true, $invoice, headerCharges: new HeaderCharges(shipping: '20', shippingTaxOption: ShippingTaxOption::Added, shippingTaxPercent: '15'));

        $document = $this->sale($return);

        $this->assertSame(TaxSection::SalesReturns, $document->section);
        $this->assertSame(['120.0000', '18.0000'], [$document->base, $document->tax]);
    }

    public function testSales_LeavesOutAQuoteAWorkOrderASuspendedSaleAndASaleThatIsNotThere(): void
    {
        $service = $this->item('0', false);
        $line    = [$this->line($service, 0, '1', '100.00')];
        $tax     = [$this->tax($service, 0, '15.00')];

        $quote     = $this->save($line, $tax, [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);
        $workOrder = $this->save($line, $tax, [], SALE_TYPE_WORK_ORDER, -1, true, null, SUSPENDED);
        $invoice   = $this->sell('100.00', '15.00');

        $found = $this->documents()->sales([$quote, $workOrder, $invoice, 987654]);

        $this->assertSame([$invoice], array_keys($found));
    }

    public function testSales_ReadsEverySaleWhateverTheSizeOfTheChunkItReadsThem(): void
    {
        $ids = [$this->sell('100.00', '15.00'), $this->sell('200.00', '30.00'), $this->sell('300.00', '45.00')];

        foreach ([1, 2, 3, 500] as $chunk) {
            $found = $this->documents(2, $chunk)->sales($ids);

            $this->assertSame($ids, array_keys($found), 'Chunk of ' . $chunk);
            $this->assertSame(['100.0000', '200.0000', '300.0000'], array_values(array_map(static fn (TaxDocument $document): string => $document->base, $found)));
        }
    }

    public function testSales_ReadsNothingForSalesThatHaveNoTaxFigures(): void
    {
        $service = $this->item('0', false);
        $quote   = $this->save([$this->line($service, 0, '1', '100.00')], [$this->tax($service, 0, '15.00')], [], SALE_TYPE_QUOTE, -1, true, null, SUSPENDED);

        $this->assertSame([], $this->documents()->sales([$quote]));
        $this->assertSame([], $this->documents()->sales([987654]));
    }

    public function testSales_ReadsOnlyTheSalesNamed(): void
    {
        $first  = $this->sell('100.00', '15.00');
        $second = $this->sell('200.00', '30.00');

        $this->assertSame([$first], array_keys($this->documents()->sales([$first])));
        $this->assertSame([$second], array_keys($this->documents(2, 1)->sales([$second])));
    }

    public function testSales_ReadsASaleOnceWhenItIsNamedTwiceAndNothingWhenNoneIsNamed(): void
    {
        $saleId = $this->sell('100.00', '15.00');

        $this->assertSame([$saleId], array_keys($this->documents()->sales([$saleId, $saleId])));
        $this->assertSame([], $this->documents()->sales([]));
    }

    public function testPurchases_ReadsTheBaseTheTaxAndTheRateOfEachLine(): void
    {
        $item     = $this->item('0.00');
        $document = $this->purchase($this->buy([$this->purchaseLine($item, 0, '10', '100', '15'), $this->purchaseLine($item, 1, '5', '40', '5')]));

        $this->assertSame(TaxSection::Purchases, $document->section);
        $this->assertSame(['1200.0000', '160.0000'], [$document->base, $document->tax]);
        $this->assertSame(['15' => ['base' => '1000.0000', 'tax' => '150.0000'], '5' => ['base' => '200.0000', 'tax' => '10.0000']], $document->rates);
        $this->assertFalse($document->discounted);
    }

    public function testPurchases_ConvertsAPurchaseInAForeignCurrencyAtItsOwnRateAsItsJournalWas(): void
    {
        $item        = $this->item('0.00');
        $receivingId = $this->buy([$this->purchaseLine($item, 0, '10', '100', '15'), $this->purchaseLine($item, 1, '1', '0.33', '15')]);

        $this->accountingDb()->table('receivings')->where('receiving_id', $receivingId)->update(['currency_code' => 'USD', 'currency_rate' => '3.75']);

        $document = $this->purchase($receivingId);

        $this->assertSame(['3751.2400', '562.6900'], [$document->base, $document->tax], 'in riyals: 1000.33 and the 150.05 of tax rounded to the cent, times 3.75');
        $this->assertSame(['15' => ['base' => '3751.2400', 'tax' => '562.6900']], $document->rates);
    }

    public function testPurchases_ReadsAPurchaseSavedUnderTheSaudiRulesByTheTaxOfEachRate(): void
    {
        $item       = $this->item('0.00');
        $receivings = [$this->buy([$this->purchaseLine($item, 0, '1', '0.10', '15'), $this->purchaseLine($item, 1, '1', '0.10', '15'), $this->purchaseLine($item, 2, '1', '0.10', '15')])];
        $receivings[] = $this->buy([$this->purchaseLine($item, 0, '1', '0.10', '15'), $this->purchaseLine($item, 1, '1', '0.10', '15'), $this->purchaseLine($item, 2, '1', '0.10', '15')]);
        $this->accountingDb()->table('receivings')->where('receiving_id', $receivings[1])->update(['vat_calc_version' => 1]);

        $this->assertSame(['0.3000', '0.0450'], [$this->purchase($receivings[0])->base, $this->purchase($receivings[0])->tax]);
        $this->assertSame(['0.3000', '0.0500'], [$this->purchase($receivings[1])->base, $this->purchase($receivings[1])->tax]);
        $this->assertSame(['15' => ['base' => '0.3000', 'tax' => '0.0500']], $this->purchase($receivings[1])->rates);
    }

    public function testPurchases_GivesAReturnTheSectionOfReturnsAndAmountsWithoutASign(): void
    {
        $purchase = $this->buyTaxed('10', '100', '15');
        $document = $this->purchase($this->giveBackTaxed($purchase, '2', '15'));

        $this->assertSame(TaxSection::PurchaseReturns, $document->section);
        $this->assertSame(['200.0000', '30.0000'], [$document->base, $document->tax]);
    }

    public function testPurchases_CountsOnlyTheLinesThatCarryTax(): void
    {
        $item     = $this->item('0.00');
        $document = $this->purchase($this->buy([$this->purchaseLine($item, 0, '10', '100', '15'), $this->purchaseLine($item, 1, '5', '40')]));

        $this->assertSame(['1000.0000', '150.0000'], [$document->base, $document->tax]);
        $this->assertSame(['15'], array_map('strval', array_keys($document->rates)));
    }

    public function testPurchases_TakesTheDiscountOfEachLineOffTheBaseInTheThreeWaysAPurchaseTakesIt(): void
    {
        $item     = $this->item('0.00');
        $percent  = $this->purchase($this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '10', PERCENT)]));
        $perUnit  = $this->purchase($this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '5', FIXED)]));
        $perLine  = $this->purchase($this->buy([$this->purchaseLine($item, 0, '10', '100', '15', '50', FIXED_LINE)]));

        $this->assertSame(['900.0000', '135.0000'], [$percent->base, $percent->tax]);
        $this->assertSame(['950.0000', '142.5000'], [$perUnit->base, $perUnit->tax]);
        $this->assertSame(['950.0000', '142.5000'], [$perLine->base, $perLine->tax]);
    }

    public function testPurchases_TakesTheShareOfTheDiscountOnTheWholePurchaseOffTheBaseAndMarksTheDocument(): void
    {
        $item     = $this->item('0.00');
        $document = $this->purchase($this->buy([$this->purchaseLine($item, 0, '10', '100', '15')], -1, null, null, InvoiceDiscount::of(PERCENT, '10')));

        $this->assertSame(['900.0000', '135.0000'], [$document->base, $document->tax]);
        $this->assertTrue($document->discounted);
    }

    public function testPurchases_LeavesOutATransferOfStockAndAPurchaseThatIsNotThere(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->anotherLocation('Second warehouse');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50', '15')]));

        $transfer = $this->buy([$this->purchaseLine($item, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($item, 1, '-4', '0')], null, null);
        $purchase = $this->buyTaxed('1', '100', '15');

        $this->assertGreaterThan(0, $transfer);
        $this->assertSame([$purchase], array_keys($this->documents()->purchases([$transfer, $purchase, 987654])));
    }

    public function testPurchases_ReadsNothingForPurchasesThatHaveNoTaxFigures(): void
    {
        $item  = $this->item('0.00');
        $depot = $this->anotherLocation('Second warehouse');

        $this->assertGreaterThan(0, $this->buy([$this->purchaseLine($item, 0, '10', '50', '15')]));

        $transfer = $this->buy([$this->purchaseLine($item, 0, '4', '0', '0', '0', PERCENT, $depot), $this->purchaseLine($item, 1, '-4', '0')], null, null);

        $this->assertGreaterThan(0, $transfer);
        $this->assertSame([], $this->documents()->purchases([$transfer]));
        $this->assertSame([], $this->documents()->purchases([987654]));
    }

    public function testPurchases_ReadsOnlyThePurchasesNamed(): void
    {
        $first  = $this->buyTaxed('1', '100', '15');
        $second = $this->buyTaxed('1', '200', '15');

        $this->assertSame([$first], array_keys($this->documents()->purchases([$first])));
        $this->assertSame([$second], array_keys($this->documents(2, 1)->purchases([$second])));
    }

    public function testPurchases_ReadsEveryPurchaseWhateverTheSizeOfTheChunkItReadsThem(): void
    {
        $ids = [$this->buyTaxed('1', '100', '15'), $this->buyTaxed('1', '200', '15'), $this->buyTaxed('1', '300', '15')];

        foreach ([1, 2, 500] as $chunk) {
            $found = $this->documents(2, $chunk)->purchases($ids);

            $this->assertSame($ids, array_keys($found), 'Chunk of ' . $chunk);
        }

        $this->assertSame([], $this->documents()->purchases([]));
    }
}
