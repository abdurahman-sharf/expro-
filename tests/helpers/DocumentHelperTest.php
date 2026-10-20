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

    public function testDocumentIsolate_EscapesAndForcesLeftToRight(): void
    {
        $this->assertSame('<span dir="ltr">&lt;b&gt; 10.00</span>', documentIsolate('<b> 10.00'));
    }
}
