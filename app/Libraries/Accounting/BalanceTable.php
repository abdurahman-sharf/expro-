<?php

namespace App\Libraries\Accounting;

/**
 * The balance report of customers or of suppliers for a period: a row for each party and the totals
 * of every column, which are the totals of the accounts and so agree with the trial balance.
 */
final readonly class BalanceTable
{
    public const COLUMNS = ['before', 'sales', 'returns', 'net_sales', 'payments', 'settlements', 'balance'];

    /**
     * @param list<BalanceRow> $rows
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $rows,
    ) {
    }

    /**
     * @param string $column One of the columns, or other.
     *
     * @return numeric-string
     */
    public function total(string $column): string
    {
        $total = '0';

        foreach ($this->rows as $row) {
            $total = bcadd($total, $column === 'net_sales' ? $row->net() : (string) $row->{$column}, SaleAmounts::SCALE);
        }

        return bcadd($total, '0', SaleAmounts::SCALE);
    }

    public function hasOther(): bool
    {
        foreach ($this->rows as $row) {
            if (bccomp($row->other, '0', SaleAmounts::SCALE) !== 0) {
                return true;
            }
        }

        return false;
    }
}
