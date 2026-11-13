<?php

namespace App\Libraries\Assets;

enum AssetMethod: string
{
    case Fixed     = 'fixed';
    case Declining = 'declining';
    case Units     = 'units';
    case None      = 'none';

    public function observedCode(): int
    {
        return match ($this) {
            self::Fixed     => 1,
            self::Declining => 2,
            self::Units     => 3,
            self::None      => 4,
        };
    }

    public function isScheduled(): bool
    {
        return $this === self::Fixed || $this === self::Declining;
    }

    public function depreciates(): bool
    {
        return $this !== self::None;
    }
}
