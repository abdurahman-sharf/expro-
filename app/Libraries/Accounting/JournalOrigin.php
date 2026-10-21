<?php

namespace App\Libraries\Accounting;

enum JournalOrigin: string
{
    case User   = 'user';
    case System = 'system';
    case Ai     = 'ai';
}
