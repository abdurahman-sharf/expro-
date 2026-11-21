<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceDocument;
use App\Libraries\EInvoicing\EInvoiceKind;
use App\Libraries\EInvoicing\EInvoiceLine;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\UblInvoiceXml;
use CodeIgniter\Test\CIUnitTestCase;
use DOMDocument;
use DOMXPath;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\EInvoiceFixtureTrait;

/**
 * The UBL file of a document, held against samples written out by hand from the structure the standard
 * publishes. The samples are a candidate: the official toolbox has not validated them.
 */
class UblInvoiceXmlTest extends CIUnitTestCase
{
    use EInvoiceFixtureTrait;

    private const GOLDEN = __DIR__ . '/../../Support/Golden/einvoice/';

    private UblInvoiceXml $xml;

    protected function setUp(): void
    {
        parent::setUp();

        $this->xml = new UblInvoiceXml();
    }

    private function canonical(string $xml): string
    {
        $document                     = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->loadXML($xml);

        return (string) $document->C14N();
    }

    public function testBuild_MatchesTheGoldenSampleOfASimplifiedInvoice(): void
    {
        $built = $this->xml->build($this->simplifiedDocument(), 'QR-TEXT');

        $this->assertSame($this->canonical((string) file_get_contents(self::GOLDEN . 'simplified_invoice.xml')), $this->canonical($built));
    }

    public function testBuild_MatchesTheGoldenSampleOfAStandardCreditNote(): void
    {
        $built = $this->xml->build($this->creditNoteDocument(), 'QR-TEXT');

        $this->assertSame($this->canonical((string) file_get_contents(self::GOLDEN . 'standard_credit_note.xml')), $this->canonical($built));
    }

    public function testBuild_WritesNoWhitespaceBetweenElements(): void
    {
        $built = $this->xml->build($this->simplifiedDocument(), 'QR-TEXT');

        $this->assertSame(0, substr_count($built, "\n"));
        $this->assertSame(0, preg_match('/>\s+</', $built));
    }

    public function testBuild_LeavesNoElementEmpty(): void
    {
        $document                     = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->loadXML($this->xml->build($this->creditNoteDocument(), 'QR-TEXT'));

        $this->assertSame(0, (new DOMXPath($document))->query('//*[not(*) and not(normalize-space())]')->length);
    }

    public function testBuild_WritesTheIssueTimeAsLocalTimeWithoutAZone(): void
    {
        $built = $this->xml->build($this->simplifiedDocument(), 'QR-TEXT');

        $this->assertStringContainsString('<cbc:IssueTime>17:41:08</cbc:IssueTime>', $built);
    }

    public function testBuild_WritesTheTwoTaxTotalsTheStandardAsksFor(): void
    {
        $document                     = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->loadXML($this->xml->build($this->simplifiedDocument(), 'QR-TEXT'));
        $xpath = new DOMXPath($document);

        $xpath->registerNamespace('cac', UblInvoiceXml::NS_CAC);

        $this->assertSame(1, $xpath->query('/*/cac:TaxTotal[not(cac:TaxSubtotal)]')->length);
        $this->assertSame(1, $xpath->query('/*/cac:TaxTotal[cac:TaxSubtotal]')->length);
    }

    public function testBuild_WritesTheRoundingAmountAndThePayableAmountItLeaves(): void
    {
        $sample   = $this->simplifiedDocument();
        $rounded  = new EInvoiceDocument($sample->kind, $sample->subtype, $sample->number, $sample->issueDate, $sample->issueTime, $sample->supplyDate, $sample->seller, $sample->buyer, $sample->lines, $sample->taxSubtotals, $sample->lineExtension, $sample->taxExclusive, $sample->taxAmount, $sample->taxInclusive, $sample->paymentMeansCodes, uuid: $sample->uuid, icv: $sample->icv, previousHash: $sample->previousHash, rounding: '0.01');
        $document = new DOMDocument();
        $document->loadXML($this->xml->build($rounded, 'QR-TEXT'));
        $xpath = new DOMXPath($document);

        $xpath->registerNamespace('cac', UblInvoiceXml::NS_CAC);
        $xpath->registerNamespace('cbc', UblInvoiceXml::NS_CBC);

        $this->assertSame('24.15', $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:TaxInclusiveAmount)'));
        $this->assertSame('0.01', $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableRoundingAmount)'));
        $this->assertSame('24.16', $xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount)'));
        $this->assertSame('cbc:PayableAmount', $xpath->query('//cac:LegalMonetaryTotal/cbc:PayableRoundingAmount/following-sibling::*')->item(0)->nodeName);
        $this->assertStringNotContainsString('PayableRoundingAmount', $this->xml->build($sample, 'QR-TEXT'), 'a document without rounding writes none');
    }

