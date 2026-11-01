<?php

namespace App\Libraries;

enum ShippingTaxOption: string
{
    case None     = 'none';
    case Added    = 'added';
    case Included = 'included';

    public static function fromStored(?string $stored): self
    {
        return $stored === null ? self::None : (self::tryFrom($stored) ?? self::None);
    }
}
