<?php

namespace App\Libraries\Accounting;

final readonly class JournalLineDraft
{
    public function __construct(
        public int $accountId,
        public string $debit = '0',
        public string $credit = '0',
        public ?string $description = null,
        public ?int $costCenterId = null,
        public ?string $partyType = null,
        public ?int $partyId = null,
        public ?string $foreignAmount = null,
        public ?string $currencyCode = null,
        public bool $autoTax = false,
    ) {
    }
}
