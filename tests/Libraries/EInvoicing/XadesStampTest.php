<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\UblInvoiceXml;
use App\Libraries\EInvoicing\XadesStamp;
use CodeIgniter\Test\CIUnitTestCase;
use DOMDocument;
use Tests\Support\EInvoiceFixtureTrait;

class XadesStampTest extends CIUnitTestCase
{
    use EInvoiceFixtureTrait;

    private function extensions(string $time = '2022-08-17T14:41:08Z', string $properties = 'PROPERTIES-DIGEST'): string
    {
        return (new XadesStamp())->extensions('DOCUMENT-HASH', 'SIGNATURE-VALUE', 'CERTIFICATE-BASE64', $time, 'CERTIFICATE-DIGEST', 'CN=Issuer,O=Org', '123456789', $properties);
    }

    private function canonical(string $xml): string
    {
        $document                     = new DOMDocument();
        $document->preserveWhiteSpace = false;
        $document->loadXML($xml);

        return (string) $document->C14N();
    }

    public function testExtensions_MatchesTheGoldenStructureOfTheStamp(): void
    {
        $wrapped = '<root xmlns:cbc="' . UblInvoiceXml::NS_CBC . '" xmlns:ext="' . UblInvoiceXml::NS_EXT . '">' . $this->extensions() . '</root>';

        $this->assertSame($this->canonical((string) file_get_contents(__DIR__ . '/../../Support/Golden/einvoice/stamp_extensions.xml')), $this->canonical($wrapped));
    }

    public function testExtensions_NamesTheThreeTransformsThatLeaveTheStampTheSignatureAndTheCodeOutOfTheHash(): void
    {
        $extensions = $this->extensions();

        $this->assertStringContainsString('not(//ancestor-or-self::ext:UBLExtensions)', $extensions);
        $this->assertStringContainsString('not(//ancestor-or-self::cac:Signature)', $extensions);
        $this->assertStringContainsString("not(//ancestor-or-self::cac:AdditionalDocumentReference[cbc:ID='QR'])", $extensions);
        $this->assertStringContainsString('http://www.w3.org/2001/04/xmldsig-more#ecdsa-sha256', $extensions);
        $this->assertStringContainsString('http://www.w3.org/2001/04/xmlenc#sha256', $extensions);
    }

    public function testCertificateDigest_IsTheBase64OfTheHexadecimalSha256OfTheCertificateText(): void
    {
        $this->assertSame(base64_encode(hash('sha256', 'CERTIFICATE-BASE64')), (new XadesStamp())->certificateDigest('CERTIFICATE-BASE64'));
        $this->assertSame(88, strlen((new XadesStamp())->certificateDigest('CERTIFICATE-BASE64')));
    }

    public function testPropertiesDigest_ChangesWithTheSigningTimeAndNotWithWhatIsOutsideTheProperties(): void
    {
        $xml    = new UblInvoiceXml();
        $stamp  = new XadesStamp();
        $first  = $stamp->propertiesDigest($xml->build($this->simplifiedDocument(), 'QR-TEXT', $this->extensions()));
        $second = $stamp->propertiesDigest($xml->build($this->simplifiedDocument(), 'ANOTHER-QR', $this->extensions('2022-08-17T14:41:08Z', 'ANOTHER-PROPERTIES-DIGEST')));
        $later  = $stamp->propertiesDigest($xml->build($this->simplifiedDocument(), 'QR-TEXT', $this->extensions('2022-08-17T14:41:09Z')));

        $this->assertSame($first, $second);
        $this->assertNotSame($first, $later);
        $this->assertSame(88, strlen($first));
    }

    public function testPropertiesDigest_MatchesTheDigestTheOfficialValidatorWorksOut(): void
    {
        $xml   = new UblInvoiceXml();
        $stamp = new XadesStamp();
        $plain = $stamp->extensions('DOCUMENT-HASH', 'SIGNATURE-VALUE', 'CERTIFICATE-BASE64', '2022-08-17T14:41:08Z', 'CERTIFICATE-DIGEST', 'CN=Issuer, O=Org', '123456789', 'PROPERTIES-DIGEST');
        $named = $stamp->extensions('DOCUMENT-HASH', 'SIGNATURE-VALUE', 'CERTIFICATE-BASE64', '2022-08-17T14:41:08Z', 'CERTIFICATE-DIGEST', 'CN=A & B <x>, O="Org"', '123456789', 'PROPERTIES-DIGEST');

        $this->assertSame('NzJjYzhmOTJjMzJlNGZlMWU2NWNjMjk5ZDc5YTEyNGY5NmQ4MWY2OTE3OGVhMjVhOTcwZmQ5OGE2ZDI4NTBiOA==', $stamp->propertiesDigest($xml->build($this->simplifiedDocument(), 'QR-TEXT', $plain)));
        $this->assertSame('MTNiZmMyZmJlNjEyYmQ2MTc3NmMyNzQ5NmFlOTExZjlmNDMyZjQwZTM0OWNkMzViYmJlYjJhNmNlMzBhYmY5OQ==', $stamp->propertiesDigest($xml->build($this->simplifiedDocument(), 'QR-TEXT', $named)));
    }

    public function testPropertiesDigest_RejectsAFileThatHoldsNoSignedProperties(): void
    {
        try {
            (new XadesStamp())->propertiesDigest((new UblInvoiceXml())->build($this->simplifiedDocument(), 'QR-TEXT'));
            $this->fail('A digest was given for a file without properties.');
        } catch (EInvoiceException $failure) {
            $this->assertSame(EInvoiceError::SigningFailed, $failure->error);
        }
    }

    public function testExtensions_EscapesWhatTheCertificateNamesCarry(): void
    {
        $extensions = (new XadesStamp())->extensions('H', 'S', 'C', '2022-08-17T14:41:08Z', 'D', 'CN=A & B <x>', '1', 'P');

        $this->assertStringContainsString('CN=A &amp; B &lt;x&gt;', $extensions);
    }
}
