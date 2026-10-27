<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseBuilder;

/**
 * The journals that close a year by moving the result of its revenue and expense accounts to
 * retained earnings. A report of a period leaves them out, so a year reads the same before and
 * after it is closed, and the balance sheet keeps them. A journal is one of them by its source or
 * by its type, so it is found whichever of the two the closing of a year is written with.
 */
final class YearEndEntries
{
    public const SOURCE_TYPE = 'year_end';
    public const ENTRY_TYPE  = 'year_end';

    public static function leaveOut(BaseBuilder $builder, string $journalAlias = 'j'): BaseBuilder
    {
        return $builder
            ->where("{$journalAlias}.source_type <>", self::SOURCE_TYPE)
            ->where("{$journalAlias}.entry_type <>", self::ENTRY_TYPE);
    }
}
