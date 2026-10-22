<?php

namespace App\Libraries\Accounting;

enum JournalEntryType: string
{
    case General = 'general';
    case Revenue = 'revenue';
    case Cogs    = 'cogs';
}
