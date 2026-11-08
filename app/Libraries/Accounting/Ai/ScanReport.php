<?php

namespace App\Libraries\Accounting\Ai;

final readonly class ScanReport
{
    /**
     * @param array<string, int> $created  The findings written, by rule.
     * @param array<string, int> $existing The findings that stood already, by rule.
     */
    public function __construct(
        public string $since,
        public array $created,
        public array $existing,
    ) {
    }

    public function createdCount(): int
    {
        return array_sum($this->created);
    }

    public function existingCount(): int
    {
        return array_sum($this->existing);
    }
}
