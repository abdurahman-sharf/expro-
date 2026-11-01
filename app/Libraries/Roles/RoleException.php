<?php

namespace App\Libraries\Roles;

use RuntimeException;
use Throwable;

class RoleException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly RoleError $error,
        string $message = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error->value, 0, $previous);
    }
}
