<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * The journals that close a year by moving the result of its revenue and expense accounts to
 * retained earnings. A report of a period leaves them out, so a year reads the same before and
 * after it is closed, and the balance sheet keeps them. A journal is one of them by its type, the
 * single definition: every journal of the closing of a year is written with the type year_end by
 * PostingService::postYearEnd, the closing and its reversal alike, and nothing else can be written
 * with it. The source year_end names the document of the closing and plays no part in telling the
 * journals apart. The reversal of one, and the reversal of that reversal, goes with it: a closing
 * taken back is left out as a pair, whereas the reversal of any other journal is counted like the
 * journal itself.
 */
final class YearEndEntries
{
    public const SOURCE_TYPE = 'year_end';
    public const ENTRY_TYPE  = 'year_end';

    public static function leaveOut(BaseBuilder $builder, BaseConnection $db, string $journalAlias = 'j'): BaseBuilder
    {
        $builder->where("{$journalAlias}.entry_type <>", self::ENTRY_TYPE);

        $reversals = self::reversalsOfClosings($db);

        return $reversals === [] ? $builder : $builder->whereNotIn("{$journalAlias}.journal_id", $reversals);
    }

    /**
     * Read with queries of their own, not with a builder: a builder that is run resets the table
     * aliases the connection holds for the one being built.
     *
     * @return list<int>
     */
    private static function reversalsOfClosings(BaseConnection $db): array
    {
        $journals = $db->prefixTable('accounting_journals');

        $level = self::idsOf($db->query(
            "SELECT journal_id FROM {$journals} WHERE entry_type = ?",
            [self::ENTRY_TYPE],
        )->getResult());

        $seen      = array_fill_keys($level, true);
        $reversals = [];

        while ($level !== []) {
            $next         = [];
            $placeholders = implode(', ', array_fill(0, count($level), '?'));

            foreach (self::idsOf($db->query("SELECT journal_id FROM {$journals} WHERE reverses_journal_id IN ({$placeholders})", $level)->getResult()) as $journalId) {
                if (! isset($seen[$journalId])) {
                    $seen[$journalId] = true;
                    $reversals[]      = $journalId;
                    $next[]           = $journalId;
                }
            }

            $level = $next;
        }

        return $reversals;
    }

    /**
     * @param list<object> $rows
     *
     * @return list<int>
     */
    private static function idsOf(array $rows): array
    {
        return array_map(static fn (object $row): int => (int) $row->journal_id, $rows);
    }
}
