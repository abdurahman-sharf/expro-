<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * A certificate ZATCA gives a unit (CSID): the token that carries it (binarySecurityToken), the secret that goes
 * with it, the number of the request, which the production certificate is asked for with, and the certificate itself
 * in base64, as it is written in the stamp of a document.
 */
final readonly class ZatcaCsid
{
    public function __construct(
        public string $requestId,
        #[SensitiveParameter]
        public string $token,
        #[SensitiveParameter]
        public string $secret,
        public string $validFrom,
        public string $validTo,
        public string $certificate = '',
    ) {
    }

    public function auth(): ZatcaAuth
    {
        return new ZatcaAuth($this->token, $this->secret);
    }
}
