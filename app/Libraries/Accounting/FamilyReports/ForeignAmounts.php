<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\ForeignDocument;
use App\Libraries\Accounting\ForeignDocuments;

/**
 * The documents of a purchases report are worked out in the currency of the document, while the journals
 * the checks are set against are in the base currency. A check over documents in a foreign currency would
 * add dollars to riyals, so it is refused by name instead of reporting a difference, and the refusal names
 * the documents.
 */
final class ForeignAmounts
{
    /**
     * @param list<PurchaseDocument> $documents
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
     * @param list<object{number: string, currency_code: ?string}> $payments
     *
     * @return list<ForeignDocument> The payments in a currency other than the base, each once.
     */
    public static function paymentsOf(array $payments): array
    {
        $foreign = [];

        foreach ($payments as $payment) {
            if ($payment->currency_code !== null) {
                $foreign[] = new ForeignDocument((string) $payment->number, (string) $payment->currency_code);
            }
        }

        return ForeignDocuments::unique($foreign);
    }

    /**
     * @param list<TieCheck>        $ties
     * @param list<ForeignDocument> $foreign The documents or payments the checks are made on that are not in the base currency.
     *
     * @return list<TieCheck>
     */
    public static function guard(array $ties, array $foreign): array
    {
        if ($foreign === []) {
            return $ties;
        }

        $refusal = lang('Accounting_family_reports.tie_foreign_currency', [ForeignDocuments::describe($foreign)]);

        return array_map(static fn (TieCheck $tie): TieCheck => TieCheck::refused($tie->label, $refusal), $ties);
    }
}
