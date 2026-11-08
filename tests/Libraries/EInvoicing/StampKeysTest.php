<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use App\Libraries\EInvoicing\StampKeys;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EInvoiceFixtureTrait;

class StampKeysTest extends CIUnitTestCase
{
    use EInvoiceFixtureTrait;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutTheCurve();
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();

        parent::tearDown();
    }

    private function refusal(string $key, string $certificate): EInvoiceException
    {
        try {
            StampKeys::load($key, $certificate);
        } catch (EInvoiceException $failure) {
            return $failure;
        }

        $this->fail('The key was accepted.');
    }

    public function testCurveAvailable_SaysWhetherTheInstalledOpensslOffersTheCurveOfTheStandard(): void
    {
        $this->assertTrue(StampKeys::curveAvailable());
    }

    public function testLoad_ReadsTheKeyTheCertificateAndWhatTheCodeNeedsOfThem(): void
    {
        $pair     = $this->generateKeyPair();
        $material = StampKeys::load($pair['key'], $pair['certificate']);

        $this->assertSame(88, strlen($material->publicKeyDer));
        $this->assertSame("\x30\x56", substr($material->publicKeyDer, 0, 2));
        $this->assertGreaterThan(60, strlen($material->certificateSignatureDer));
        $this->assertSame("\x30", $material->certificateSignatureDer[0]);
        $this->assertStringContainsString('CN=Test EGS unit', $material->issuerName);
        $this->assertStringContainsString('O=Test taxpayer', $material->issuerName);
        $this->assertStringEndsWith('CN=Test EGS unit', $material->issuerName);
        $this->assertMatchesRegularExpression('/^\d+$/', $material->serialNumber);
        $this->assertNotSame('', $material->certificateBase64);
        $this->assertStringNotContainsString("\n", $material->certificateBase64);
    }

    public function testLoad_RejectsAKeyInsideTheApplicationDirectoryWhateverItHolds(): void
    {
        $pair = $this->generateKeyPair();

        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal(ROOTPATH . 'composer.json', $pair['certificate'])->error);
        $this->assertSame(EInvoiceError::KeyPathUnsafe, $this->refusal($pair['key'], WRITEPATH . 'cert.pem')->error);
    }

    public function testLoad_RejectsAKeyOnAnotherCurve(): void
    {
        $pair = $this->generateKeyPair('prime256v1');

        $this->assertSame(EInvoiceError::CurveUnsupported, $this->refusal($pair['key'], $pair['certificate'])->error);
    }

    public function testLoad_RejectsAKeyFileThatIsNotAKey(): void
    {
        $pair = $this->generateKeyPair();

        file_put_contents($pair['directory'] . '/not_a_key.pem', 'nothing');

        $this->assertSame(EInvoiceError::KeyUnreadable, $this->refusal($pair['directory'] . '/not_a_key.pem', $pair['certificate'])->error);
        $this->assertSame(EInvoiceError::KeyUnreadable, $this->refusal($pair['directory'] . '/missing.pem', $pair['certificate'])->error);
    }

    public function testLoad_RejectsACertificateFileThatIsNotACertificate(): void
    {
        $pair = $this->generateKeyPair();

        file_put_contents($pair['directory'] . '/not_a_cert.pem', 'nothing');

        $this->assertSame(EInvoiceError::CertificateUnreadable, $this->refusal($pair['key'], $pair['directory'] . '/not_a_cert.pem')->error);
        $this->assertSame(EInvoiceError::CertificateUnreadable, $this->refusal($pair['key'], $pair['directory'] . '/missing.pem')->error);
    }

    public function testLoad_RejectsACertificateThatIsNotTheOneOfTheKey(): void
    {
        $first  = $this->generateKeyPair();
        $second = $this->generateKeyPair('secp256k1', $this->temporaryDirectory());

        $this->assertSame(EInvoiceError::CertificateKeyMismatch, $this->refusal($first['key'], $second['certificate'])->error);
    }
}
