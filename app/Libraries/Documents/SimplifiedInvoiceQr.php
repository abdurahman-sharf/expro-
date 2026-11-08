<?php

namespace App\Libraries\Documents;

/**
 * The text of the code that goes on a simplified tax invoice of the Saudi electronic invoicing: five
 * fields in this order, each written as a tag of one byte, a length of one byte and the value as UTF-8, and
 * the whole of it as base64, and for the second phase the four fields that follow (the hash of the document,
 * the signature, the public key and, for a simplified invoice, the signature of the certificate). The
 * builder holds no setting and reads nothing, so the document layer decides whether a document has a code at
 * all and which phase it is in.
 */
final class SimplifiedInvoiceQr
{
    public const TAG_SELLER_NAME  = 1;
    public const TAG_VAT_NUMBER   = 2;
    public const TAG_TIMESTAMP    = 3;
    public const TAG_TOTAL        = 4;
    public const TAG_TAX          = 5;
    public const TAG_HASH         = 6;
    public const TAG_SIGNATURE    = 7;
    public const TAG_PUBLIC_KEY   = 8;
    public const TAG_CERTIFICATE  = 9;
    public const MAX_TEXT_LENGTH  = 700;
    private const MAX_VALUE_BYTES = 255;

    /**
     * @param string $timestamp    Universal time as 2026-10-22T09:47:29Z.
     * @param string $totalWithTax The total of the document with the tax in it.
     * @param string $taxTotal     The tax the total holds.
     */
    public function encode(string $sellerName, string $vatNumber, string $timestamp, string $totalWithTax, string $taxTotal): string
    {
        return base64_encode($this->tlv($sellerName, $vatNumber, $timestamp, $totalWithTax, $taxTotal));
    }

    public function tlv(string $sellerName, string $vatNumber, string $timestamp, string $totalWithTax, string $taxTotal): string
    {
        $sellerName = trim($sellerName);
        $vatNumber  = trim($vatNumber);

        if ($sellerName === '') {
            throw new DocumentException(DocumentError::QrSellerMissing);
        }

        if ($vatNumber === '') {
            throw new DocumentException(DocumentError::QrVatNumberMissing);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $timestamp) !== 1) {
            throw new DocumentException(DocumentError::QrTimestampMalformed, context: ['value' => $timestamp]);
        }

        foreach ([$totalWithTax, $taxTotal] as $amount) {
            if (preg_match('/^\d+(\.\d+)?$/', $amount) !== 1) {
                throw new DocumentException(DocumentError::QrAmountMalformed, context: ['value' => $amount]);
            }
        }

        return $this->field(self::TAG_SELLER_NAME, $sellerName)
            . $this->field(self::TAG_VAT_NUMBER, $vatNumber)
            . $this->field(self::TAG_TIMESTAMP, $timestamp)
            . $this->field(self::TAG_TOTAL, $totalWithTax)
            . $this->field(self::TAG_TAX, $taxTotal);
    }

    /**
     * The text of the code with the fields of the second phase, for the tags 6 to 8 of a document that is signed
     * and the tag 9 of a simplified one. The hash and the signature are written as the base64 text the file
     * holds, as the example of the technical guidelines does, and the key and the signature of the
     * certificate as the bytes of their DER. The security standard of 2023 says the hash is the 32 bytes
     * themselves, which `$hashAsBytes` writes: which of the two the validator of the authority takes is to be
     * verified against its toolbox before any real use.
     *
     * @throws DocumentException
     */
    public function encodePhaseTwo(
        string $sellerName,
        string $vatNumber,
        string $timestamp,
        string $totalWithTax,
        string $taxTotal,
        string $hashBase64,
        string $signatureBase64,
        string $publicKeyDer,
        ?string $certificateSignatureDer = null,
        bool $hashAsBytes = false,
    ): string {
        $bytes = $this->tlv($sellerName, $vatNumber, $timestamp, $totalWithTax, $taxTotal)
            . $this->field(self::TAG_HASH, $hashAsBytes ? (string) base64_decode($hashBase64, true) : $hashBase64)
            . $this->field(self::TAG_SIGNATURE, $signatureBase64)
            . $this->field(self::TAG_PUBLIC_KEY, $publicKeyDer);

        if ($certificateSignatureDer !== null) {
            $bytes .= $this->field(self::TAG_CERTIFICATE, $certificateSignatureDer);
        }

        $text = base64_encode($bytes);

        if (strlen($text) > self::MAX_TEXT_LENGTH) {
            throw new DocumentException(DocumentError::QrValueTooLong, context: ['tag' => 0, 'bytes' => strlen($text)]);
        }

        return $text;
    }

    private function field(int $tag, string $value): string
    {
        $length = \strlen($value);

        if ($length > self::MAX_VALUE_BYTES) {
            throw new DocumentException(DocumentError::QrValueTooLong, context: ['tag' => $tag, 'bytes' => $length]);
        }

        return chr($tag) . chr($length) . $value;
    }
}
