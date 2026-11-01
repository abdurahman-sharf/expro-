<?php

namespace App\Libraries\Accounting\OperationalReports;

final readonly class PeriodBucket
{
    public function __construct(
        public PeriodGroup $group,
        public string $key,
        public string $from,
        public string $to,
    ) {
    }
}
