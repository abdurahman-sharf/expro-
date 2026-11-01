<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Ages what is owed from the account itself, not from the documents: every line of the account
 * on the side of what is owed is a debit, every line on the other side is credit, and the credit is
 * applied to the oldest debit first, as the reference system does in its aging by ledger. What is left
 * of a debit is aged by the day of its journal against the day of the report, in buckets of a period
 * of days. Only journals dated by the day of the report count, so the buckets, less the credit held,
 * are the balance of the account of that day.
 */
final class AgedLedger
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * The customers, or the suppliers, who owe or are owed something on the day, or one of them.
     *
     * @param string         $kind      EntityAccountService::CUSTOMER or EntityAccountService::SUPPLIER.
     * @param list<int>|null $branchIds The branches whose journals count, null for every branch.
     *
     * @return list<AgedLedgerRow>
     */
    public function build(string $kind, string $asOf, int $period = AgingBuckets::DEFAULT_PERIOD, ?int $partyId = null, ?array $branchIds = null): array
    {
        $period   = max(1, min($period, AgingBuckets::MAX_PERIOD));
        $owed     = $kind === EntityAccountService::CUSTOMER ? 'l.debit' : 'l.credit';
        $settled  = $kind === EntityAccountService::CUSTOMER ? 'l.credit' : 'l.debit';
        $accounts = $this->db->prefixTable('accounting_accounts');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $journals = $this->db->prefixTable('accounting_journals');
        $bindings = [JournalStatus::Posted->value, JournalStatus::Reversed->value, $asOf, $kind];
        $only     = '';
        $inBranch = '';

        if ($branchIds !== null) {
            $inBranch = ' AND j.branch_id IN (' . ($branchIds === [] ? 'NULL' : implode(', ', array_map('intval', $branchIds))) . ')';
        }

        if ($partyId !== null) {
            $only       = ' AND a.entity_id = ?';
            $bindings[] = $partyId;
        }

        $rows = $this->db->query(
            "SELECT a.entity_id AS party_id, a.name AS party_name, a.account_id, j.journal_date, j.journal_id, {$owed} AS owed, {$settled} AS settled
             FROM {$accounts} a
             JOIN {$lines} l ON l.account_id = a.account_id
             JOIN {$journals} j ON j.journal_id = l.journal_id AND j.status IN (?, ?) AND j.journal_date <= ?{$inBranch}
             WHERE a.entity_type = ?{$only}
             ORDER BY a.name ASC, a.account_id ASC, j.journal_date ASC, j.journal_id ASC, l.line_no ASC",
            $bindings,
        )->getResult();

        $accountsRows = [];

        foreach ($rows as $row) {
            $accountsRows[(int) $row->account_id][] = $row;
        }

        $aged = [];

        foreach ($accountsRows as $accountId => $entries) {
            $credit    = '0';
            $documents = [];

            foreach ($entries as $entry) {
                $credit = bcadd($credit, (string) $entry->settled, SaleAmounts::SCALE);

                if (bccomp((string) $entry->owed, '0', SaleAmounts::SCALE) > 0) {
                    $documents[] = [(string) $entry->journal_date, (string) $entry->owed];
                }
            }

            $left = [];

            foreach ($documents as [$date, $amount]) {
                $applied = bccomp($credit, $amount, SaleAmounts::SCALE) < 0 ? $credit : $amount;
                $credit  = bcsub($credit, $applied, SaleAmounts::SCALE);
                $rest    = bcsub($amount, $applied, SaleAmounts::SCALE);

                if (bccomp($rest, '0', SaleAmounts::SCALE) > 0) {
                    $left[] = [$date, $rest];
                }
            }

            $row = new AgedLedgerRow((int) $entries[0]->party_id, (string) $entries[0]->party_name, $accountId, AgingBuckets::spread($left, $asOf, $period), bcadd($credit, '0', SaleAmounts::SCALE));

            if (bccomp($row->total(), '0', SaleAmounts::SCALE) > 0 || bccomp($row->credit, '0', SaleAmounts::SCALE) > 0) {
                $aged[] = $row;
            }
        }

        return $aged;
    }
}
