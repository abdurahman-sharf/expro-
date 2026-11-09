<?php

namespace App\Libraries\Integrations;

enum ApiFailure: string
{
    case Missing     = 'missing';
    case Malformed   = 'malformed';
    case Unknown     = 'unknown_key';
    case Revoked     = 'revoked';
    case Expired     = 'expired';
    case RateLimited = 'rate_limited';
}
