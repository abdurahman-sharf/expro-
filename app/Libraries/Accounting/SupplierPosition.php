<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * Where a supplier stands: the purchases that are still open, the credit held with them that has
 * not been spent, and the balance of their account. The three agree, and what does not (a manual
 * journal on the account of the supplier) is shown apart as unclassified instead of hidden. A
 * balance is what is owed to the supplier, and minus what they owe back.
 */
final readonly class SupplierPosition
{
    public const AGING_BUCKETS = ['0-30', '31-60', '61-90', '91+'];

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
     * What is owed, by the age of the purchase in days at a date.
     *
     * @return array<string, numeric-string>
     */
    public function aging(string $asOf): array
    {
        $buckets = array_fill_keys(self::AGING_BUCKETS, '0.0000');
        $today   = new DateTimeImmutable($asOf);

        foreach ($this->purchases as $purchase) {
            if (bccomp($purchase->open(), '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            $days   = max(0, (int) $today->diff(new DateTimeImmutable($purchase->date))->format('%a'));
            $bucket = match (true) {
                $days <= 30 => '0-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default     => '91+',
            };

            $buckets[$bucket] = bcadd($buckets[$bucket], $purchase->open(), SaleAmounts::SCALE);
        }

        return $buckets;
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
