<?php

namespace App\Libraries\EInvoicing;

final readonly class ReadinessCheck
{
    public const PASS = 'pass';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    public function __construct(
        public string $id,
        public string $status,
        public string $detail = '',
    ) {
    }
}
