<?php

namespace App\Libraries\Accounting;

enum OpeningStockStatus: string
{
    case Postable      = 'postable';
    case Posted        = 'posted';
    case NothingToPost = 'nothing_to_post';
    case AlreadyPosted = 'already_posted';
    case Blocked       = 'blocked';
}
