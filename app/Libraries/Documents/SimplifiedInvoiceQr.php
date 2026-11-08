<?php

namespace App\Libraries\Documents;

/**
 * The text of the code that goes on a simplified tax invoice of the first phase of the Saudi
 * electronic invoicing: five fields in this order, each written as a tag of one byte, a length of one
 * byte and the value as UTF-8, and the whole of it as base64. The fields of the second phase (tags 6 to
 * 9) are not written here. The builder holds no setting and reads nothing, so the document layer decides
 * whether a document has a code at all.
 */
final class SimplifiedInvoiceQr
{
    public const TAG_SELLER_NAME  = 1;
    public const TAG_VAT_NUMBER   = 2;
    public const TAG_TIMESTAMP    = 3;
    public const TAG_TOTAL        = 4;
    public const TAG_TAX          = 5;
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

    private function field(int $tag, string $value): string
    {
        $length = \strlen($value);

        if ($length > self::MAX_VALUE_BYTES) {
            throw new DocumentException(DocumentError::QrValueTooLong, context: ['tag' => $tag, 'bytes' => $length]);
        }

        return chr($tag) . chr($length) . $value;
    }
}
