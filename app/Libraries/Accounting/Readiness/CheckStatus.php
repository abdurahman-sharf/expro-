<?php

namespace App\Libraries\Accounting\Readiness;

enum CheckStatus: string
{
    case Pass    = 'pass';
    case Fail    = 'fail';
    case Review  = 'review';
    case Skipped = 'skipped';
    case Error   = 'error';

    public function blocks(): bool
    {
        return $this === self::Fail || $this === self::Error;
    }
}
