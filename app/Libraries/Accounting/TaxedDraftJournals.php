<?php

namespace App\Libraries\Accounting;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The drafts of manual journals that carry a tax on a line, with what posting will add for it: a
 * tax on top of the amount of its line adds a line on the side of that line, so a draft that
 * balances as it was typed is out by that tax once it is posted, until its other side covers it.
 * A tax that is in the amount of its line adds nothing to the totals. It reads and changes nothing.
 */
final class TaxedDraftJournals
{
    private const SCALE = 4;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{journal_id: int, date: string, branch_id: int, description: string, debit: string, credit: string, tax_on_debit: string, tax_on_credit: string, difference_when_posted: string}>
     */
    public function list(): array
    {
        $journals = $this->db->prefixTable('accounting_journals');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $meta     = $this->db->prefixTable('accounting_journal_line_meta');

        $rows = $this->db->query(
            "SELECT j.journal_id, j.journal_date, j.branch_id, j.description, j.total_debit, j.total_credit,
                    COALESCE(SUM(CASE WHEN m.tax_included = 0 AND l.debit > 0 THEN m.tax_amount ELSE 0 END), 0) AS tax_on_debit,
                    COALESCE(SUM(CASE WHEN m.tax_included = 0 AND l.credit > 0 THEN m.tax_amount ELSE 0 END), 0) AS tax_on_credit
             FROM {$journals} j
             JOIN {$lines} l ON l.journal_id = j.journal_id
             JOIN {$meta} m ON m.line_id = l.line_id AND m.tax_amount > 0
             WHERE j.company_id = ? AND j.status = ? AND j.source_type = ?
             GROUP BY j.journal_id, j.journal_date, j.branch_id, j.description, j.total_debit, j.total_credit
             ORDER BY j.journal_date, j.journal_id",
            [CompanyContext::id(), JournalStatus::Draft->value, JournalDraft::SOURCE_MANUAL],
        )->getResult();

        return array_map(static function (object $row): array {
            $onDebit  = bcadd((string) $row->tax_on_debit, '0', self::SCALE);
            $onCredit = bcadd((string) $row->tax_on_credit, '0', self::SCALE);

            return [
                'journal_id'             => (int) $row->journal_id,
                'date'                   => (string) $row->journal_date,
                'branch_id'              => (int) $row->branch_id,
                'description'            => (string) ($row->description ?? ''),
                'debit'                  => bcadd((string) $row->total_debit, '0', self::SCALE),
                'credit'                 => bcadd((string) $row->total_credit, '0', self::SCALE),
                'tax_on_debit'           => $onDebit,
                'tax_on_credit'          => $onCredit,
                'difference_when_posted' => bcsub(bcadd((string) $row->total_debit, $onDebit, self::SCALE), bcadd((string) $row->total_credit, $onCredit, self::SCALE), self::SCALE),
            ];
        }, $rows);
    }
}
