<?php

namespace App\Libraries\Stock;

enum TrackingType: string
{
    case None   = 'none';
    case Serial = 'serial';
    case Batch  = 'batch';
}
