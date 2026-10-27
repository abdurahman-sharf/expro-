<?php

namespace App\Libraries\Accounting;

/**
 * The accounts of one type as a statement lists them, and the total of the type. The total is that
 * of the accounts at the top of the chart whatever the lines shown, so a level, a hidden account or
 * a folded one changes what is listed and never what is added up.
 */
final readonly class StatementSection
{
    /**
     * @param list<StatementLine> $lines
     * @param numeric-string      $total
     */
    public function __construct(
        public AccountType $type,
        public array $lines,
        public string $total,
    ) {
    }
}
