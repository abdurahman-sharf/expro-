<?php

namespace App\Libraries\Organization;

/**
 * The company every request works in. There is one company today. Services take it from here,
 * so a second company later changes this class and the screens, not every query.
 */
final class CompanyContext
{
    public const DEFAULT_COMPANY_ID = 1;

    public static function id(): int
    {
        return self::DEFAULT_COMPANY_ID;
    }
}
