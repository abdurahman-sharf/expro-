<?php

namespace App\Libraries\EInvoicing;

/**
 * How a request to ZATCA ended, as the queue reads it: accepted, accepted with warnings, refused for good, a fault
 * of the service that is tried again, or no answer at all, after which the same request is sent again as it was.
 */
enum ZatcaOutcome: string
{
    case Accepted             = 'accepted';
    case AcceptedWithWarnings = 'accepted_with_warnings';
    case Rejected             = 'rejected';
    case ServerError          = 'server_error';
    case Timeout              = 'timeout';

    public function accepted(): bool
    {
        return $this === self::Accepted || $this === self::AcceptedWithWarnings;
    }
}
