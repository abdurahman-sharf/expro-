<?php

namespace App\Libraries\Accounting;

enum YearEndBranchStatus: string
{
    case Ready         = 'ready';
    case NoActivity    = 'no_activity';
    case AlreadyClosed = 'already_closed';
}
