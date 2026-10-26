<?php

namespace App\Libraries\Accounting;

enum JournalChange: string
{
    case None      = 'none';
    case Unchanged = 'unchanged';
    case Posted    = 'posted';
    case Replaced  = 'replaced';
    case Reversed  = 'reversed';
}
