<?php

namespace App\Libraries\Assets;

final readonly class AssetsReportGroup
{
    /**
     * @param list<AssetsReportRow> $rows
     * @param numeric-string        $cost
     * @param numeric-string        $accumulated
     * @param numeric-string        $depreciation
     * @param numeric-string        $bookValue
     */
    public function __construct(
        public string $key,
        public array $rows,
        public string $cost,
        public string $accumulated,
        public string $depreciation,
        public string $bookValue,
    ) {
    }
}
