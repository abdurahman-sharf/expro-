<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * What the reports of the family share when they add rows up and name the group a block of rows is under.
 */
trait BuildsBlocks
{
    /**
     * @param list<array<string, string>> $rows
     * @param list<string>                $keys The cells that are amounts to add up.
     *
     * @return array<string, string>
     */
    private function addUp(array $rows, array $keys): array
    {
        $sums = [];

        foreach ($keys as $key) {
            $sums[$key] = Amounts::sum(array_map(static fn (array $row): string => $row[$key] ?? Amounts::ZERO, $rows));
        }

        return $sums;
    }

    /**
     * @return array<string, string>
     */
    private function heading(string $label, string $kind = 'text'): array
    {
        return ['label' => $label, 'labelKind' => $kind];
    }

    private function walkIn(): string
    {
        return lang('Accounting_operational_reports.walk_in');
    }

    private function nobody(): string
    {
        return lang('Accounting_operational_reports.no_user');
    }
}
