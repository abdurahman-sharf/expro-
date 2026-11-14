<?php

namespace App\Libraries\Accounting;

/**
 * The documents a report holds in a currency other than the base, the way a refusal names them: each once,
 * with its number and its currency, the first few and how many more there are, so a report of thousands
 * of documents still says in a line which of them it cannot be set beside the books for.
 */
final class ForeignDocuments
{
    public const NAMED = 5;

    /**
     * @param list<ForeignDocument> $documents
     *
     * @return list<ForeignDocument> Each once, in the order given.
     */
    public static function unique(array $documents): array
    {
        $unique = [];

        foreach ($documents as $document) {
            $unique[$document->label()] ??= $document;
        }

        return array_values($unique);
    }

    /**
     * @param list<ForeignDocument> $documents
     */
    public static function describe(array $documents): string
    {
        $documents = self::unique($documents);
        $named     = implode(', ', array_map(static fn (ForeignDocument $document): string => $document->label(), array_slice($documents, 0, self::NAMED)));

        return count($documents) > self::NAMED ? $named . ' +' . (count($documents) - self::NAMED) : $named;
    }
}
