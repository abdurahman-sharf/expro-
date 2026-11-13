<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\InvoiceHash;
use App\Libraries\EInvoicing\UblInvoiceXml;
use App\Libraries\EInvoicing\XadesStamp;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EInvoiceFixtureTrait;

class InvoiceHashTest extends CIUnitTestCase
{
    use EInvoiceFixtureTrait;

    private function stamped(): string
    {
        $extensions = (new XadesStamp())->extensions('DOCUMENT-HASH', 'SIGNATURE', 'CERTIFICATE', '2022-08-17T14:41:08Z', 'CERT-DIGEST', 'CN=Issuer', '12345', 'PROPERTIES-HASH');

        return (new UblInvoiceXml())->build($this->simplifiedDocument(), 'A-DIFFERENT-QR-TEXT', $extensions);
    }

    public function testFirst_IsTheBase64OfTheHexadecimalSha256OfZero(): void
    {
        $this->assertSame(base64_encode(hash('sha256', '0')), InvoiceHash::FIRST);
        $this->assertSame('NWZlY2ViNjZmZmM4NmYzOGQ5NTI3ODZjNmQ2OTZjNzljMmRiYzIzOWRkNGU5MWI0NjcyOWQ3M2EyN2ZiNTdlOQ==', InvoiceHash::FIRST);
    }

    public function testOf_IsTheBase64OfThe32BytesOfTheHash(): void
    {
        $hash = InvoiceHash::of((new UblInvoiceXml())->build($this->simplifiedDocument(), 'QR-TEXT'));

        $this->assertSame(44, strlen($hash));
        $this->assertSame(32, strlen((string) base64_decode($hash, true)));
    }

    public function testOf_IgnoresTheExtensionsTheSignatureAndTheCode(): void
    {
        $bare = (new UblInvoiceXml())->build($this->simplifiedDocument(), 'QR-TEXT');

        $this->assertSame(InvoiceHash::of($bare), InvoiceHash::of($this->stamped()));
    }

    public function testOf_ChangesWhenAnAmountChanges(): void
    {
        $xml     = new UblInvoiceXml();
        $changed = $this->amended($this->simplifiedDocument(), ['taxInclusive' => '24.16']);

        $this->assertNotSame(InvoiceHash::of($xml->build($this->simplifiedDocument(), 'QR-TEXT')), InvoiceHash::of($xml->build($changed, 'QR-TEXT')));
    }

    public function testOf_ChangesWhenTheCounterChanges(): void
    {
        $xml = new UblInvoiceXml();

        $this->assertNotSame(InvoiceHash::of($xml->build($this->simplifiedDocument(), 'QR-TEXT')), InvoiceHash::of($xml->build($this->amended($this->simplifiedDocument(), ['icv' => 11]), 'QR-TEXT')));
    }

    public function testOf_GivesTheSameHashForTheGoldenSamplesAsWhenTheyWereFirstWrittenDown(): void
    {
        $xml = new UblInvoiceXml();

        $this->assertSame('neq5gp7Skzhzrjpicf/zLlSRzbXD54QNO4zq6PC5+pM=', InvoiceHash::of($xml->build($this->simplifiedDocument(), 'QR-TEXT')));
        $this->assertSame('dl+Xk3EmnCRA3u062eT6K3zQDxijIPsePLv0junn1qM=', InvoiceHash::of($xml->build($this->creditNoteDocument(), 'QR-TEXT')));
    }

    public function testCanonical_LeavesTheNamespaceDeclarationsOfTheRootInPlaceWhenTheExtensionsAreTakenOut(): void
    {
        $canonical = InvoiceHash::canonical($this->stamped());

        $this->assertStringContainsString('xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2"', $canonical);
        $this->assertStringNotContainsString('UBLExtensions', $canonical);
        $this->assertStringNotContainsString('cac:Signature', $canonical);
        $this->assertStringNotContainsString('A-DIFFERENT-QR-TEXT', $canonical);
        $this->assertStringContainsString('NWZlY2Vi', $canonical);
    }

    public function testCanonical_KeepsTheDocumentReferencesThatAreNotTheCode(): void
    {
        $canonical = InvoiceHash::canonical($this->stamped());

        $this->assertStringContainsString('<cbc:ID>ICV</cbc:ID>', $canonical);
        $this->assertStringContainsString('<cbc:ID>PIH</cbc:ID>', $canonical);
        $this->assertStringNotContainsString('<cbc:ID>QR</cbc:ID>', $canonical);
    }

    public function testLoad_RejectsAFileThatIsNotWellFormed(): void
    {
        try {
            InvoiceHash::canonical('<Invoice><cbc:ID></Invoice>');
            $this->fail('A malformed file was hashed.');
        } catch (EInvoiceException $failure) {
            $this->assertSame(EInvoiceError::RuleBroken, $failure->error);
        }
    }

    public function testLoad_RejectsAnAttributeOfTheXmlNamespaceBecauseVersionOnePointOneOfTheCanonicalizationIsNotAvailable(): void
    {
        $xml = (new UblInvoiceXml())->build($this->simplifiedDocument(), 'QR-TEXT');

        try {
            InvoiceHash::canonical(str_replace('<cbc:ID>SME00010', '<cbc:ID xml:lang="ar">SME00010', $xml));
            $this->fail('A file with an xml:lang attribute was hashed.');
        } catch (EInvoiceException $failure) {
            $this->assertSame(EInvoiceError::RuleBroken, $failure->error);
            $this->assertSame('C14N11', $failure->context['rule']);
        }
    }
}
