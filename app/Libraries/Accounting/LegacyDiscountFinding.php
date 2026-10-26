<?php

namespace App\Libraries\Accounting;

final readonly class LegacyDiscountFinding
{
    public function __construct(
        public int $receivingId,
        public int $line,
        public int $itemId,
        public string $units,
        public string $discount,
        public string $savedAt,
        public LegacyDiscountVerdict $verdict,
        public string $reason,
    ) {
    }
}
