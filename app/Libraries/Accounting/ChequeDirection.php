<?php

namespace App\Libraries\Accounting;

enum ChequeDirection: string
{
    case Received = 'received';
    case Paid     = 'paid';
}
