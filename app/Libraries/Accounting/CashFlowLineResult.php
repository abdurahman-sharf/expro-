<?php

namespace App\Libraries\Accounting;

/**
 * A line of the cash flow report with what it came to in the range.
 */
final readonly class CashFlowLineResult
{
    /**
     * @param numeric-string $amount At four decimals.
     */
    public function __construct(
        public string $key,
        public string $title,
        public bool $custom,
        public string $amount,
    ) {
    }
}
