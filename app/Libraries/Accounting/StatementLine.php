<?php

namespace App\Libraries\Accounting;

/**
 * An account of a statement with what it stands at, its own lines and those of the accounts under
 * it, read in the direction of its section: a debit balance inside a liability reads as a minus.
 */
final readonly class StatementLine
{
    /**
     * @param numeric-string $amount At four decimals.
     * @param int<1, max>    $level  How many accounts there are from the top of the chart to this one, counting both.
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public bool $isGroup,
        public bool $isEntity,
        public int $level,
        public string $amount,
    ) {
    }
}
