<?php

namespace App\Libraries\Accounting\OperationalReports;

final readonly class ReportColumn
{
    public function __construct(
        public string $key,
        public string $labelKey,
        public ColumnKind $kind = ColumnKind::Text,
    ) {
    }
}
