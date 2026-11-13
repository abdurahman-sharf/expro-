<?php

namespace App\Libraries\EInvoicing;

use OpenSSLAsymmetricKey;

/**
 * What stamping a document needs from the key and the certificate: the key itself, the certificate as the
 * base64 the file holds, the public key and the signature of the certificate as DER (tags 8 and 9 of the
 * code), and the name of the issuer and the serial number the signed properties name. Held for as long as
 * one document takes to stamp.
 */
final readonly class StampMaterial
{
    public function __construct(
        public OpenSSLAsymmetricKey $privateKey,
        public string $certificateBase64,
        public string $publicKeyDer,
        public string $certificateSignatureDer,
        public string $issuerName,
        public string $serialNumber,
    ) {
    }
}
