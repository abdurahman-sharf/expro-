<?php

namespace App\Libraries\Accounting\Ai;

enum ProposalStatus: string
{
    case Proposed   = 'proposed';
    case Approved   = 'approved';
    case Rejected   = 'rejected';
    case Expired    = 'expired';
    case Superseded = 'superseded';
}
