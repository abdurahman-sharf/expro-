<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * Reads the signing key and the certificate from the paths the settings name, at the moment a document is
 * signed and never before, and refuses a path inside the application, a key that is not on the curve the
 * standard names (secp256k1, which the installed OpenSSL must offer), and a certificate that is not the one
 * of the key. Nothing of the key is kept after the call.
 */
final class StampKeys
{
    public const CURVE = 'secp256k1';

    /**
     * @param list<string>|null $curves The curves the OpenSSL offers, for a test; asked of OpenSSL when none is given.
     */
    public static function curveAvailable(?array $curves = null): bool
    {
        return in_array(self::CURVE, $curves ?? openssl_get_curve_names() ?: [], true);
    }

    /**
     * @param list<string>|null $protected The directories of the application, for a test that checks the refusal elsewhere.
     * @param list<string>|null $curves    The curves the OpenSSL offers, for a test.
     *
     * @throws EInvoiceException
     */
    public static function load(#[SensitiveParameter] string $keyPath, string $certificatePath, ?array $protected = null, ?array $curves = null): StampMaterial
    {
        $keyFile         = KeyPath::check($keyPath, $protected);
        $certificateFile = KeyPath::check($certificatePath, $protected);

        if (! self::curveAvailable($curves)) {
            throw new EInvoiceException(EInvoiceError::CurveUnsupported, 'The installed OpenSSL does not offer the curve ' . self::CURVE . '.');
        }

        $keyPem = is_file($keyFile) && is_readable($keyFile) ? file_get_contents($keyFile) : false;
        $key    = $keyPem === false ? false : self::quietly(static fn (): mixed => openssl_pkey_get_private($keyPem));

        if ($key === false) {
            throw new EInvoiceException(EInvoiceError::KeyUnreadable, 'The private key cannot be read.');
        }

        $details = openssl_pkey_get_details($key);

        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC || ($details['ec']['curve_name'] ?? '') !== self::CURVE) {
            throw new EInvoiceException(EInvoiceError::CurveUnsupported, 'The private key is not on the curve ' . self::CURVE . '.');
        }

        $certificatePem = is_file($certificateFile) && is_readable($certificateFile) ? file_get_contents($certificateFile) : false;
        $certificate    = $certificatePem === false ? false : self::quietly(static fn (): mixed => openssl_x509_read($certificatePem));

        if ($certificate === false || ! openssl_x509_export($certificate, $normalized)) {
            throw new EInvoiceException(EInvoiceError::CertificateUnreadable, 'The certificate cannot be read.');
        }

        if (! openssl_x509_check_private_key($certificate, $key)) {
            throw new EInvoiceException(EInvoiceError::CertificateKeyMismatch, 'The certificate is not the one of the private key.');
        }

        $parsed = openssl_x509_parse($certificate);
        $der    = self::der($normalized);

        return new StampMaterial(
            privateKey: $key,
            certificateBase64: base64_encode($der),
            publicKeyDer: self::der($details['key']),
            certificateSignatureDer: DerReader::certificateSignature($der),
            issuerName: self::issuerName((array) ($parsed['issuer'] ?? [])),
            serialNumber: (string) ($parsed['serialNumber'] ?? ''),
        );
    }

    /**
     * OpenSSL warns about a file that is not what it was asked to read; the refusal says so in its own words.
     */
    private static function quietly(callable $read): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }

    private static function der(string $pem): string
    {
        $body = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '';

        return (string) base64_decode($body, true);
    }

    /**
     * The issuer as the official validator reads it from the certificate, which is how Java writes a name: the most
     * specific part first, the parts parted by a comma and a space, and a value that holds a special character in
     * quotes.
     *
     * @param array<string, mixed> $issuer
     */
    private static function issuerName(array $issuer): string
    {
        $parts = [];

        foreach ($issuer as $attribute => $values) {
            foreach ((array) $values as $value) {
                $parts[] = $attribute . '=' . self::quoted((string) $value);
            }
        }

        return implode(', ', array_reverse($parts));
    }

    private static function quoted(string $value): string
    {
        if (preg_match('/[,=\n+<>#;\\\\"]|^\s|\s$|\s\s/', $value) !== 1) {
            return $value;
        }

        return '"' . addcslashes($value, '"\\') . '"';
    }
}
