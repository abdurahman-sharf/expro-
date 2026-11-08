<?php

namespace App\Libraries\Accounting;

/**
 * The file of the aged ledger and of the balance report of customers or of suppliers: the rows and the
 * totals of the screen, in the words of the group of language lines the screen reads.
 */
final class PartyReportCsv
{
    use ExportsCsv;

    public function aged(AgedLedgerTable $table, string $group, string $party): string
    {
        $handle = fopen('php://temp', 'r+b');
        $ranges = AgingBuckets::ranges($table->days);

        $this->csvRow($handle, [lang($group . '.aged_title')]);
        $this->csvRow($handle, [lang($group . '.aged_as_of', [$table->asOf])]);

        $this->csvRow($handle, [
            lang($group . '.' . $party),
            ...array_map(static fn (int $index, mixed $range): string => $index === 0 ? lang($group . '.age_today') : lang($group . '.bucket_days', [$range]), array_keys($ranges), $ranges),
            lang($group . '.aged_total'),
            lang($group . '.aged_credit'),
            lang($group . '.balance'),
        ]);

        foreach ($table->rows as $row) {
            $this->csvRow($handle, [$row->name, ...$row->buckets, $row->total(), $row->credit, $row->balance()]);
        }

        if ($table->rows !== []) {
            $this->csvRow($handle, [lang($group . '.totals'), ...array_map(static fn (int $index): string => $table->bucket($index), array_keys($ranges)), $table->total(), $table->credit(), $table->balance()]);
        }

        return $this->csvText($handle);
    }

    public function balances(BalanceTable $table, string $group, string $party): string
    {
        $handle = fopen('php://temp', 'r+b');
        $other  = $table->hasOther();

        $this->csvRow($handle, [lang($group . '.balances_title')]);
        $this->csvRow($handle, [$table->from === '' ? lang($group . '.balances_period_to', [$table->to]) : lang($group . '.balances_period', [$table->from, $table->to])]);

        $this->csvRow($handle, [
            lang($group . '.' . $party),
            lang($group . '.col_before'),
            lang($group . '.col_sales'),
            lang($group . '.col_returns'),
            lang($group . '.col_net'),
            lang($group . '.col_payments'),
            lang($group . '.col_settlements'),
            ...($other ? [lang($group . '.col_other')] : []),
            lang($group . '.balance'),
        ]);

        foreach ($table->rows as $row) {
            $this->csvRow($handle, [$row->name, $row->before, $row->sales, $row->returns, $row->net(), $row->payments, $row->settlements, ...($other ? [$row->other] : []), $row->balance]);
        }

        if ($table->rows !== []) {
            $this->csvRow($handle, [
                lang($group . '.totals'),
                $table->total('before'),
                $table->total('sales'),
                $table->total('returns'),
                $table->total('net_sales'),
                $table->total('payments'),
                $table->total('settlements'),
                ...($other ? [$table->total('other')] : []),
                $table->total('balance'),
            ]);
        }

        return $this->csvText($handle);
    }
}
