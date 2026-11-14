<?php

namespace App\Libraries\Accounting;

final readonly class ForeignDocument
{
    public function __construct(
        public string $number,
        public string $currencyCode,
    ) {
    }

    public function label(): string
    {
        return $this->number . ' (' . $this->currencyCode . ')';
    }
}
