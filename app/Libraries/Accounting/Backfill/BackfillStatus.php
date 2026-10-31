<?php

namespace App\Libraries\Accounting\Backfill;

enum BackfillStatus: string
{
    case Collecting = 'collecting';
    case Preview    = 'preview';
    case Approving  = 'approving';
    case Approved   = 'approved';
    case Cancelled  = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Collecting, self::Preview, self::Approving], true);
    }
}
