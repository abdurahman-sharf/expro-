<?php

namespace App\Libraries\EInvoicing;

/**
 * The signature of the hash of a document: ECDSA with SHA-256 over the curve of the standard, done by the
 * OpenSSL of PHP, which gives the signature as DER. The bytes that are signed are the 32 bytes of the
 * hash of the document (the second of the signing steps of the technical guidelines), so SHA-256 is applied
 * to them once more, as the openssl command does when it signs a file that holds them.
 */
final class EcdsaSigner
{
    /**
     * @throws EInvoiceException
     */
    public function sign(string $hashBytes, StampMaterial $material): string
    {
        if (! openssl_sign($hashBytes, $signature, $material->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new EInvoiceException(EInvoiceError::SigningFailed, 'The hash could not be signed.');
        }

        return $signature;
    }

    public function verify(string $hashBytes, string $signatureDer, string $publicKeyDer): bool
    {
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($publicKeyDer), 64, "\n") . "-----END PUBLIC KEY-----\n";

        return openssl_verify($hashBytes, $signatureDer, $pem, OPENSSL_ALGO_SHA256) === 1;
    }
}
