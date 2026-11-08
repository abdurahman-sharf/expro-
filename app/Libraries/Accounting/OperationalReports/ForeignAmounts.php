<?php

namespace App\Libraries\Accounting\OperationalReports;

/**
 * The documents of a sales report read from the stored sale are in the currency of the document, while
 * the journals the checks are set against are in the base currency. A check over documents in a foreign
 * currency would add dollars to riyals, so it is refused by name instead of reporting a difference.
 */
final class ForeignAmounts
{
    /**
     * @param list<ReportDocument> $documents
     *
     * @return list<string> The currencies other than the base that the documents are in, once each.
     */
    public static function codesOf(array $documents): array
    {
        $codes = array_unique(array_filter(array_map(static fn (ReportDocument $document): ?string => $document->currencyCode, $documents)));

        sort($codes);

        return $codes;
    }

    /**
     * @param list<TieCheck>       $ties
     * @param list<ReportDocument> $documents
     *
     * @return list<TieCheck>
     */
    public static function guard(array $ties, array $documents): array
    {
        $codes = self::codesOf($documents);

        if ($codes === []) {
            return $ties;
        }

        return array_map(static fn (TieCheck $tie): TieCheck => TieCheck::refused($tie->labelKey, 'Accounting_operational_reports.tie_foreign_currency', [implode(', ', $codes)]), $ties);
    }
}
