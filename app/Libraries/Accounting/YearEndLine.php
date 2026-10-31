<?php

namespace App\Libraries\Accounting;

final readonly class YearEndLine
{
    /**
     * @param numeric-string $net What the account stands at in the year: debit minus credit, so a revenue that kept its credit balance is negative.
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public AccountType $type,
        public string $net,
    ) {
    }

    /**
     * @return numeric-string What the closing credits to the account: a debit balance is closed by a credit.
     */
    public function closingCredit(): string
    {
        return bccomp($this->net, '0', 4) > 0 ? $this->net : '0.0000';
    }

    /**
     * @return numeric-string What the closing debits to the account.
     */
    public function closingDebit(): string
    {
        return bccomp($this->net, '0', 4) < 0 ? bcsub('0', $this->net, 4) : '0.0000';
    }
}
