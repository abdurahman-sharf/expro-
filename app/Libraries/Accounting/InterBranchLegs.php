<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The branch on the other side of a journal that put something on the accounts between branches.
 * A settlement and a payment into or out of a treasury of another branch write a row for each of
 * their journals, so the branch is known and not worked out. A journal that has such a row is one
 * of a pair that stands or falls together. The transfers of money and of stock that were posted
 * before leave no row: their other branch is read from their own documents.
 */
final class InterBranchLegs
{
    public const RECEIVABLE = 'receivable';
    public const PAYABLE    = 'payable';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function record(int $journalId, int $accountId, string $side, int $counterpartBranchId, string $documentType, int $documentId, int $revision): void
    {
        $this->db->table('accounting_interbranch_legs')->ignore(true)->insert([
            'journal_id'            => $journalId,
            'account_id'            => $accountId,
            'company_id'            => CompanyContext::id(),
            'side'                  => $side,
            'counterpart_branch_id' => $counterpartBranchId,
            'document_type'         => $documentType,
            'document_id'           => $documentId,
            'revision'              => $revision,
        ]);
    }

    public function isPaired(int $journalId): bool
    {
        return $this->db->table('accounting_interbranch_legs')->where('journal_id', $journalId)->countAllResults() > 0;
    }

    /**
     * The journals that stand with a journal: the others written for the same document in the same
     * revision, those that have not been reversed, the lower branch first.
     *
     * @return list<object{journal_id: string, branch_id: string, journal_date: string, source_type: string}>
     */
    public function partnersOf(int $journalId): array
    {
        $table = $this->db->prefixTable('accounting_interbranch_legs');
        $rows  = $this->db->query(
            "SELECT DISTINCT j.journal_id, j.branch_id, j.journal_date, j.source_type
             FROM {$table} mine
             JOIN {$table} other ON other.company_id = mine.company_id AND other.document_type = mine.document_type AND other.document_id = mine.document_id AND other.revision = mine.revision AND other.journal_id <> mine.journal_id
             JOIN " . $this->db->prefixTable('accounting_journals') . ' j ON j.journal_id = other.journal_id
             WHERE mine.journal_id = ? AND j.status = ?
             ORDER BY j.branch_id ASC, j.journal_id ASC',
            [$journalId, JournalStatus::Posted->value],
        );

        return $rows === false ? [] : $rows->getResult();
    }
}
