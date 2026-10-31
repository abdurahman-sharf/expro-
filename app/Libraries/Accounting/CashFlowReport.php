<?php

namespace App\Libraries\Accounting;

/**
 * A cash flow report as it was read: the lines of each part with the total of the part, the change
 * of the accounts that no line takes from set out account by account, and the money held when the
 * range opened and when it ended. The total change is that of the treasuries and the banks, which
 * is checked against what they hold.
 */
final readonly class CashFlowReport
{
    /**
     * @param array<string, list<CashFlowLineResult>>                                 $lines       The lines of each part, by the name of the part.
     * @param array<string, numeric-string>                                           $totals      The total of each part.
     * @param list<array{accountId: int, code: string, name: string, amount: string}> $otherItems
     * @param numeric-string                                                          $other       What the accounts that no line takes from came to.
     * @param numeric-string                                                          $totalChange
     * @param numeric-string                                                          $cashOpening
     * @param numeric-string                                                          $cashClosing What the treasuries and the banks hold at the end, read on its own.
     */
    public function __construct(
        public array $lines,
        public array $totals,
        public array $otherItems,
        public string $other,
        public string $totalChange,
        public string $cashOpening,
        public string $cashClosing,
        public ReportScope $scope,
        public string $from,
        public string $to,
    ) {
    }

    public function ties(): bool
    {
        return bccomp(bcadd($this->cashOpening, $this->totalChange, 4), $this->cashClosing, 4) === 0;
    }
}
