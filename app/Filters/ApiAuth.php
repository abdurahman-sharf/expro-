<?php

namespace App\Filters;

use App\Libraries\Integrations\ApiAuthenticator;
use App\Libraries\Integrations\ApiContext;
use App\Libraries\Integrations\ApiFailure;
use App\Libraries\Integrations\IntegrationOptions;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Stands in front of every route under api/. While the API is switched off each one is a page that does not
 * exist, whoever asks and whatever they present. Otherwise the call must carry a key that passes, in the
 * Authorization header as a bearer token or in X-Api-Key, never in the address, where it would be written to
 * every log on the way.
 */
class ApiAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        ApiContext::clear();

        if (! (new IntegrationOptions())->apiEnabled()) {
            return $this->refusal(ResponseInterface::HTTP_NOT_FOUND, 'not_found', 'Not found.');
        }

        $result = (new ApiAuthenticator())->authenticate(self::presented($request), $request->getIPAddress());

        if ($result->succeeded()) {
            ApiContext::set($result->key);

            return null;
        }

        if ($result->failure === ApiFailure::RateLimited) {
            return $this->refusal(ResponseInterface::HTTP_TOO_MANY_REQUESTS, 'rate_limited', 'Too many calls for this key.')->setHeader('Retry-After', (string) $result->retryAfter);
        }

        return $this->refusal(ResponseInterface::HTTP_UNAUTHORIZED, 'unauthorized', 'The key is missing or not accepted.')->setHeader('WWW-Authenticate', 'Bearer');
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        ApiContext::clear();

        return $response->setHeader('Cache-Control', 'no-store');
    }

    public static function presented(RequestInterface $request): ?string
    {
        $authorization = trim($request->getHeaderLine('Authorization'));

        if (preg_match('/^Bearer\s+(\S+)$/i', $authorization, $parts) === 1) {
            return $parts[1];
        }

        $header = trim($request->getHeaderLine('X-Api-Key'));

        return $header === '' ? null : $header;
    }

    private function refusal(int $status, string $code, string $message): ResponseInterface
    {
        return service('response')->setStatusCode($status)->setHeader('Cache-Control', 'no-store')->setJSON(['error' => $code, 'message' => $message]);
    }
}
