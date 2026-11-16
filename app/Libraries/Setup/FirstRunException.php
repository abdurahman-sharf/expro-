<?php

namespace App\Libraries\Setup;

use RuntimeException;

final class FirstRunException extends RuntimeException
{
    /**
     * @param string               $key     The end of the line of the Setup language file that says what is wrong.
     * @param array<string, mixed> $context
     */
    public function __construct(public readonly string $key, public readonly array $context = [])
    {
        parent::__construct("The first setup could not go on: {$key}.");
    }
}
