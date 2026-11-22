<?php

namespace App\Libraries\EInvoicing;

/**
 * How a request to ZATCA ended, as the queue reads it: accepted, accepted with warnings, refused for good, a fault
 * of the service that is tried again (the document was not received), credentials ZATCA does not accept (tried again
 * once they are put right), no answer at all, after which the same request is sent again as it was, or not sent: the
 * request never left the server (the guard was closed, or the unit had no certificate), so nothing changes.
 */
enum ZatcaOutcome: string
{
    case Accepted             = 'accepted';
    case AcceptedWithWarnings = 'accepted_with_warnings';
    case Rejected             = 'rejected';
    case ServerError          = 'server_error';
    case Unauthorized         = 'unauthorized';
    case Timeout              = 'timeout';
    case NotSent              = 'not_sent';

    public function accepted(): bool
    {
        return $this === self::Accepted || $this === self::AcceptedWithWarnings;
    }
}
