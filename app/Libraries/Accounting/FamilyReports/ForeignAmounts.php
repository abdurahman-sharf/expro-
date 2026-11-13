<?php

namespace App\Libraries\Accounting\FamilyReports;

/**
 * The documents of a purchases report are worked out in the currency of the document, while the journals
 * the checks are set against are in the base currency. A check over documents in a foreign currency would
 * add dollars to riyals, so it is refused by name instead of reporting a difference.
 */
final class ForeignAmounts
{
    /**
     * @param list<PurchaseDocument> $documents
     *
     * @return list<string>
     */
    public static function codesOf(array $documents): array
    {
        return self::unique(array_map(static fn (PurchaseDocument $document): ?string => $document->currencyCode, $documents));
    }

    /**
     * @param list<string|null> $codes
     *
     * @return list<string> The currencies other than the base, once each.
     */
    public static function unique(array $codes): array
    {
        $codes = array_unique(array_filter($codes));

        sort($codes);

        return $codes;
    }

    /**
     * @param list<TieCheck>    $ties
     * @param list<string|null> $codes The currencies of the documents or payments the checks are made on.
     *
     * @return list<TieCheck>
     */
    public static function guard(array $ties, array $codes): array
    {
        $codes = self::unique($codes);

        if ($codes === []) {
            return $ties;
        }

        $refusal = lang('Accounting_family_reports.tie_foreign_currency', [implode(', ', $codes)]);

        return array_map(static fn (TieCheck $tie): TieCheck => TieCheck::refused($tie->label, $refusal), $ties);
    }
}
