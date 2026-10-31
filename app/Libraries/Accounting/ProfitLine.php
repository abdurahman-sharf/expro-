<?php

namespace App\Libraries\Accounting;

/**
 * An account of a profit and loss statement with what it stands at in each column and in the
 * whole range, read in the direction of its section: a debit balance inside the revenue reads as a
 * minus. The accounts under it are folded into it.
 */
final readonly class ProfitLine
{
    /**
     * @param list<numeric-string> $amounts One for each column, at four decimals.
     * @param numeric-string       $total
     * @param int<1, max>          $level   How many accounts there are from the top of the chart to this one, counting both.
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public bool $isGroup,
        public int $level,
        public array $amounts,
        public string $total,
    ) {
    }
}
