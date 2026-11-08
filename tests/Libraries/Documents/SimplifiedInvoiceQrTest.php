<?php

namespace Tests\Libraries\Documents;

use App\Libraries\Documents\DocumentError;
use App\Libraries\Documents\DocumentException;
use App\Libraries\Documents\SimplifiedInvoiceQr;
use CodeIgniter\Test\CIUnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class SimplifiedInvoiceQrTest extends CIUnitTestCase
{
    private SimplifiedInvoiceQr $qr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->qr = new SimplifiedInvoiceQr();
    }

    private function refusal(callable $action): DocumentException
    {
        try {
            $action();
        } catch (DocumentException $exception) {
            return $exception;
        }

        $this->fail('The code was not refused.');
    }

    public function testTlv_WritesFiveFieldsAsTagLengthAndValueInOrder(): void
    {
        $bytes = $this->qr->tlv('Bobs Records', '310122393500003', '2022-04-25T15:30:00Z', '1000.00', '150.00');

        $expected = "\x01\x0c" . 'Bobs Records'
            . "\x02\x0f" . '310122393500003'
            . "\x03\x14" . '2022-04-25T15:30:00Z'
            . "\x04\x07" . '1000.00'
            . "\x05\x06" . '150.00';

        $this->assertSame($expected, $bytes);
    }

    public function testEncode_ProducesTheBase64OfThePublishedExample(): void
    {
        $text = $this->qr->encode('Bobs Records', '310122393500003', '2022-04-25T15:30:00Z', '1000.00', '150.00');

        $this->assertSame('AQxCb2JzIFJlY29yZHMCDzMxMDEyMjM5MzUwMDAwMwMUMjAyMi0wNC0yNVQxNTozMDowMFoEBzEwMDAuMDAFBjE1MC4wMA==', $text);
    }

    public function testTlv_ProducesTheBytesOfInvoiceOneOfTheReferenceSystemWithItsOwnVatNumber(): void
    {
        $hex = bin2hex($this->qr->tlv('opes', '300000000000003', '2026-10-22T09:47:29Z', '1725.00', '225'));

        $expected = '0104' . bin2hex('opes')
            . '020f' . bin2hex('300000000000003')
            . '0314' . bin2hex('2026-10-22T09:47:29Z')
            . '0407' . bin2hex('1725.00')
            . '0503' . bin2hex('225');

        $this->assertSame($expected, $hex);
        $this->assertSame('01046f706573020f3330303030303030303030303030330314323032362d31302d32325430393a34373a32395a0407313732352e30300503323235', $hex);
    }

    public function testTlv_ProducesTheBytesOfTheCreditNoteOfTheReferenceSystem(): void
    {
        $hex = bin2hex($this->qr->tlv('opes', '300000000000003', '2026-10-22T11:30:33Z', '253.00', '33'));

        $this->assertStringEndsWith('0406' . bin2hex('253.00') . '0502' . bin2hex('33'), $hex);
        $this->assertStringContainsString('0314' . bin2hex('2026-10-22T11:30:33Z'), $hex);
    }

    public function testTlv_WritesTheLengthInBytesForAnArabicSellerName(): void
    {
        $name  = 'شركة النور';
        $bytes = $this->qr->tlv($name, '300000000000003', '2026-10-22T09:47:29Z', '115.00', '15.00');

        $this->assertSame(10, mb_strlen($name));
        $this->assertSame(19, strlen($name));
        $this->assertSame(1, ord($bytes[0]));
        $this->assertSame(19, ord($bytes[1]));
        $this->assertSame($name, substr($bytes, 2, 19));
        $this->assertSame(2, ord($bytes[21]));
    }

    public function testTlv_WritesNoFieldBeyondTheFifthTag(): void
    {
        $bytes = $this->qr->tlv('A', '3', '2026-10-22T09:47:29Z', '1.00', '0.00');

        $tags   = [];
        $offset = 0;

        while ($offset < strlen($bytes)) {
            $tags[] = ord($bytes[$offset]);
            $offset += 2 + ord($bytes[$offset + 1]);
        }

        $this->assertSame([1, 2, 3, 4, 5], $tags);
    }

    public function testEncode_RejectsAMissingSellerName(): void
    {
        $refusal = $this->refusal(fn () => $this->qr->encode('  ', '300000000000003', '2026-10-22T09:47:29Z', '1.00', '0.00'));

        $this->assertSame(DocumentError::QrSellerMissing, $refusal->error);
    }

    public function testEncode_RejectsAMissingVatNumberInsteadOfWritingAnEmptyField(): void
    {
        $refusal = $this->refusal(fn () => $this->qr->encode('opes', '', '2026-10-22T09:47:29Z', '1725.00', '225.00'));

        $this->assertSame(DocumentError::QrVatNumberMissing, $refusal->error);

        $blank = $this->refusal(fn () => $this->qr->encode('opes', "  \t", '2026-10-22T09:47:29Z', '1725.00', '225.00'));

        $this->assertSame(DocumentError::QrVatNumberMissing, $blank->error);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function malformedTimestampProvider(): array
    {
        return [
            'a date alone'        => ['2026-10-22'],
            'no zone letter'      => ['2026-10-22T09:47:29'],
            'a space instead of T' => ['2026-10-22 09:47:29Z'],
            'an offset'           => ['2026-10-22T09:47:29+03:00'],
            'empty'               => [''],
        ];
    }

    #[DataProvider('malformedTimestampProvider')]
    public function testEncode_RejectsATimestampThatIsNotUniversalTime(string $timestamp): void
    {
        $refusal = $this->refusal(fn () => $this->qr->encode('opes', '300000000000003', $timestamp, '1.00', '0.00'));

        $this->assertSame(DocumentError::QrTimestampMalformed, $refusal->error);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedAmountProvider(): array
    {
        return [
            'a negative total'  => ['-1.00', '0.00'],
            'a thousands comma' => ['1,725.00', '225.00'],
            'letters'           => ['abc', '0.00'],
            'an empty tax'      => ['1.00', ''],
            'a currency sign'   => ['1.00', '$0.15'],
        ];
    }

    #[DataProvider('malformedAmountProvider')]
    public function testEncode_RejectsAnAmountThatIsNotPlainDigits(string $total, string $tax): void
    {
        $refusal = $this->refusal(fn () => $this->qr->encode('opes', '300000000000003', '2026-10-22T09:47:29Z', $total, $tax));

        $this->assertSame(DocumentError::QrAmountMalformed, $refusal->error);
    }

    public function testEncode_AcceptsAValueOfExactlyTwoHundredAndFiftyFiveBytes(): void
    {
        $name  = str_repeat('a', 255);
        $bytes = $this->qr->tlv($name, '300000000000003', '2026-10-22T09:47:29Z', '1.00', '0.00');

        $this->assertSame(255, ord($bytes[1]));
    }

    public function testEncode_RejectsAValueOfTwoHundredAndFiftySixBytes(): void
    {
        $refusal = $this->refusal(fn () => $this->qr->encode(str_repeat('a', 256), '300000000000003', '2026-10-22T09:47:29Z', '1.00', '0.00'));

        $this->assertSame(DocumentError::QrValueTooLong, $refusal->error);
        $this->assertSame(1, $refusal->context['tag']);
        $this->assertSame(256, $refusal->context['bytes']);
    }

    public function testEncode_RejectsAnArabicNameWhoseBytesPassTheLimitEvenWhenItsLettersDoNot(): void
    {
        $name = str_repeat('ش', 128);

        $this->assertLessThan(255, mb_strlen($name));

        $refusal = $this->refusal(fn () => $this->qr->encode($name, '300000000000003', '2026-10-22T09:47:29Z', '1.00', '0.00'));

        $this->assertSame(DocumentError::QrValueTooLong, $refusal->error);
    }

    public function testEncode_RoundTripsThroughBase64(): void
    {
        $text = $this->qr->encode('opes', '300000000000003', '2026-10-22T09:47:29Z', '1725.00', '225.00');

        $this->assertSame($this->qr->tlv('opes', '300000000000003', '2026-10-22T09:47:29Z', '1725.00', '225.00'), base64_decode($text, true));
    }
}
