<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * A certificate ZATCA gives a unit (CSID): the token that is the certificate, the secret that goes with it, and the
 * number of the request, which the production certificate is asked for with.
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
    ) {
    }
}
