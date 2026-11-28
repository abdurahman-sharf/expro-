<?php

namespace App\Libraries\Accounting\Engine;

/**
 * What the posting engine does not take over yet, operation by operation. An operation with a gap here may
 * be checked in shadow but is never posted by the engine: the switch to engine refuses it until its gaps are
 * closed and taken off this list.
 */
final class PostingGaps
{
    /**
     * @var array<string, list<string>>
     */
    public const OPEN = [
        'supplier_advance' => [
            'an opening balance a supplier owes is posted under the same source by the code, and has no rules of its own yet',
        ],
        'purchase' => [
            'the stock movements of a purchase in a foreign currency are valued from the journal of the code, so a journal of the engine that differed would leave the stock untied',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function of(string $operation): array
    {
        return self::OPEN[$operation] ?? [];
    }
}
