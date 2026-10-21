<?php

namespace App\Libraries\Accounting;

use RuntimeException;
use Throwable;

class PostingException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly PostingError $error,
        string $message,
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
