<?php

namespace Tests\Helpers;

use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use Config\OSPOS;

class DocumentHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $config           = new OSPOS();
        $config->settings = [
            'number_locale'       => 'en_US',
            'currency_symbol'     => '$',
            'currency_decimals'   => 2,
            'thousands_separator' => '1',
        ];
        Factories::injectMock('config', OSPOS::class, $config);

        helper(['locale', 'document']);
    }

    protected function tearDown(): void
    {
        Factories::reset('config');

        parent::tearDown();
    }

    public function testDocumentTypes_ListsPrintableDocuments(): void
    {
        $this->assertSame(['invoice', 'tax_invoice', 'quote', 'work_order'], documentTypes());
    }

    public function testDocumentProfile_NumbersInvoiceByInvoiceNumber(): void
    {
        $profile = documentProfile('invoice', ['invoice_number' => 'INV-7', 'transaction_date' => '2026-10-20', 'total' => 10, 'amount_due' => 0]);

        $this->assertSame('INV-7', $profile['number']);
        $this->assertSame(lang('Sales.invoice'), $profile['title']);
        $this->assertTrue($profile['showPayments']);
        $this->assertFalse($profile['showSignature']);
    }

    public function testDocumentProfile_NumbersQuoteByQuoteNumber(): void
    {
        $profile = documentProfile('quote', ['quote_number' => 'Q-3', 'transaction_date' => '2026-10-20', 'total' => 10]);

        $this->assertSame('Q-3', $profile['number']);
        $this->assertFalse($profile['showPayments']);
    }

    public function testDocumentProfile_RequiresSignatureOnTaxInvoiceAndWorkOrder(): void
    {
        $this->assertTrue(documentProfile('tax_invoice', [])['showSignature']);
        $this->assertTrue(documentProfile('work_order', [])['showSignature']);
    }

    public function testDocumentProfile_HidesPricesOnWorkOrderWithoutPriceInfo(): void
    {
        $profile = documentProfile('work_order', ['print_price_info' => false, 'total' => 10, 'discount' => 5, 'work_order_number' => 'W-1']);

        $this->assertFalse($profile['showPrices']);
        $this->assertFalse($profile['showUnitDiscount']);
        $this->assertSame('W-1', $profile['number']);
        $this->assertCount(2, $profile['meta']);
    }

    public function testDocumentProfile_ShowsAmountDueOnlyForOpenInvoices(): void
    {
        $paid = documentProfile('invoice', ['total' => 10, 'amount_due' => 0]);
        $open = documentProfile('invoice', ['total' => 10, 'amount_due' => 4]);

        $this->assertCount(3, $paid['meta']);
        $this->assertCount(4, $open['meta']);
    }

    public function testDocumentProfile_ShowsHsnOnlyWhenEnabledForInvoices(): void
    {
        $this->assertTrue(documentProfile('invoice', ['include_hsn' => true])['showHsn']);
        $this->assertFalse(documentProfile('quote', ['include_hsn' => true])['showHsn']);
        $this->assertFalse(documentProfile('invoice', ['include_hsn' => false])['showHsn']);
    }

    public function testDocumentLogoSource_ReturnsEmptyWithoutLogo(): void
    {
        $this->assertSame('', documentLogoSource(['company_logo' => ''], 'pdf'));
        $this->assertSame('', documentLogoSource([], 'screen'));
    }

    public function testDocumentLogoSource_UsesBasenameOnly(): void
    {
        $this->assertStringEndsWith('/uploads/logo.png', documentLogoSource(['company_logo' => '../../etc/logo.png'], 'screen'));
        $this->assertSame('', documentLogoSource(['company_logo' => '../../etc/passwd'], 'pdf'));
    }

    public function testDocumentBarcodeSource_StripsDoctypeFromSvg(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg></svg>';

        $source = documentBarcodeSource($svg);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $source);
        $this->assertStringNotContainsString('DOCTYPE', base64_decode(substr($source, strlen('data:image/svg+xml;base64,')), true));
        $this->assertSame('', documentBarcodeSource(''));
    }

    public function testDocumentCell_IsolatesNumbersAndEscapesText(): void
    {
        $this->assertSame('<span dir="ltr">$42.50</span>', documentCell('$42.50'));
        $this->assertSame('أرز &amp; سكر', documentCell('أرز & سكر'));
        $this->assertSame('a<br />' . "\n" . 'b', documentCell("a\nb"));
    }

    public function testDocumentIsolate_EscapesAndForcesLeftToRight(): void
    {
        $this->assertSame('<span dir="ltr">&lt;b&gt; 10.00</span>', documentIsolate('<b> 10.00'));
    }

    public function testInvoiceDiscountLabel_NamesThePercentageOfADiscountEnteredAsOne(): void
    {
        $this->assertSame(lang('Sales.invoice_discount') . ' (' . to_decimals('10') . '%)', invoiceDiscountLabel(['type' => PERCENT, 'value' => '10']));
    }

    public function testInvoiceDiscountLabel_NamesOnlyTheDiscountEnteredAsAnAmountOrNotEnteredAtAll(): void
    {
        $this->assertSame(lang('Sales.invoice_discount'), invoiceDiscountLabel(['type' => FIXED, 'value' => '20']));
        $this->assertSame(lang('Sales.invoice_discount'), invoiceDiscountLabel(null));
    }

    public function testHeaderChargeRows_ListsNothingForADocumentWithoutCharges(): void
    {
        $this->assertSame([], headerChargeRows([]));
        $this->assertSame([], headerChargeRows(['shipping' => '0.0000', 'adjustment' => '0.0000', 'shipping_tax' => '0.0000']));
    }

    public function testHeaderChargeRows_ListsTheShippingTheTaxOnItAndTheAdjustmentInThatOrderUnderTheLabelTyped(): void
    {
        $rows = headerChargeRows(['shipping' => '20.0000', 'shipping_tax' => '3.0000', 'shipping_tax_included' => false, 'adjustment' => '-10.0000', 'adjustment_label' => 'Handling fee']);

        $this->assertSame([[lang('Sales.shipping'), '20.0000'], [lang('Sales.shipping_tax'), '3.0000'], ['Handling fee', '-10.0000']], $rows);
    }

    public function testHeaderChargeRows_LeavesOutTheTaxOnShippingWhenItIsInsideTheAmount(): void
    {
        $rows = headerChargeRows(['shipping' => '100.0000', 'shipping_tax' => '13.0435', 'shipping_tax_included' => true]);

        $this->assertSame([[lang('Sales.shipping'), '100.0000']], $rows);
    }

    public function testHeaderChargeRows_LeavesOutTheTaxWhenTheShippingHasNoneAndTheTaxWhenThereIsNoShipping(): void
    {
        $this->assertSame([[lang('Sales.shipping'), '20.0000']], headerChargeRows(['shipping' => '20.0000', 'shipping_tax' => '0.0000']));
        $this->assertSame([], headerChargeRows(['shipping' => '0.0000', 'shipping_tax' => '3.0000']), 'a tax with no shipping under it is not printed alone');
    }

    public function testHeaderChargeRows_NamesAnAdjustmentWithoutALabelByTheGenericWord(): void
    {
        foreach ([[], ['adjustment_label' => ''], ['adjustment_label' => '   ']] as $label) {
            $this->assertSame([[lang('Sales.adjustment'), '5.0000']], headerChargeRows(['adjustment' => '5.0000'] + $label));
        }
    }

    public function testHeaderChargeRows_PrintsAnAdjustmentThatIsOnlyANegativeAmount(): void
    {
        $this->assertSame([[lang('Sales.adjustment'), '-0.0100']], headerChargeRows(['adjustment' => '-0.0100']));
    }
}
