<?php

namespace App\Libraries\Accounting;

enum LockOutcome: string
{
    case Taken    = 'taken';
    case Busy     = 'busy';
    case NotHeld  = 'not_held';
    case Occupied = 'occupied';
}
