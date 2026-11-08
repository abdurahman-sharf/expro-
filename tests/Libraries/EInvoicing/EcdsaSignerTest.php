<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\EcdsaSigner;
use App\Libraries\EInvoicing\StampKeys;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EInvoiceFixtureTrait;

class EcdsaSignerTest extends CIUnitTestCase
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

    public function testSign_GivesASignatureThatTheKeyOfTheCertificateVerifies(): void
    {
        $pair     = $this->generateKeyPair();
        $material = StampKeys::load($pair['key'], $pair['certificate']);
        $signer   = new EcdsaSigner();
        $hash     = hash('sha256', 'a document', true);

        $signature = $signer->sign($hash, $material);

        $this->assertSame("\x30", $signature[0]);
        $this->assertTrue($signer->verify($hash, $signature, $material->publicKeyDer));
    }

    public function testVerify_RejectsAnotherHashAnotherKeyAndAChangedSignature(): void
    {
        $pair     = $this->generateKeyPair();
        $other    = $this->generateKeyPair('secp256k1', $this->temporaryDirectory());
        $material = StampKeys::load($pair['key'], $pair['certificate']);
        $stranger = StampKeys::load($other['key'], $other['certificate']);
        $signer   = new EcdsaSigner();
        $hash     = hash('sha256', 'a document', true);

        $signature = $signer->sign($hash, $material);
        $changed   = $signature;
        $changed[10] = $changed[10] === "\x01" ? "\x02" : "\x01";

        $this->assertFalse($signer->verify(hash('sha256', 'another document', true), $signature, $material->publicKeyDer));
        $this->assertFalse($signer->verify($hash, $signature, $stranger->publicKeyDer));
        $this->assertFalse($signer->verify($hash, $changed, $material->publicKeyDer));
    }

    public function testSign_SignsTheBytesOfTheHashWithSha256AsTheOpensslCommandDoesForAFileThatHoldsThem(): void
    {
        $pair     = $this->generateKeyPair();
        $material = StampKeys::load($pair['key'], $pair['certificate']);
        $hash     = hash('sha256', 'a document', true);
        $signature = (new EcdsaSigner())->sign($hash, $material);

        $details = openssl_pkey_get_details(openssl_pkey_get_public(file_get_contents($pair['certificate'])));

        $this->assertSame(1, openssl_verify($hash, $signature, $details['key'], OPENSSL_ALGO_SHA256));
        $this->assertNotSame(1, openssl_verify(bin2hex($hash), $signature, $details['key'], OPENSSL_ALGO_SHA256));
    }
}
