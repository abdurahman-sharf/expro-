<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * The journals that close a year by moving the result of its revenue and expense accounts to
 * retained earnings. The books ask two questions about them, each answered in one place.
 *
 * Which journals a report leaves out, so a year reads the same before and after it is closed while
 * the balance sheet keeps them: leaveOut(), the single definition of the reports, which is the type
 * year_end. Every journal of the closing of a year is written with that type by
 * PostingService::postYearEnd, the closing and its reversal alike, and nothing else can be written
 * with it. The reversal of one, and the reversal of that reversal, goes with it: a closing taken
 * back is left out as a pair, whereas the reversal of any other journal is counted like the journal
 * itself.
 *
 * Which journals the books refuse to write or to reverse outside the closing of the year: marks(),
 * the single definition of the refusals, which is wider on purpose: the type or the source
 * year_end, so a journal that only looks like a closing is never written or taken back by other means.
 */
final class YearEndEntries
{
    public const SOURCE_TYPE = 'year_end';
    public const ENTRY_TYPE  = 'year_end';

    public static function marks(string $entryType, string $sourceType): bool
    {
        return $entryType === self::ENTRY_TYPE || $sourceType === self::SOURCE_TYPE;
    }

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
