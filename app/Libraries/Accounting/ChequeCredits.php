<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;

/**
 * What the cheques of a customer or a supplier hold in credit that no invoice has spent. The journals
 * of the cheque say it: a received cheque credits the account of its customer until it is rejected,
 * and a cheque delivered to a supplier debits the account of its supplier until it is rejected. The
 * credit is the net of the journals of the cheque that stand, so a status that takes the cheque back
 * takes the credit back with it.
 */
final class ChequeCredits
{
    /**
     * @return list<object> The cheque, the day it was issued and what it holds, for each cheque that holds something.
     */
    public static function ofCustomer(BaseConnection $db, int $customerId, int $accountId, ?string $asOf): array
    {
        return self::read($db, ChequeDirection::Received, $customerId, $accountId, $asOf, 'l.credit - l.debit');
    }

    /**
     * @return list<object>
     */
    public static function ofSupplier(BaseConnection $db, int $supplierId, int $accountId, ?string $asOf): array
    {
        return self::read($db, ChequeDirection::Paid, $supplierId, $accountId, $asOf, 'l.debit - l.credit');
    }

    /**
     * @return list<object>
     */
    private static function read(BaseConnection $db, ChequeDirection $direction, int $partyId, int $accountId, ?string $asOf, string $side): array
    {
        [$standing, $standingBindings] = StandingJournals::condition($db, 'j', $asOf);

        $sources      = ChequeTransition::sourcesOf($direction);
        $placeholders = implode(',', array_fill(0, count($sources), '?'));

        return $db->query(
            "SELECT c.cheque_id, c.issue_date, SUM({$side}) AS amount
             FROM " . $db->prefixTable('cheques') . ' c
             JOIN ' . $db->prefixTable('accounting_journals') . " j ON j.source_type IN ({$placeholders}) AND j.source_id = c.cheque_id AND j.entry_type = ? AND {$standing}
             JOIN " . $db->prefixTable('accounting_journal_lines') . ' l ON l.journal_id = j.journal_id AND l.account_id = ?
             WHERE c.direction = ? AND c.party_id = ?
             GROUP BY c.cheque_id, c.issue_date
             HAVING amount > 0
             ORDER BY c.issue_date ASC, c.cheque_id ASC',
            [...$sources, JournalEntryType::General->value, ...$standingBindings, $accountId, $direction->value, $partyId],
        )->getResult();
    }
}
