<?php

namespace Tests\Helpers;

use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class DocumentHelperTest extends CIUnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        helper('document');
    }

    public function testDocumentTypesListsPrintableDocuments(): void
    {
        $this->assertSame(['invoice', 'tax_invoice', 'quote', 'work_order'], documentTypes());
    }

    public function testDocumentProfileNumbersInvoiceByInvoiceNumber(): void
    {
        $profile = documentProfile('invoice', ['invoice_number' => 'INV-7', 'transaction_date' => '2026-10-20', 'total' => 10, 'amount_due' => 0]);

        $this->assertSame('INV-7', $profile['number']);
        $this->assertSame(lang('Sales.invoice'), $profile['title']);
        $this->assertTrue($profile['showPayments']);
        $this->assertFalse($profile['showSignature']);
    }

    public function testDocumentProfileNumbersQuoteByQuoteNumber(): void
    {
        $profile = documentProfile('quote', ['quote_number' => 'Q-3', 'transaction_date' => '2026-10-20', 'total' => 10]);

        $this->assertSame('Q-3', $profile['number']);
        $this->assertFalse($profile['showPayments']);
    }

    public function testDocumentProfileRequiresSignatureOnTaxInvoiceAndWorkOrder(): void
    {
        $this->assertTrue(documentProfile('tax_invoice', [])['showSignature']);
        $this->assertTrue(documentProfile('work_order', [])['showSignature']);
    }

    public function testDocumentProfileHidesPricesOnWorkOrderWithoutPriceInfo(): void
    {
        $profile = documentProfile('work_order', ['print_price_info' => false, 'total' => 10, 'discount' => 5, 'work_order_number' => 'W-1']);

        $this->assertFalse($profile['showPrices']);
        $this->assertFalse($profile['showUnitDiscount']);
        $this->assertSame('W-1', $profile['number']);
        $this->assertCount(2, $profile['meta']);
    }

    public function testDocumentProfileShowsAmountDueOnlyForOpenInvoices(): void
    {
        $paid = documentProfile('invoice', ['total' => 10, 'amount_due' => 0]);
        $open = documentProfile('invoice', ['total' => 10, 'amount_due' => 4]);

        $this->assertCount(3, $paid['meta']);
        $this->assertCount(4, $open['meta']);
    }

    public function testDocumentProfileShowsHsnOnlyWhenEnabledForInvoices(): void
    {
        $this->assertTrue(documentProfile('invoice', ['include_hsn' => true])['showHsn']);
        $this->assertFalse(documentProfile('quote', ['include_hsn' => true])['showHsn']);
        $this->assertFalse(documentProfile('invoice', ['include_hsn' => false])['showHsn']);
    }

    public function testDocumentLogoSourceReturnsEmptyWithoutLogo(): void
    {
        $this->assertSame('', documentLogoSource(['company_logo' => ''], 'pdf'));
        $this->assertSame('', documentLogoSource([], 'screen'));
    }

    public function testDocumentLogoSourceUsesBasenameOnly(): void
    {
        $this->assertStringEndsWith('/uploads/logo.png', documentLogoSource(['company_logo' => '../../etc/logo.png'], 'screen'));
        $this->assertSame('', documentLogoSource(['company_logo' => '../../etc/passwd'], 'pdf'));
    }

    public function testDocumentBarcodeSourceStripsDoctypeFromSvg(): void
    {
        $svg = '<?xml version="1.0"?><!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg></svg>';

        $source = documentBarcodeSource($svg);

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $source);
        $this->assertStringNotContainsString('DOCTYPE', base64_decode(substr($source, strlen('data:image/svg+xml;base64,')), true));
        $this->assertSame('', documentBarcodeSource(''));
    }

    public function testDocumentIsolateEscapesAndForcesLeftToRight(): void
    {
        $this->assertSame('<span dir="ltr">&lt;b&gt; 10.00</span>', documentIsolate('<b> 10.00'));
    }
}
