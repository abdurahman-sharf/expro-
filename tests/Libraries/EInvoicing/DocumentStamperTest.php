<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\DocumentStamper;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\InvoiceHash;
use App\Libraries\EInvoicing\StampKeys;
use App\Libraries\EInvoicing\StampMaterial;
use App\Libraries\EInvoicing\StampVerifier;
use App\Libraries\EInvoicing\UblInvoiceXml;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EInvoiceFixtureTrait;

class DocumentStamperTest extends CIUnitTestCase
{
    use EInvoiceFixtureTrait;

    private DocumentStamper $stamper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutTheCurve();

        $this->stamper = new DocumentStamper();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();

        parent::tearDown();
    }

    /**
     * @return list<array{int, string}>
     */
    private function tags(string $qr): array
    {
        $bytes  = (string) base64_decode($qr, true);
        $fields = [];
        $offset = 0;

        while ($offset < strlen($bytes)) {
            $length   = ord($bytes[$offset + 1]);
            $fields[] = [ord($bytes[$offset]), substr($bytes, $offset + 2, $length)];
            $offset  += 2 + $length;
        }

        return $fields;
    }

    private function material(): StampMaterial
    {
        $pair = $this->generateKeyPair();

        return StampKeys::load($pair['key'], $pair['certificate']);
    }

    public function testUnsigned_WritesTheCodeOfTheFirstPhaseAndTheHashOfTheFile(): void
    {
        $stamped = $this->stamper->unsigned($this->simplifiedDocument());

        $this->assertFalse($stamped->signed);
        $this->assertSame([1, 2, 3, 4, 5], array_column($this->tags($stamped->qr), 0));
        $this->assertSame(InvoiceHash::of($stamped->xml), $stamped->hash);
        $this->assertStringNotContainsString('UBLExtensions', $stamped->xml);
    }

    public function testSigned_ProducesAFileWhoseStampVerifies(): void
    {
        $stamped = $this->stamper->signed($this->simplifiedDocument(), $this->material(), '2022-08-17T14:41:08Z');

        $this->assertTrue($stamped->signed);
        $this->assertSame(['signed' => true, 'document' => true, 'properties' => true, 'signature' => true], (new StampVerifier())->verify($stamped->xml));
    }

    public function testSigned_KeepsTheHashOfTheFileBeforeItWasStamped(): void
    {
        $document  = $this->simplifiedDocument();
        $unstamped = (new UblInvoiceXml())->build($document, $this->stamper->unsigned($document)->qr);
        $stamped   = $this->stamper->signed($document, $this->material(), '2022-08-17T14:41:08Z');

        $this->assertSame(InvoiceHash::of($unstamped), $stamped->hash);
        $this->assertSame(InvoiceHash::of($stamped->xml), $stamped->hash);
    }

    public function testSigned_WritesTheNineTagsOfTheCodeOfASimplifiedInvoice(): void
    {
        $material = $this->material();
        $stamped  = $this->stamper->signed($this->simplifiedDocument(), $material, '2022-08-17T14:41:08Z');
        $tags     = $this->tags($stamped->qr);

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], array_column($tags, 0));
        $this->assertSame('Maximum Speed Tech Supply LTD', $tags[0][1]);
        $this->assertSame('399999999900003', $tags[1][1]);
        $this->assertSame('2022-08-17T17:41:08Z', $tags[2][1]);
        $this->assertSame('24.15', $tags[3][1]);
        $this->assertSame('3.15', $tags[4][1]);
        $this->assertSame($stamped->hash, $tags[5][1]);
        $this->assertSame($stamped->signature, $tags[6][1]);
        $this->assertSame($material->publicKeyDer, $tags[7][1]);
        $this->assertSame($material->certificateSignatureDer, $tags[8][1]);
        $this->assertSame(88, strlen($tags[7][1]));
    }

    public function testSigned_LeavesTheSignatureOfTheCertificateOutOfTheCodeOfATaxInvoice(): void
    {
        $document = $this->amended($this->simplifiedDocument(), ['subtype' => EInvoiceSubtype::Standard]);
        $stamped  = $this->stamper->signed($document, $this->material(), '2022-08-17T14:41:08Z');

        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], array_column($this->tags($stamped->qr), 0));
    }

    public function testSigned_KeepsTheCodeWithinTheSevenHundredCharactersOfTheStandard(): void
    {
        $stamped = $this->stamper->signed($this->simplifiedDocument(), $this->material(), '2022-08-17T14:41:08Z');

        $this->assertLessThanOrEqual(700, strlen($stamped->qr));
    }

    public function testSigned_PutsTheCodeInTheReferenceThatTheHashLeavesOut(): void
    {
        $stamped = $this->stamper->signed($this->simplifiedDocument(), $this->material(), '2022-08-17T14:41:08Z');

        $this->assertStringContainsString('<cbc:EmbeddedDocumentBinaryObject mimeCode="text/plain">' . $stamped->qr . '</cbc:EmbeddedDocumentBinaryObject>', $stamped->xml);
        $this->assertStringContainsString('<cac:Signature><cbc:ID>urn:oasis:names:specification:ubl:signature:Invoice</cbc:ID><cbc:SignatureMethod>urn:oasis:names:specification:ubl:dsig:enveloped:xades</cbc:SignatureMethod></cac:Signature>', $stamped->xml);
        $this->assertStringContainsString('<cbc:ID>urn:oasis:names:specification:ubl:signature:1</cbc:ID>', $stamped->xml);
    }

    public function testSigned_GivesADifferentSignatureEachTimeAndTheSameHash(): void
    {
        $material = $this->material();
        $first    = $this->stamper->signed($this->simplifiedDocument(), $material, '2022-08-17T14:41:08Z');
        $second   = $this->stamper->signed($this->simplifiedDocument(), $material, '2022-08-17T14:41:08Z');

        $this->assertSame($first->hash, $second->hash);
        $this->assertTrue((new StampVerifier())->verify($second->xml)['signature']);
    }

    public function testSigned_NamesTheCertificateByItsDigestItsIssuerAndItsSerial(): void
    {
        $material = $this->material();
        $stamped  = $this->stamper->signed($this->simplifiedDocument(), $material, '2022-08-17T14:41:08Z');

        $this->assertStringContainsString('<xades:SigningTime>2022-08-17T14:41:08Z</xades:SigningTime>', $stamped->xml);
        $this->assertStringContainsString('<ds:DigestValue>' . base64_encode(hash('sha256', $material->certificateBase64)) . '</ds:DigestValue>', $stamped->xml);
        $this->assertStringContainsString('<ds:X509IssuerName>' . htmlspecialchars($material->issuerName, ENT_XML1) . '</ds:X509IssuerName>', $stamped->xml);
        $this->assertStringContainsString('<ds:X509SerialNumber>' . $material->serialNumber . '</ds:X509SerialNumber>', $stamped->xml);
        $this->assertStringContainsString('<ds:X509Certificate>' . $material->certificateBase64 . '</ds:X509Certificate>', $stamped->xml);
    }
}
