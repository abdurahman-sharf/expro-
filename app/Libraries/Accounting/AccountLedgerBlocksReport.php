<?php

namespace App\Libraries\Accounting;

/**
 * The statement of a main account drawn account by account, with the total of what the blocks
 * debited and credited and the difference between the two in the place of a balance.
 */
final readonly class AccountLedgerBlocksReport
{
    /**
     * @param list<LedgerBlock> $blocks
     * @param numeric-string    $debit
     * @param numeric-string    $credit
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public array $blocks,
        public string $debit,
        public string $credit,
        public ReportScope $scope,
        public ReportPeriod $period,
        public LedgerDisplay $display,
        public ?int $employeeId,
    ) {
    }

    /**
     * @return numeric-string Debit minus credit of the whole statement.
     */
    public function net(): string
    {
        return bcsub($this->debit, $this->credit, 4);
    }
}
