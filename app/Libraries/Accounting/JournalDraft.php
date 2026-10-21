<?php

namespace App\Libraries\Accounting;

final readonly class JournalDraft
{
    public const SOURCE_MANUAL   = 'manual';
    public const SOURCE_REVERSAL = 'reversal';

    /**
     * @param list<JournalLineDraft> $lines
     */
    public function __construct(
        public string $date,
        public array $lines,
        public string $sourceType,
        public ?int $sourceId = null,
        public int $revision = 1,
        public JournalOrigin $origin = JournalOrigin::System,
        public ?int $employeeId = null,
        public string $description = '',
        public string $currencyCode = '',
        public string $exchangeRate = '1',
        public ?int $reversesJournalId = null,
        public ?int $branchId = null,
    ) {
    }
}
