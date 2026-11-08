<?php

namespace App\Libraries\Stock;

enum NegativeStockOption: string
{
    case Allow           = 'allow';
    case Refuse          = 'refuse';
    case RefuseAvailable = 'refuse_below_available';
}
