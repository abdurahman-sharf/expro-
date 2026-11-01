<?php

namespace App\Libraries\Accounting\FamilyReports;

final readonly class ReportBlock
{
    /**
     * @param list<ReportRow> $rows
     */
    public function __construct(
        public ?string $title,
        public array $rows,
        public ?ReportRow $total = null,
    ) {
    }
}
