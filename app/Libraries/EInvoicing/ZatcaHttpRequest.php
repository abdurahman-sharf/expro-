<?php

namespace App\Libraries\EInvoicing;

use SensitiveParameter;

/**
 * One request to ZATCA as it goes over the wire. Its headers carry the credentials (Authorization) and the one-time
 * password (OTP), so they are never shown: dumped or logged, the request names them without their values.
 */
final readonly class ZatcaHttpRequest
{
    private const HIDDEN = ['authorization', 'otp'];

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $environment,
        public string $method,
        public string $url,
        #[SensitiveParameter]
        public array $headers,
        public string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<string, string> The headers, those that carry secrets masked.
     */
    public function shownHeaders(): array
    {
        $shown = [];

        foreach ($this->headers as $key => $value) {
            $shown[$key] = in_array(strtolower($key), self::HIDDEN, true) ? '***' : $value;
        }

        return $shown;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['environment' => $this->environment, 'method' => $this->method, 'url' => $this->url, 'headers' => $this->shownHeaders(), 'body' => strlen($this->body) . ' bytes'];
    }
}
