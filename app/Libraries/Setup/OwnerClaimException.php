<?php

namespace App\Libraries\Setup;

use RuntimeException;

final class OwnerClaimException extends RuntimeException
{
    /**
     * @param string               $key     The end of the line of the Owner_setup language file that says what is wrong.
     * @param array<string, mixed> $context
     */
    public function __construct(public readonly string $key, public readonly array $context = [])
    {
        parent::__construct("The owner could not be set up: {$key}.");
    }
}
