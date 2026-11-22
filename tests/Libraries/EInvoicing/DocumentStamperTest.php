<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\Documents\SimplifiedInvoiceQr;
use App\Libraries\EInvoicing\DocumentStamper;
use App\Libraries\EInvoicing\EInvoiceAddress;
use App\Libraries\EInvoicing\EInvoiceDocument;
use App\Libraries\EInvoicing\EcdsaSigner;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\EInvoiceParty;
use App\Libraries\EInvoicing\EInvoiceSubtype;
use App\Libraries\EInvoicing\InvoiceHash;
use App\Libraries\EInvoicing\StampedDocument;
use App\Libraries\EInvoicing\StampKeys;
use App\Libraries\EInvoicing\StampMaterial;
use App\Libraries\EInvoicing\StampVerifier;
use App\Libraries\EInvoicing\UblInvoiceXml;
use App\Libraries\EInvoicing\XadesStamp;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\EInvoiceMatrixTrait;

class DocumentStamperTest extends CIUnitTestCase
{
    use EInvoiceMatrixTrait;

    /**
     * The rules of 2021 in the public SDK (2.0.3), and what they refuse each sample for: rules the standard v1.2
     * dropped (BR-KSA-05, BR-KSA-50, BR-KSA-62, BR-KSA-F-02, BR-O-02, BR-O-05), and BR-S-10 and BR-KSA-51, which
     * that SDK works out over the whole file and in floating point (TAX2 T5 report).
     */
    private const LEGACY_RULES    = '20210819';
    private const LEGACY_REFUSALS = [
        'B-03' => ['BR-KSA-62'],
        'B-05' => ['BR-O-02', 'BR-O-05'],
        'B-07' => ['BR-KSA-F-02'],
        'B-08' => ['BR-KSA-05'],
        'S-01' => ['BR-KSA-50'],
        'S-02' => ['BR-S-10'],
        'S-04' => ['BR-KSA-50', 'BR-KSA-51'],
        'S-06' => ['BR-KSA-50'],
        'S-08' => ['BR-KSA-50'],
    ];

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

    public function testSigned_RefusesAFileWhoseHashIsNotTheHashThatWasSigned(): void
    {
        $stamp = new class () extends XadesStamp {
            public function extensions(string $documentHash, string $signatureValue, string $certificate, string $signingTime, string $certificateDigest, string $issuerName, string $serialNumber, string $propertiesDigest): string
            {
                return '<cbc:Note>not an extension, so the hash of the file moves</cbc:Note>';
            }

            public function propertiesDigest(string $xml): string
            {
                return 'digest';
            }
        };

        try {
            (new DocumentStamper(new UblInvoiceXml(), new SimplifiedInvoiceQr(), new EcdsaSigner(), $stamp))->signed($this->simplifiedDocument(), $this->material(), '2022-08-17T14:41:08Z');
            $this->fail('A file whose hash moved was stamped.');
        } catch (EInvoiceException $failure) {
            $this->assertSame(EInvoiceError::SigningFailed, $failure->error);
            $this->assertStringContainsString('not the hash that was signed', $failure->getMessage());
        }
    }

    /**
     * Signs every sample of the matrix (TAX2 §5.3), the chain in its order, and writes each file where the official
     * validator can read it, the directory it returns.
     */
    private function writeMatrix(): string
    {
        $directory = WRITEPATH . 'zatca-matrix';
        $material  = $this->material();
        $previous  = InvoiceHash::FIRST;

        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        foreach ($this->matrix() as $id => $document) {
            if (str_starts_with($id, 'C-01-')) {
                $document = $this->amended($document, ['previousHash' => $previous]);
            }

            $stamped = $this->stamper->signed($document, $material, '2022-08-17T14:41:08Z');

            if (str_starts_with($id, 'C-01-')) {
                $previous = $stamped->hash;
            }

            $this->assertSame(['signed' => true, 'document' => true, 'properties' => true, 'signature' => true], (new StampVerifier())->verify($stamped->xml), $id);
            file_put_contents($directory . '/' . $id . '.xml', $stamped->xml);
        }

        foreach ($this->brokenMatrix() as $id => $document) {
            file_put_contents($directory . '/' . $id . '.xml', $this->stamper->signed($document, $material, '2022-08-17T14:41:08Z')->xml);
        }

        return $directory;
    }

    public function testSigned_StampsEverySampleOfTheMatrixSoThatItVerifies(): void
    {
        $directory = $this->writeMatrix();

        $this->assertCount(count($this->matrix()) + count($this->brokenMatrix()), glob($directory . '/*.xml'));
    }

