<?php

namespace App\Libraries\Accounting;

/**
 * An account of the trial balance with what it holds, its own lines and those of the accounts
 * under it. Amounts are strings at four decimals. The opening and closing are debit minus credit,
 * and the gross opening is what each side had added up to before the period.
 */
final readonly class TrialBalanceRow
{
    /**
     * @param numeric-string $opening
     * @param numeric-string $debit         What was debited in the period.
     * @param numeric-string $credit        What was credited in the period.
     * @param numeric-string $openingDebit  What was debited before the period.
     * @param numeric-string $openingCredit What was credited before the period.
     * @param int<1, max>    $level         How many accounts there are from the top of the chart to this one, counting both.
     */
    public function __construct(
        public int $accountId,
        public string $code,
        public string $name,
        public AccountType $type,
        public bool $isGroup,
        public bool $isEntity,
        public int $depth,
        public string $opening,
        public string $debit,
        public string $credit,
        public string $openingDebit = '0.0000',
        public string $openingCredit = '0.0000',
        public int $level = 1,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function closing(): string
    {
        return bcadd(bcadd($this->opening, $this->debit, 4), bcsub('0', $this->credit, 4), 4);
    }

    /**
     * @return numeric-string
     */
    public function grossClosingDebit(): string
    {
        return bcadd($this->openingDebit, $this->debit, 4);
    }

    /**
     * @return numeric-string
     */
    public function grossClosingCredit(): string
    {
        return bcadd($this->openingCredit, $this->credit, 4);
    }

    /**
     * @return array{numeric-string, numeric-string} The debit and the credit side of a net amount.
     */
    public static function sides(string $net): array
    {
        return bccomp($net, '0', 4) >= 0 ? [bcadd($net, '0', 4), '0.0000'] : ['0.0000', bcsub('0', $net, 4)];
    }
}
