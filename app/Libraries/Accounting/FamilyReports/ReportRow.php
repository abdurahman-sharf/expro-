<?php

namespace App\Libraries\Accounting\FamilyReports;

final readonly class ReportRow
{
    /**
     * @param array<string, string> $cells The raw value of each column, by its key: a number as a numeric string, a day as Y-m-d.
     */
    public function __construct(
        public array $cells,
        public ?string $url = null,
        public bool $total = false,
    ) {
    }

    public function cell(string $key): string
    {
        return $this->cells[$key] ?? '';
    }
}
