<?php

namespace App\Libraries\Accounting;

/**
 * What a customer owes, or what is owed to a supplier, aged by the account: the credit that came
 * in (payments, credit and debit notes) is applied to the oldest debit first, and what is left of
 * each debit is aged by the day of its own journal. What the credit could not be applied to, because
 * there was nothing left to apply it to, is the credit held.
 */
final readonly class AgedLedgerRow
{
    /**
     * @param list<numeric-string> $buckets The six buckets, the day itself first.
     */
    public function __construct(
        public int $partyId,
        public string $name,
        public int $accountId,
        public array $buckets,
        public string $credit,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function total(): string
    {
        return AgingBuckets::total($this->buckets);
    }

    /**
     * What the account holds on that day: what is owed less the credit held.
     *
     * @return numeric-string
     */
    public function balance(): string
    {
        return bcsub($this->total(), $this->credit, SaleAmounts::SCALE);
    }
}
