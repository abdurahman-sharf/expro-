<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\App;

/**
 * When the application is served over HTTPS, tells browsers to keep to HTTPS for a year, which the framework
 * only does on the redirect from HTTP where browsers ignore it, and never tells which PHP runs it.
 */
final class TransportHeaders implements FilterInterface
{
    public const STRICT_TRANSPORT_SECURITY = 'max-age=31536000';

    public function before(RequestInterface $request, $arguments = null)
    {
        if (config(App::class)->isSecureBase() && ! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        if (! config(App::class)->isSecureBase()) {
            return null;
        }

        $response->setHeader('Strict-Transport-Security', self::STRICT_TRANSPORT_SECURITY);
        $response->removeHeader('X-Powered-By');

        return null;
    }
}
