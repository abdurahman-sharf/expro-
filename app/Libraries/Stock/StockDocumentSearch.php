<?php

namespace App\Libraries\Stock;

use App\Libraries\Numbering\DocumentType;

/**
 * What a search for the number of a stock document means while the numbering is off: a document then
 * shows the label it falls back to, the kind and the id, and that label, or the id alone, finds it.
 */
final class StockDocumentSearch
{
    private const ID_MAX_DIGITS = 9;

    /**
     * @param list<DocumentType> $types The kinds of document the search may name.
     *
     * @return array{type: DocumentType|null, id: int}|null
     */
    public static function labelled(string $search, array $types): ?array
    {
        $text = strtoupper(ltrim(trim($search), '#'));

        foreach ($types as $type) {
            $prefix = strtoupper(rtrim($type->fallback(0), '0'));

            if (str_starts_with($text, $prefix)) {
                return self::idOf(substr($text, strlen($prefix)), $type);
            }
        }

        return self::idOf($text, null);
    }

    /**
     * @return array{type: DocumentType|null, id: int}|null
     */
    private static function idOf(string $text, ?DocumentType $type): ?array
    {
        $text = trim($text);

        return ctype_digit($text) && strlen($text) <= self::ID_MAX_DIGITS ? ['type' => $type, 'id' => (int) $text] : null;
    }
}
