<?php

namespace App\Libraries\Accounting;

/**
 * The aged ledger of customers or of suppliers on a day: a row for each party who owes or is owed
 * something, and the totals of every bucket, which are what the accounts hold on that day.
 */
final readonly class AgedLedgerTable
{
    /**
     * @param list<AgedLedgerRow> $rows
     */
    public function __construct(
        public string $asOf,
        public int $days,
        public array $rows,
    ) {
    }

    /**
     * @return numeric-string
     */
    public function bucket(int $index): string
    {
        $total = '0';

        foreach ($this->rows as $row) {
            $total = bcadd($total, $row->buckets[$index], SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string
     */
    public function total(): string
    {
        $total = '0';

        foreach ($this->rows as $row) {
            $total = bcadd($total, $row->total(), SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string
     */
    public function credit(): string
    {
        $total = '0';

        foreach ($this->rows as $row) {
            $total = bcadd($total, $row->credit, SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    /**
     * @return numeric-string
     */
    public function balance(): string
    {
        return bcsub($this->total(), $this->credit(), SaleAmounts::SCALE);
    }
}
