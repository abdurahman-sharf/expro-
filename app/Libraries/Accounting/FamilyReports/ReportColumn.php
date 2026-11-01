<?php

namespace App\Libraries\Accounting\FamilyReports;

final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $label,
        public ColumnKind $kind = ColumnKind::Text,
    ) {
    }
}
