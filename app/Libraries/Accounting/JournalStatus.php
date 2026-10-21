<?php

namespace App\Libraries\Accounting;

enum JournalStatus: string
{
    case Draft    = 'draft';
    case Preview  = 'preview';
    case Posted   = 'posted';
    case Reversed = 'reversed';
}
