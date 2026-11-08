<?php

namespace App\Libraries\EInvoicing;

use RuntimeException;
use Throwable;

class EInvoiceException extends RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        public readonly EInvoiceError $error,
        string $message = '',
        public readonly array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message !== '' ? $message : $error->value, 0, $previous);
    }

    public function describe(): string
    {
        $arguments = [];

        foreach (['rule', 'field', 'expected', 'actual', 'name', 'count', 'item', 'line', 'number'] as $name) {
            if (array_key_exists($name, $this->context)) {
                $arguments[] = (string) $this->context[$name];
            }
        }

        return lang('Accounting_einvoicing.error_' . $this->error->value, $arguments);
    }
}
