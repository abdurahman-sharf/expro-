<?php

namespace App\Libraries\Numbering;

final readonly class DocumentNumber
{
    public function __construct(
        public DocumentType $type,
        public int $sequence,
        public string $number,
    ) {
    }
}