    #[Group('zatca-sdk')]
    public function testSigned_PassesTheOfficialValidatorForEverySampleOfTheMatrix(): void
    {
        $sdk    = (string) getenv('ZATCA_SDK_HOME');
        $config = is_file($sdk . '/Configuration/config.json') ? json_decode((string) file_get_contents($sdk . '/Configuration/config.json'), true) : null;

        if ($sdk === '' || ! is_file($sdk . '/Apps/fatoora')) {
            $this->markTestSkipped('The official SDK is not installed (D-09): set ZATCA_SDK_HOME to the folder it was unpacked in.');
        }

        if (! is_array($config) || ($config['xsdPath'] ?? '') === '') {
            $this->markTestSkipped('Run install.sh of the SDK once: it writes Configuration/config.json.');
        }

        $legacy    = str_contains((string) ($config['zatcaSchematron'] ?? ''), self::LEGACY_RULES);
        $directory = $this->temporaryDirectory();
        $pair      = $this->generateKeyPair();
        $material  = StampKeys::load($pair['key'], $pair['certificate']);
        $broken    = $this->brokenMatrix();
        $previous  = InvoiceHash::FIRST;
        $java      = trim(getenv('_JAVA_OPTIONS') . ($legacy ? ' -Djava.security.properties=' . $directory . '/java.security' : ''));

        file_put_contents($directory . '/java.security', 'security.provider.3=org.bouncycastle.jce.provider.BouncyCastleProvider' . "\n");
        file_put_contents($directory . '/certificate.txt', $material->certificateBase64);

        foreach (array_merge($this->matrix(), $broken) as $id => $document) {
            if ($legacy) {
                $document = $this->withThe2021Fields($document);
            }

            if (str_starts_with($id, 'C-01-')) {
                $document = $this->amended($document, ['previousHash' => $previous]);
            }

            $stamped = $this->stamper->signed($document, $material, '2022-08-17T14:41:08Z');
            $file    = $directory . '/' . $id . '.xml';

            file_put_contents($file, $legacy && $document->subtype === EInvoiceSubtype::Simplified ? $this->withThe2021Code($stamped) : $stamped->xml);
            file_put_contents($directory . '/' . $id . '.pih', $document->previousHash === '' ? InvoiceHash::FIRST : $document->previousHash);
            file_put_contents($directory . '/' . $id . '.json', json_encode(['certPath' => $directory . '/certificate.txt', 'pihPath' => $directory . '/' . $id . '.pih'] + $config, JSON_THROW_ON_ERROR));

            if (str_starts_with($id, 'C-01-')) {
                $previous = $stamped->hash;
            }

            $output = (string) shell_exec(
                'FATOORA_HOME=' . escapeshellarg($sdk . '/Apps') . ' SDK_CONFIG=' . escapeshellarg($directory . '/' . $id . '.json') . ' _JAVA_OPTIONS=' . escapeshellarg($java)
                . ' ' . escapeshellarg($sdk . '/Apps/fatoora') . ' -validate -invoice ' . escapeshellarg($file) . ' 2>&1',
            );
            preg_match_all('/CODE : ([^,]+), MESSAGE/', $output, $codes);
            $refusals = array_values(array_unique($codes[1]));
            sort($refusals);

            if (isset($broken[$id])) {
                $this->assertStringNotContainsString('GLOBAL VALIDATION RESULT = PASSED', $output, $id);
            } elseif ($legacy && isset(self::LEGACY_REFUSALS[$id])) {
                $this->assertSame(self::LEGACY_REFUSALS[$id], $refusals, $id . ': ' . $output);
            } else {
                $this->assertStringContainsString('GLOBAL VALIDATION RESULT = PASSED', $output, $id . ': ' . $output);
            }
        }
    }

    /**
     * The fields the rules of 2021 ask for and the standard v1.2 leaves optional: the additional number and the
     * province of each address, and a seller name short enough for the code to stay under the 500 characters those
     * rules allow (v1.2 allows 700).
     */
    private function withThe2021Fields(EInvoiceDocument $document): EInvoiceDocument
    {
        $party = static function (EInvoiceParty $party, string $name): EInvoiceParty {
            $address = $party->address === null ? null : new EInvoiceAddress(
                $party->address->street,
                $party->address->buildingNumber,
                $party->address->district,
                $party->address->city,
                $party->address->postalCode,
                $party->address->countryCode,
                $party->address->additionalStreet,
                '1234',
                'Riyadh Region',
            );

            return new EInvoiceParty($name, $party->vatNumber, $party->identificationType, $party->identificationNumber, $address);
        };

        return $this->amended($document, ['seller' => $party($document->seller, 'Expro Co'), 'buyer' => $party($document->buyer, $document->buyer->name)]);
    }

    /**
     * The code of a simplified document laid out as the validator of 2021 reads it: the public key in tag 7 and the
     * two halves of the signature in tags 8 and 9, where v1.2 puts the signature, the public key and the signature
     * of the certificate. The code is outside what is hashed and signed, so the stamp still holds.
     */
    private function withThe2021Code(StampedDocument $stamped): string
    {
        $tags      = array_column($this->tags($stamped->qr), 1, 0);
        $signature = (string) base64_decode($stamped->signature, true);
        $first     = ord($signature[3]);
        $fields    = [1 => $tags[1], 2 => $tags[2], 3 => $tags[3], 4 => $tags[4], 5 => $tags[5], 6 => $tags[6], 7 => $tags[8], 8 => substr($signature, 4, $first), 9 => substr($signature, 6 + $first, ord($signature[5 + $first]))];
        $code      = base64_encode(implode('', array_map(static fn (int $tag, string $value): string => chr($tag) . chr(strlen($value)) . $value, array_keys($fields), $fields)));

        return str_replace('>' . $stamped->qr . '<', '>' . $code . '<', $stamped->xml);
    }
}
