<?php

namespace App\Libraries\Portal;

enum PortalLoginOutcome: string
{
    case Success = 'success';
    case Invalid = 'invalid';
    case Locked  = 'locked';
}
