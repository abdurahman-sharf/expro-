<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\SaleAmounts;

trait BuildsReports
{
    protected function column(string $key, ColumnKind $kind = ColumnKind::Text, ?string $label = null): ReportColumn
    {
        return new ReportColumn($key, lang('Accounting_family_reports.' . ($label ?? 'col_' . $key)), $kind);
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<string>    $keys The columns to add up.
     *
     * @return array<string, string>
     */
    protected function sums(array $rows, array $keys): array
    {
        $sums = array_fill_keys($keys, '0');

        foreach ($rows as $row) {
            foreach ($keys as $key) {
                $sums[$key] = bcadd($sums[$key], $row->cell($key) === '' ? '0' : $row->cell($key), SaleAmounts::SCALE);
            }
        }

        return array_map(static fn (string $sum): string => bcadd($sum, '0', SaleAmounts::SCALE), $sums);
    }

    /**
     * @param list<ReportRow> $rows
     * @param list<string>    $keys
     */
    protected function totalRow(array $rows, array $keys, string $labelKey, string $label): ReportRow
    {
        return new ReportRow([$labelKey => $label, ...$this->sums($rows, $keys)], null, true);
    }

    protected function amount(string $value): string
    {
        return bcadd($value, '0', SaleAmounts::SCALE);
    }

    protected function quantity(string $value): string
    {
        return bcadd($value, '0', 3);
    }

    protected function text(string $key, mixed ...$arguments): string
    {
        return lang('Accounting_family_reports.' . $key, $arguments);
    }

    /**
     * @param list<string> $notes
     */
    protected function noteIfAny(array &$notes, bool $condition, string $key, mixed ...$arguments): void
    {
        if ($condition) {
            $notes[] = $this->text($key, ...$arguments);
        }
    }
}
