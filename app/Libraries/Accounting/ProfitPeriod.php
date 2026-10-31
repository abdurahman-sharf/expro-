<?php

namespace App\Libraries\Accounting;

/**
 * One column of a profit and loss statement: the days of the range that fall in a calendar month,
 * quarter or year, and the name of that month, quarter or year.
 */
final readonly class ProfitPeriod
{
    public function __construct(
        public string $label,
        public string $from,
        public string $to,
    ) {
    }
}
