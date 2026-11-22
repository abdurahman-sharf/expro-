<?php

namespace App\Libraries\EInvoicing;

use RuntimeException;

/**
 * A request that got no answer: the connection could not be made, so ZATCA never received it, or it was made and no
 * answer came in time, so ZATCA may have received it. The second is sent again as it was, never built again.
 */
final class ZatcaHttpFailure extends RuntimeException
{
    public function __construct(string $message, public readonly bool $mayHaveArrived)
    {
        parent::__construct($message);
    }
}
