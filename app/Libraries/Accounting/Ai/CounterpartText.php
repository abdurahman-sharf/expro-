<?php

namespace App\Libraries\Accounting\Ai;

/**
 * The text that names the other side of a line: what is written on the line, else what is written on its
 * journal, with the case and the spaces made equal so that the same words typed twice are one text.
 */
final class CounterpartText
{
    public const MAX = 120;

    public static function normalize(?string $text): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim((string) $text));

        return mb_substr(mb_strtolower(trim((string) $collapsed)), 0, self::MAX);
    }

    /**
     * The same text as the database sees it, so that a query can compare it with a normalized text.
     */
    public static function sql(string $lineAlias, string $journalAlias): string
    {
        return "LOWER(LEFT(TRIM(REGEXP_REPLACE(COALESCE(NULLIF(TRIM({$lineAlias}.description), ''), {$journalAlias}.description, ''), '[[:space:]]+', ' ')), " . self::MAX . '))';
    }
}
