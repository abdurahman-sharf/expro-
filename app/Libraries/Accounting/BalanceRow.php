<?php

namespace App\Libraries\Accounting;

/**
 * What the account of a customer or of a supplier did over a period, split the way the reference
 * system splits it: the balance before the period, what was sold or bought, what came back as a
 * return invoice (negative, as the account moves), what was paid, what credit and debit notes settled, and the balance at the end.
 * Whatever the account holds that none of those explains (a manual journal) is kept apart as
 * other, so the columns always add up to the balance.
 */
final readonly class BalanceRow
{
    public function __construct(
        public int $partyId,
        public string $name,
        public int $accountId,
        public string $before,
        public string $sales,
        public string $returns,
        public string $payments,
        public string $settlements,
        public string $other,
        public string $balance,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function net(): string
    {
        return bcadd($this->sales, $this->returns, SaleAmounts::SCALE);
    }

    /**
     * Whether the columns, and not only the balance, explain the balance: before, plus the net of
     * sales, less payments, plus the settlements, plus what is not classified.
     */
    public function adds(): bool
    {
        $sum = bcadd(bcadd(bcsub(bcadd($this->before, $this->net(), SaleAmounts::SCALE), $this->payments, SaleAmounts::SCALE), $this->settlements, SaleAmounts::SCALE), $this->other, SaleAmounts::SCALE);

        return bccomp($sum, $this->balance, SaleAmounts::SCALE) === 0;
    }

    public function isEmpty(): bool
    {
        foreach ([$this->before, $this->sales, $this->returns, $this->payments, $this->settlements, $this->other, $this->balance] as $amount) {
            if (bccomp($amount, '0', SaleAmounts::SCALE) !== 0) {
                return false;
            }
        }

        return true;
    }
}
