<?php

namespace Tests\Views;

use App\Libraries\Documents\EInvoicePrint;
use App\Libraries\EInvoicing\DocumentStamper;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EInvoiceMatrixTrait;

/**
 * The printout of a document issued under the Saudi rules: the title and the labels in Arabic whatever the language
 * of the reader, and the VAT of every line, of every category and of the whole as the issued file states them, on
 * paper of both widths.
 */
final class EInvoiceViewTest extends CIUnitTestCase
{
    use EInvoiceMatrixTrait;

    protected function setUp(): void
    {
        parent::setUp();

        helper(['document', 'locale']);
    }

    public function testEinvoice_PrintsTheVatOfEveryLineInArabicOnBothWidths(): void
    {
        $document = $this->matrix()['S-01'];
        $print    = EInvoicePrint::fromXml((new DocumentStamper())->unsigned($document)->xml);

        foreach (['a4', 'thermal'] as $variant) {
            $html = view('documents/einvoice', ['einvoice' => $print, 'variant' => $variant, 'alignStart' => '', 'alignEnd' => '']);

            $this->assertStringContainsString('ضريبة القيمة المضافة', $html, $variant);
            $this->assertStringContainsString('الإجمالي شامل الضريبة', $html, $variant);
            $this->assertStringContainsString($document->taxInclusive, $html, $variant);

            foreach ($document->lines as $line) {
                $this->assertStringContainsString($line->vatAmount, $html, $variant . ': the VAT of the line');
                $this->assertStringContainsString($line->amountWithVat, $html, $variant . ': the line with its VAT');
            }
        }
    }

    public function testEinvoiceWords_PutsTheLanguageOfTheReaderAfterTheArabic(): void
    {
        $language = service('language');
        $active   = $language->getLocale();

        try {
            $language->setLocale('en');
            $this->assertSame('فاتورة ضريبية مبسطة / Simplified Tax Invoice', einvoiceWords('Documents.einvoice_title_simplified'));
            $this->assertSame('en', $language->getLocale(), 'the language of the reader is kept');

            $language->setLocale('ar-EG');
            $this->assertSame('فاتورة ضريبية مبسطة', einvoiceWords('Documents.einvoice_title_simplified'), 'an Arabic reader reads it once');
        } finally {
            $language->setLocale($active);
        }
    }
}
