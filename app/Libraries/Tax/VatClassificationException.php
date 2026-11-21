<?php

namespace App\Libraries\Tax;

use RuntimeException;
use Throwable;

class VatClassificationException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly VatClassificationError $error,
        string $message = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error->value, 0, $previous);
    }

    public function describe(): string
    {
        $arguments = [];

        foreach (['name', 'date'] as $name) {
            if (array_key_exists($name, $this->context)) {
                $arguments[] = (string) $this->context[$name];
            }
        }

        return lang('Vat_classes.error_' . $this->error->value, $arguments);
    }
}
