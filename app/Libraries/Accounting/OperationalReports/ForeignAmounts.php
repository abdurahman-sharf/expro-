<?php

namespace App\Libraries\Accounting\OperationalReports;

use App\Libraries\Accounting\ForeignDocument;
use App\Libraries\Accounting\ForeignDocuments;
use App\Libraries\Accounting\ReportScope;

/**
 * The documents of a sales report read from the stored sale are in the currency of the document, while
 * the journals the checks are set against are in the base currency. A check over documents in a foreign
 * currency would add dollars to riyals, so it is refused by name instead of reporting a difference, and
 * the refusal names the documents.
 */
final class ForeignAmounts
{
    /**
     * @param list<ProfitRow|ReportDocument> $documents
     *
     * @return list<ForeignDocument> The documents in a currency other than the base, each once.
     */
    public static function documentsOf(array $documents): array
    {
        $foreign = [];

        foreach ($documents as $document) {
            if ($document->currencyCode !== null) {
                $foreign[] = new ForeignDocument($document->number, $document->currencyCode);
            }
        }

        return ForeignDocuments::unique($foreign);
    }

    /**
     * The report that is not made when a figure of it would add the price of goods in a foreign currency
     * to their cost in the base currency, or null when none of the documents is in one.
     *
     * @param list<ProfitRow> $rows
     */
    public static function refuseProfit(array $rows, ReportScope $scope, ?string $from, string $to): ?ReportTable
    {
        $foreign = self::documentsOf($rows);

        return $foreign === [] ? null : ReportTable::refused($scope, $from, $to, 'Accounting_operational_reports.profit_foreign_docs', [ForeignDocuments::describe($foreign)]);
    }

    /**
     * @param list<TieCheck>       $ties
     * @param list<ReportDocument> $documents
     *
     * @return list<TieCheck>
     */
    public static function guard(array $ties, array $documents): array
    {
        $foreign = self::documentsOf($documents);

        if ($foreign === []) {
            return $ties;
        }

        return array_map(static fn (TieCheck $tie): TieCheck => TieCheck::refused($tie->labelKey, 'Accounting_operational_reports.tie_foreign_currency', [ForeignDocuments::describe($foreign)]), $ties);
    }
}
