<?php

namespace App\Libraries\Accounting;

use DateTimeImmutable;

/**
 * Where a customer stands: the invoices that are still open, the credit that has not been spent,
 * and the balance of their account. The three agree, and what does not (a manual journal on the
 * account of the customer) is shown apart as unclassified instead of hidden.
 */
final readonly class CustomerPosition
{
    public const AGING_BUCKETS = ['0-30', '31-60', '61-90', '91+'];

    /**
     * @param list<InvoiceFigures> $invoices
     * @param list<CreditSource>   $credits
     */
    public function __construct(
        public int $customerId,
        public ?int $accountId,
        public string $accountBalance,
        public array $invoices,
        public array $credits,
    ) {
    }

    /**
     * @return numeric-string What the customer owes on invoices that are still open.
     */
    public function open(): string
    {
        $open = '0';

        foreach ($this->invoices as $invoice) {
            $open = bcadd($open, $invoice->open(), SaleAmounts::SCALE);
        }

        return bcadd($open, '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string What the customer holds in credit that has not been spent.
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
     * What the invoices and the credit say the account should hold.
     *
     * @return numeric-string
     */
    public function ledgerBalance(): string
    {
        return bcsub($this->open(), $this->credit(), SaleAmounts::SCALE);
    }

    /**
     * What the account holds that the invoices and the credit do not explain.
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
     * What is owed, by the age of the invoice in days at a date.
     *
     * @return array<string, numeric-string>
     */
    public function aging(string $asOf): array
    {
        $buckets = array_fill_keys(self::AGING_BUCKETS, '0.0000');
        $today   = new DateTimeImmutable($asOf);

        foreach ($this->invoices as $invoice) {
            if (bccomp($invoice->open(), '0', SaleAmounts::SCALE) <= 0) {
                continue;
            }

            $days   = max(0, (int) $today->diff(new DateTimeImmutable($invoice->date))->format('%a'));
            $bucket = match (true) {
                $days <= 30 => '0-30',
                $days <= 60 => '31-60',
                $days <= 90 => '61-90',
                default     => '91+',
            };

            $buckets[$bucket] = bcadd($buckets[$bucket], $invoice->open(), SaleAmounts::SCALE);
        }

        return $buckets;
    }

    public function invoice(int $saleId): ?InvoiceFigures
    {
        foreach ($this->invoices as $invoice) {
            if ($invoice->saleId === $saleId) {
                return $invoice;
            }
        }

        return null;
    }
}
