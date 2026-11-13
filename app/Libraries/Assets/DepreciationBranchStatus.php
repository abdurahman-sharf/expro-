<?php

namespace App\Libraries\Assets;

enum DepreciationBranchStatus: string
{
    case Ready      = 'ready';
    case NothingDue = 'nothing_due';
    case Blocked    = 'blocked';
}
