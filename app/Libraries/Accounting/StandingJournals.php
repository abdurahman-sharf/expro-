<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

/**
 * The condition that a journal stands: now it does when it is posted, and on a day of the past it
 * does when it was written by then and had not been reversed by then. So a document that was
 * cancelled later still counts in the books as they were on that day.
 */
final class StandingJournals
{
    /**
     * @return array{0: string, 1: list<string>} The SQL and its bindings, in order.
     */
    public static function condition(BaseConnection $db, string $alias, ?string $asOf): array
    {
        if ($asOf === null) {
            return ["{$alias}.status = ?", [JournalStatus::Posted->value]];
        }

        $journals = $db->prefixTable('accounting_journals');

        return [
            "({$alias}.journal_date <= ? AND ({$alias}.status = ? OR ({$alias}.status = ? AND EXISTS (SELECT 1 FROM {$journals} later WHERE later.reverses_journal_id = {$alias}.journal_id AND later.journal_date > ?))))",
            [$asOf, JournalStatus::Posted->value, JournalStatus::Reversed->value, $asOf],
        ];
    }
}
