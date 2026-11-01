<?php

namespace App\Libraries\Pricing;

enum PriceRule: string
{
    case BelowMinimum = 'below_minimum';
    case AboveCeiling = 'above_ceiling';
}
