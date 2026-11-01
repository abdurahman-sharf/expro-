<?php

namespace App\Libraries\Accounting\Bank;

final class StatementRow
{
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $date,
        public readonly string $description,
        public readonly string $reference,
        public readonly string $amount,
        public readonly string $dedupeKey,
    ) {
    }
}
