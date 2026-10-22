<?php

namespace App\Libraries\Organization;

use RuntimeException;
use Throwable;

class BranchException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly BranchError $error,
        string $message = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error->value, 0, $previous);
    }
}
