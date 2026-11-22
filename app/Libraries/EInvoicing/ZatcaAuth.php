<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * The credentials a request to ZATCA is made with: the token of a certificate (CSID) and its secret, sent as basic
 * authentication. Never shown.
 */
final readonly class ZatcaAuth
{
    public function __construct(
        #[SensitiveParameter]
        private string $token,
        #[SensitiveParameter]
        private string $secret,
    ) {
    }

    public function header(): string
    {
        return 'Basic ' . base64_encode($this->token . ':' . $this->secret);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['token' => '***', 'secret' => '***'];
    }
}
