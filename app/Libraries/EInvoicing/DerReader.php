<?php

namespace App\Libraries\EInvoicing;

/**
 * The little of ASN.1 that the signature of a certificate needs: a certificate is a sequence of the
 * certificate to be signed, the algorithm and the signature as a bit string, and the code of the invoice
 * carries that last part as it is.
 */
final class DerReader
{
    /**
     * @throws EInvoiceException
     */
    public static function certificateSignature(string $der): string
    {
        [$tag, , $offset] = self::header($der, 0);

        if ($tag !== 0x30) {
            throw new EInvoiceException(EInvoiceError::CertificateUnreadable, 'The certificate is not a DER sequence.');
        }

        $offset = self::skip($der, $offset);
        $offset = self::skip($der, $offset);

        [$tag, $length, $content] = self::header($der, $offset);

        if ($tag !== 0x03 || $length < 1 || $content + $length > strlen($der)) {
            throw new EInvoiceException(EInvoiceError::CertificateUnreadable, 'The certificate holds no signature.');
        }

        return substr($der, $content + 1, $length - 1);
    }

    /**
     * @return array{0: int, 1: int, 2: int} The tag, the length of the content and where the content starts.
     *
     * @throws EInvoiceException
     */
    private static function header(string $der, int $offset): array
    {
        if ($offset + 2 > strlen($der)) {
            throw new EInvoiceException(EInvoiceError::CertificateUnreadable, 'The certificate ends too soon.');
        }

        $tag    = ord($der[$offset]);
        $length = ord($der[$offset + 1]);
        $next   = $offset + 2;

        if ($length >= 0x80) {
            $count = $length & 0x7f;

            if ($count < 1 || $count > 4 || $next + $count > strlen($der)) {
                throw new EInvoiceException(EInvoiceError::CertificateUnreadable, 'The length of a certificate part is not valid.');
            }

            $length = 0;

            for ($index = 0; $index < $count; $index++) {
                $length = ($length << 8) | ord($der[$next + $index]);
            }

            $next += $count;
        }

        return [$tag, $length, $next];
    }

    private static function skip(string $der, int $offset): int
    {
        [, $length, $content] = self::header($der, $offset);

        return $content + $length;
    }
}
