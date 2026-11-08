<?php

namespace App\Libraries\EInvoicing;

final readonly class StampedDocument
{
    public function __construct(
        public string $xml,
        public string $hash,
        public string $qr,
        public bool $signed,
        public string $signature = '',
    ) {
    }
}
