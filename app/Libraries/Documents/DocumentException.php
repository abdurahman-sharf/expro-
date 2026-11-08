<?php

namespace App\Libraries\Documents;

use RuntimeException;
use Throwable;

class DocumentException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly DocumentError $error,
        string $message = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error->value, 0, $previous);
    }
}
