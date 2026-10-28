<?php

namespace App\Libraries\Accounting;

/**
 * Where a supplier stands: the purchases that are still open, the credit held with them that has
 * not been spent, and the balance of their account. The three agree, and what does not (a manual
 * journal on the account of the supplier) is shown apart as unclassified instead of hidden. A
 * balance is what is owed to the supplier, and minus what they owe back.
 */
final readonly class SupplierPosition
{
    public const AGING_BUCKETS = AgingBuckets::KEYS;

    /**
     * @param list<PurchaseFigures> $purchases
     * @param list<CreditSource>    $credits
     */
    public function __construct(
        public int $supplierId,
        public ?int $accountId,
        public string $accountBalance,
        public array $purchases,
        public array $credits,
        public ?string $asOf = null,
    ) {
    }

    /**
     * @return numeric-string What is owed to the supplier on purchases that are still open.
     */
    public function open(): string
    {
        $open = '0';

        foreach ($this->purchases as $purchase) {
            $open = bcadd($open, $purchase->open(), SaleAmounts::SCALE);
        }

        return bcadd($open, '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string What is held with the supplier in credit that has not been spent.
     */
    public function credit(): string
    {
        $credit = '0';

        foreach ($this->credits as $source) {
            $credit = bcadd($credit, $source->available(), SaleAmounts::SCALE);
        }

        return bcadd($credit, '0', SaleAmounts::SCALE);
    }

    /**
     * What the purchases and the credit say the account should hold.
     *
     * @return numeric-string
     */
    public function ledgerBalance(): string
    {
        return bcsub($this->open(), $this->credit(), SaleAmounts::SCALE);
    }

    /**
     * What the account holds that the purchases and the credit do not explain.
     *
     * @return numeric-string
     */
    public function unclassified(): string
    {
        return bcsub($this->accountBalance, $this->ledgerBalance(), SaleAmounts::SCALE);
    }

    public function agrees(): bool
    {
        return bccomp($this->unclassified(), '0', SaleAmounts::SCALE) === 0;
    }

    /**
     * What is owed, in the six buckets of the age of the purchase in days at a date. The age counts
     * from the day the purchase is dated, not from the day it falls due, and a purchase dated after
     * the date is not counted.
     *
     * @return array<string, numeric-string>
     */
    public function aging(string $asOf): array
    {
        $documents = [];

        foreach ($this->purchases as $purchase) {
            if (bccomp($purchase->open(), '0', SaleAmounts::SCALE) > 0) {
                $documents[] = [$purchase->date, $purchase->open()];
            }
        }

        return AgingBuckets::named($documents, $asOf);
    }

    /**
     * What is owed on purchases whose due date is before the day.
     *
     * @return numeric-string
     */
    public function overdue(string $today): string
    {
        $late = '0';

        foreach ($this->purchases as $purchase) {
            if ($purchase->isOverdue($today)) {
                $late = bcadd($late, $purchase->open(), SaleAmounts::SCALE);
            }
        }

        return bcadd($late, '0', SaleAmounts::SCALE);
    }

    public function purchase(int $receivingId): ?PurchaseFigures
    {
        foreach ($this->purchases as $purchase) {
            if ($purchase->receivingId === $receivingId) {
                return $purchase;
            }
        }

        return null;
    }
}
