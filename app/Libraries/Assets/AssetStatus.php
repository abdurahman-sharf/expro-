<?php

namespace App\Libraries\Assets;

enum AssetStatus: string
{
    case Active     = 'active';
    case Inactive   = 'inactive';
    case Consumed   = 'consumed';
    case Sold       = 'sold';
    case WrittenOff = 'written_off';

    public function isDisposed(): bool
    {
        return $this === self::Sold || $this === self::WrittenOff;
    }

    public function isOnTheBooks(): bool
    {
        return ! $this->isDisposed();
    }

    public function canBeDisposed(): bool
    {
        return ! $this->isDisposed();
    }
}
