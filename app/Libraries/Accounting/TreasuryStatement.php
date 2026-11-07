<?php

namespace App\Libraries\Accounting;

/**
 * The statement of a treasury or a bank account in pages: its movements cut down to a stretch of days and
 * to an amount, newest first or oldest first, a page of them at a time, with the balance after each
 * movement as the account held it, and the balance before the oldest movement of the page, so that a
 * page can be checked without reading the others. The filters change which movements are shown and
 * never the balances.
 */
final class TreasuryStatement
{
    public const PAGE_SIZE = 20;

    public function __construct(private readonly TreasuryService $treasuries = new TreasuryService())
    {
    }

    /**
     * @param array{from?: string, to?: string, amount_from?: string, amount_to?: string} $filters
     *
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, pages: int, before: ?string, balance: string}
     *
     * @throws AccountingException When a day is not a day, the period ends before it starts, or an amount is not an amount.
     */
    public function page(int $treasuryId, array $filters = [], string $order = 'desc', int $page = 1, int $size = self::PAGE_SIZE): array
    {
        $all     = $this->treasuries->movements($treasuryId)['rows'];
        $rows    = $this->filtered($all, $filters);
        $size    = max(1, $size);
        $pages   = max(1, (int) ceil(count($rows) / $size));
        $page    = max(1, min($page, $pages));
        $ordered = $order === 'asc' ? $rows : array_reverse($rows);
        $slice   = array_slice($ordered, ($page - 1) * $size, $size);
        $oldest  = $order === 'asc' ? ($slice[0] ?? null) : ($slice === [] ? null : $slice[count($slice) - 1]);
        $balance = $all === [] ? '0.0000' : (string) $all[count($all) - 1]['balance'];

        return [
            'rows'    => $slice,
            'total'   => count($rows),
            'page'    => $page,
            'pages'   => $pages,
            'before'  => $oldest === null ? null : bcsub((string) $oldest['balance'], bcsub((string) $oldest['debit'], (string) $oldest['credit'], SaleAmounts::SCALE), SaleAmounts::SCALE),
            'balance' => bcadd($balance, '0', SaleAmounts::SCALE),
        ];
    }

    /**
     * Every movement the filters keep, oldest first, for the file.
     *
     * @param array{from?: string, to?: string, amount_from?: string, amount_to?: string} $filters
     *
     * @return list<array<string, mixed>>
     *
     * @throws AccountingException
     */
    public function rows(int $treasuryId, array $filters = [], string $order = 'asc'): array
    {
        $rows = $this->filtered($this->treasuries->movements($treasuryId)['rows'], $filters);

        return $order === 'asc' ? $rows : array_reverse($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string>      $filters
     *
     * @return list<array<string, mixed>>
     */
    private function filtered(array $rows, array $filters): array
    {
        $from = trim($filters['from'] ?? '');
        $to   = trim($filters['to'] ?? '');

        if ($from !== '' || $to !== '') {
            $period = ReportPeriod::of($from === '' ? null : $from, $to === '' ? '9999-12-31' : $to);
            $from   = $period->from ?? '';
            $to     = $period->to;
        }

        $least = $this->amount($filters['amount_from'] ?? '');
        $most  = $this->amount($filters['amount_to'] ?? '');

        if ($least !== null && $most !== null && bccomp($least, $most, SaleAmounts::SCALE) > 0) {
            throw new AccountingException(AccountingError::StatementAmountInvalid, 'The least amount is more than the most.');
        }

        return array_values(array_filter($rows, static function (array $row) use ($from, $to, $least, $most): bool {
            $moved = bcadd((string) $row['debit'], (string) $row['credit'], SaleAmounts::SCALE);

            return ($from === '' || (string) $row['journal_date'] >= $from)
                && ($to === '' || (string) $row['journal_date'] <= $to)
                && ($least === null || bccomp($moved, $least, SaleAmounts::SCALE) >= 0)
                && ($most === null || bccomp($moved, $most, SaleAmounts::SCALE) <= 0);
        }));
    }

    /**
     * @throws AccountingException
     */
    private function amount(string $text): ?string
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^\d{1,11}(\.\d{1,4})?$/', $text) !== 1) {
            throw new AccountingException(AccountingError::StatementAmountInvalid, 'An amount of the filter is not an amount.');
        }

        return bcadd($text, '0', SaleAmounts::SCALE);
    }
}