    #[DataProvider('typeCodes')]
    public function testBuild_WritesTheTypeCodeAndTheSubtypeOfEachKind(EInvoiceKind $kind, EInvoiceSubtype $subtype, string $code, string $name): void
    {
        $copy = $this->amended($this->creditNoteDocument(), ['kind' => $kind, 'subtype' => $subtype]);

        $this->assertStringContainsString('<cbc:InvoiceTypeCode name="' . $name . '">' . $code . '</cbc:InvoiceTypeCode>', $this->xml->build($copy, 'QR-TEXT'));
    }

    /**
     * @return array<string, array{EInvoiceKind, EInvoiceSubtype, string, string}>
     */
    public static function typeCodes(): array
    {
        return [
            'tax invoice'              => [EInvoiceKind::Invoice, EInvoiceSubtype::Standard, '388', '0100000'],
            'simplified tax invoice'   => [EInvoiceKind::Invoice, EInvoiceSubtype::Simplified, '388', '0200000'],
            'tax credit note'          => [EInvoiceKind::CreditNote, EInvoiceSubtype::Standard, '381', '0100000'],
            'simplified credit note'   => [EInvoiceKind::CreditNote, EInvoiceSubtype::Simplified, '381', '0200000'],
            'tax debit note'           => [EInvoiceKind::DebitNote, EInvoiceSubtype::Standard, '383', '0100000'],
            'simplified debit note'    => [EInvoiceKind::DebitNote, EInvoiceSubtype::Simplified, '383', '0200000'],
        ];
    }

    public function testBuild_EscapesTheTextOfAName(): void
    {
        $base  = $this->simplifiedDocument();
        $named = new EInvoiceLine(1, 'Tea & <coffee> "set" شاي', '1.00', 'PCE', '1.0000', '0.00', '1.00', 'S', '15.00', '0.15', '1.15');
        $copy  = $this->amended($base, ['lines' => [$named, ...array_slice($base->lines, 1)]]);
        $built = $this->xml->build($copy, 'QR-TEXT');

        $this->assertStringContainsString('<cbc:Name>Tea &amp; &lt;coffee&gt; "set" شاي</cbc:Name>', $built);
        $this->assertNotFalse((new DOMDocument())->loadXML($built));
    }

    public function testEscape_StripsACharacterXmlDoesNotAllow(): void
    {
        $this->assertSame('ab', UblInvoiceXml::escape("a\x00\x0bb"));
    }

    public function testEscape_EscapesQuotesInAnAttributeAndLeavesThemInText(): void
    {
        $this->assertSame('say &quot;hi&quot;', UblInvoiceXml::escape('say "hi"', true));
        $this->assertSame('say "hi"', UblInvoiceXml::escape('say "hi"'));
    }

    public function testBuild_RefusesADocumentThatHasNoCounterAndNoChain(): void
    {
        $bare = $this->amended($this->simplifiedDocument(), ['uuid' => '', 'icv' => 0, 'previousHash' => '']);

        $this->expectException(LogicException::class);

        $this->xml->build($bare, 'QR-TEXT');
    }

    public function testBuild_GivesTheSameBytesForTheSameDocument(): void
    {
        $this->assertSame($this->xml->build($this->simplifiedDocument(), 'QR-TEXT'), $this->xml->build($this->simplifiedDocument(), 'QR-TEXT'));
    }
}
