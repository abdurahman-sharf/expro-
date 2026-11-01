<?php

namespace App\Libraries\Accounting;

/**
 * What a treasury holds: the balance of its account, and how much of it was put there by hand.
 * The rest comes from payments, transfers and opening balances. A treasury that holds a foreign
 * currency says how much it holds in that currency too; the balance of its account is the same money
 * in the base, so it still equals the account.
 */
final readonly class TreasuryPosition
{
    /**
     * @param numeric-string $balance Debits less credits of the account.
     * @param numeric-string $manual  What journals written by hand put on the account.
     * @param numeric-string|null $foreignBalance What the account holds in the currency of the treasury, when the treasury holds one: the balance above is the same money in the base.
     */
    public function __construct(
        public int $treasuryId,
        public ?int $accountId,
        public string $balance,
        public string $manual,
        public ?string $currencyCode = null,
        public ?string $foreignBalance = null,
    ) {
    }

    /**
     * What documents put on the account, so that what is not theirs shows apart.
     *
     * @return numeric-string
     */
    public function documented(): string
    {
        return bcsub($this->balance, $this->manual, SaleAmounts::SCALE);
    }

    public function agrees(): bool
    {
        return bccomp($this->manual, '0', SaleAmounts::SCALE) === 0;
    }
}
