<?php

namespace App\Libraries\Portal;

use CodeIgniter\Cookie\Cookie as CookieValue;
use CodeIgniter\HTTP\IncomingRequest;
use Config\Cookie;
use Config\Session;

/**
 * Where the portal begins and what makes its session different from the session of an employee: a path that
 * starts with the prefix of the portal gets a cookie of its own name, a shorter life and a cookie that is
 * never sent along with a request from another site and is marked secure whenever the request is.
 */
final class PortalRealm
{
    public const PREFIX         = 'portal';
    public const COOKIE         = 'ospos_portal_session';
    public const SESSION_SECONDS = 1800;

    public static function isPortalPath(string $path): bool
    {
        $path = strtolower(trim($path, '/ '));

        return $path === self::PREFIX || str_starts_with($path, self::PREFIX . '/');
    }

    public static function apply(Session $session, Cookie $cookie, string $path, bool $secure): void
    {
        if (! self::isPortalPath($path)) {
            return;
        }

        $session->cookieName = self::COOKIE;
        $session->expiration = self::SESSION_SECONDS;
        $cookie->samesite    = CookieValue::SAMESITE_STRICT;
        $cookie->secure      = $cookie->secure || $secure;
    }

    public static function currentPath(): string
    {
        $request = service('request');

        return $request instanceof IncomingRequest ? $request->getUri()->getRoutePath() : '';
    }

    public static function currentIsSecure(): bool
    {
        $request = service('request');

        return $request instanceof IncomingRequest && $request->isSecure();
    }
}
