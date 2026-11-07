<?php

namespace App\Libraries\Accounting;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public string $nameAr,
        public string $nameEn,
        public int $decimals,
        public bool $active,
    ) {
    }
}
