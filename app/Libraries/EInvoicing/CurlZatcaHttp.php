<?php

namespace App\Libraries\EInvoicing;

use Config\Zatca;

/**
 * The transport that reaches ZATCA over HTTPS. Before every request it asks the guard, which refuses unless the server
 * and the application both allow the environment, and it sends only to the address configured for that environment:
 * HTTPS alone, the certificate of the server verified, no redirection followed. No credential or password is written
 * to a log. The tests never reach it past the guard, which is closed under tests.
 */
final class CurlZatcaHttp implements ZatcaHttp
{
    private const NOT_CONNECTED = [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT, CURLE_COULDNT_RESOLVE_PROXY, CURLE_SSL_CONNECT_ERROR];

    private Zatca $config;

    public function __construct(private readonly ZatcaNetworkGuard $guard, ?Zatca $config = null)
    {
        $this->config = $config ?? config(Zatca::class);
    }

    public function send(ZatcaHttpRequest $request): ZatcaHttpReply
    {
        $this->guard->assertOpen($request->environment);

        $options = $this->options($request);
        $handle  = curl_init();

        curl_setopt_array($handle, $options);

        $body  = curl_exec($handle);
        $error = curl_errno($handle);
        $code  = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        curl_close($handle);

        if ($error !== 0 || ! is_string($body)) {
            throw new ZatcaHttpFailure('No answer from ZATCA (cURL error ' . $error . ').', ! in_array($error, self::NOT_CONNECTED, true));
        }

        return new ZatcaHttpReply($code, $body);
    }

    /**
     * What cURL is told for a request, refused when its address is not the one of its environment.
     *
     * @return array<int, mixed>
     *
     * @throws EInvoiceException
     */
    public function options(ZatcaHttpRequest $request): array
    {
        $environment = ZatcaEnvironment::tryFrom($request->environment);
        $base        = $environment?->baseUrl($this->config) ?? '';

        if ($base === '' || ! str_starts_with($base, 'https://') || ! str_starts_with($request->url, $base . '/')) {
            throw new EInvoiceException(EInvoiceError::NetworkBlocked, 'The address is not the one configured for the environment.', ['field' => 'url']);
        }

        $headers = [];

        foreach ($request->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        return [
            CURLOPT_URL            => $request->url,
            CURLOPT_CUSTOMREQUEST  => $request->method,
            CURLOPT_POSTFIELDS     => $request->body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => max(1, $this->config->connectTimeout),
            CURLOPT_TIMEOUT        => max(1, $this->config->timeout),
        ];
    }
}
