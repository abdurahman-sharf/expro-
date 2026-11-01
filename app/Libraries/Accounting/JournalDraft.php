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
        public JournalEntryType $entryType = JournalEntryType::General,
    ) {
    }

    public function withDescription(string $description): self
    {
        return new self(
            $this->date,
            $this->lines,
            $this->sourceType,
            $this->sourceId,
            $this->revision,
            $this->origin,
            $this->employeeId,
            $description,
            $this->currencyCode,
            $this->exchangeRate,
            $this->reversesJournalId,
            $this->branchId,
            $this->entryType,
        );
    }

    /**
     * The same journal with other lines and the currency and rate they were converted at.
     *
     * @param list<JournalLineDraft> $lines
     */
    public function withLinesIn(array $lines, string $currencyCode, string $exchangeRate): self
    {
        return new self(
            $this->date,
            $lines,
            $this->sourceType,
            $this->sourceId,
            $this->revision,
            $this->origin,
            $this->employeeId,
            $this->description,
            $currencyCode,
            $exchangeRate,
            $this->reversesJournalId,
            $this->branchId,
            $this->entryType,
        );
    }
}
