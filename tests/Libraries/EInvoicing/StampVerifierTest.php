<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\DocumentStamper;
use App\Libraries\EInvoicing\StampKeys;
use App\Libraries\EInvoicing\StampVerifier;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\EInvoiceFixtureTrait;

class StampVerifierTest extends CIUnitTestCase
{
    use EInvoiceFixtureTrait;

    private string $xml;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipWithoutTheCurve();

        $pair      = $this->generateKeyPair();
        $this->xml = (new DocumentStamper())->signed($this->simplifiedDocument(), StampKeys::load($pair['key'], $pair['certificate']), '2022-08-17T14:41:08Z')->xml;
    }

    protected function tearDown(): void
    {
        $this->removeTemporaryDirectories();

        parent::tearDown();
    }

    public function testVerify_FailsTheDocumentDigestWhenAnAmountWasChangedAfterSigning(): void
    {
        $result = (new StampVerifier())->verify(str_replace('<cbc:LineExtensionAmount currencyID="SAR">21.00', '<cbc:LineExtensionAmount currencyID="SAR">22.00', $this->xml));

        $this->assertTrue($result['signed']);
        $this->assertFalse($result['document']);
        $this->assertFalse($result['signature']);
    }

    public function testVerify_FailsTheSignatureWhenTheSignatureValueWasChanged(): void
    {
        preg_match('#<ds:SignatureValue>([^<]+)</ds:SignatureValue>#', $this->xml, $found);

        $other = $found[1][10] === 'A' ? 'B' : 'A';
        $bad   = str_replace('<ds:SignatureValue>' . $found[1], '<ds:SignatureValue>' . substr_replace($found[1], $other, 10, 1), $this->xml);
        $result = (new StampVerifier())->verify($bad);

        $this->assertTrue($result['document']);
        $this->assertFalse($result['signature']);
    }

    public function testVerify_FailsThePropertiesDigestWhenTheSigningTimeWasChanged(): void
    {
        $result = (new StampVerifier())->verify(str_replace('2022-08-17T14:41:08Z', '2022-08-17T14:41:09Z', $this->xml));

        $this->assertTrue($result['document']);
        $this->assertFalse($result['properties']);
    }

    public function testVerify_SaysAFileWithoutAStampIsNotSignedInsteadOfThrowing(): void
    {
        $result = (new StampVerifier())->verify((new DocumentStamper())->unsigned($this->simplifiedDocument())->xml);

        $this->assertSame(['signed' => false, 'document' => false, 'properties' => false, 'signature' => false], $result);
    }

    public function testVerify_SaysAFileThatIsNotXmlIsNotSigned(): void
    {
        $this->assertFalse((new StampVerifier())->verify('not xml')['signed']);
    }
}
