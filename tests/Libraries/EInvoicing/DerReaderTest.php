<?php

namespace Tests\Libraries\EInvoicing;

use App\Libraries\EInvoicing\DerReader;
use App\Libraries\EInvoicing\EInvoiceError;
use App\Libraries\EInvoicing\EInvoiceException;
use CodeIgniter\Test\CIUnitTestCase;

class DerReaderTest extends CIUnitTestCase
{
    private function element(int $tag, string $content): string
    {
        $length = strlen($content);

        if ($length < 0x80) {
            return chr($tag) . chr($length) . $content;
        }

        $bytes = ltrim(pack('N', $length), "\0");

        return chr($tag) . chr(0x80 | strlen($bytes)) . $bytes . $content;
    }

    private function certificate(string $signature, string $tbs = 'to be signed'): string
    {
        return $this->element(0x30, $this->element(0x30, $tbs) . $this->element(0x30, 'algorithm') . $this->element(0x03, "\x00" . $signature));
    }

    public function testCertificateSignature_ReturnsTheBitsOfTheLastPartWithoutTheCountOfUnusedBits(): void
    {
        $signature = "\x30\x46\x02\x21\x00" . random_bytes(32);

        $this->assertSame($signature, DerReader::certificateSignature($this->certificate($signature)));
    }

    public function testCertificateSignature_ReadsLengthsWrittenInTheLongForm(): void
    {
        $signature = random_bytes(72);

        $this->assertSame($signature, DerReader::certificateSignature($this->certificate($signature, str_repeat('t', 700))));
        $this->assertSame($signature, DerReader::certificateSignature($this->certificate($signature, str_repeat('t', 70000))));
    }

    public function testCertificateSignature_RejectsWhatIsNotACertificateSequence(): void
    {
        $this->expectException(EInvoiceException::class);

        DerReader::certificateSignature($this->element(0x04, 'text'));
    }

    public function testCertificateSignature_RejectsACertificateThatEndsBeforeItsSignature(): void
    {
        try {
            DerReader::certificateSignature($this->element(0x30, $this->element(0x30, 'tbs') . $this->element(0x30, 'algorithm')));
            $this->fail('A certificate without a signature was read.');
        } catch (EInvoiceException $failure) {
            $this->assertSame(EInvoiceError::CertificateUnreadable, $failure->error);
        }
    }

    public function testCertificateSignature_RejectsALastPartThatIsNotABitString(): void
    {
        $this->expectException(EInvoiceException::class);

        DerReader::certificateSignature($this->element(0x30, $this->element(0x30, 'tbs') . $this->element(0x30, 'algorithm') . $this->element(0x04, 'octets')));
    }

    public function testCertificateSignature_RejectsALengthThatRunsPastTheEnd(): void
    {
        $this->expectException(EInvoiceException::class);

        DerReader::certificateSignature("\x30\x82\xff\xff" . 'short');
    }
}
