<?php

namespace App\Libraries\Accounting;

/**
 * The lines of an account over a period, with where it stood when the period opened and where it
 * stands at the end. The balances are in the direction the account normally holds: debit minus
 * credit for an asset or an expense, credit minus debit for the rest.
 */
final readonly class AccountLedgerReport
{
    /**
     * @param list<array<string, mixed>> $rows    The lines of the page asked for, each with the balance after it.
     * @param numeric-string             $opening
     * @param numeric-string             $closing
     * @param numeric-string             $debit   What the whole period debited, not only this page.
     * @param numeric-string             $credit
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public NormalBalance $normal,
        public bool $isGroup,
        public int $accountsCounted,
        public array $rows,
        public int $totalRows,
        public string $opening,
        public string $debit,
        public string $credit,
        public string $closing,
        public ReportScope $scope,
        public ReportPeriod $period,
        public ?int $employeeId = null,
    ) {
    }
}
