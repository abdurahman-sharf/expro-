<?php

namespace App\Libraries\Assets;

enum AssetInterval: string
{
    case Month = 'month';
    case Day   = 'day';
    case Week  = 'week';

    public function observedCode(): int
    {
        return match ($this) {
            self::Month => 1,
            self::Day   => 2,
            self::Week  => 3,
        };
    }
}
