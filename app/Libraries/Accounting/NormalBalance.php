<?php

namespace App\Libraries\Accounting;

enum NormalBalance: string
{
    case Debit  = 'debit';
    case Credit = 'credit';
}
