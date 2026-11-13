<?php

namespace App\Libraries\Integrations;

/**
 * The key a request of the API was authenticated with, held for the length of that request. The filter
 * sets it and clears it, and an endpoint that finds none refuses the request.
 */
final class ApiContext
{
    private static ?ApiKey $key = null;

    public static function set(?ApiKey $key): void
    {
        self::$key = $key;
    }

    public static function key(): ?ApiKey
    {
        return self::$key;
    }

    public static function clear(): void
    {
        self::$key = null;
    }
}
