<?php

namespace App\Libraries\EInvoicing;

final readonly class TransportResult
{
    public const NOT_SUBMITTED = 'not_submitted';

    public function __construct(
        public string $status,
        public string $note = '',
    ) {
    }
}
