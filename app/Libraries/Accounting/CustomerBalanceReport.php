<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The balance of every customer over a period, split by what moved it. A journal on the account of
 * a customer is an invoice (sales), a credit note (settlements, negative), a payment (payments, a
 * refund counting negative) or something else (other); a journal that reverses another counts as
 * what it reverses. The balance before is the account at the end of the day before the period, and
 * the balance is the account at the end of the last day, so each equals what the ledger says.
 *
 * A sales debit note goes under sales, as it raises what the customer owes the way an invoice does.
 *
 * A return issued as a refund invoice goes under returns; a return issued as a credit note sits
 * under settlements, as the reference system shows a credit note.
 */
final class CustomerBalanceReport
{
    private const PERIOD_START = '1000-01-01';

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function build(ReportPeriod $period, bool $hideEmpty = true): BalanceTable
    {
        $from     = $period->from ?? self::PERIOD_START;
        $accounts = $this->db->prefixTable('accounting_accounts');
        $lines    = $this->db->prefixTable('accounting_journal_lines');
        $journals = $this->db->prefixTable('accounting_journals');
        $sales    = $this->db->prefixTable('sales');

        $rows = $this->db->query(
            "SELECT t.party_id, t.party_name, t.account_id,
                    COALESCE(SUM(CASE WHEN t.day < ? THEN t.amount END), 0) AS before_balance,
                    COALESCE(SUM(CASE WHEN t.day >= ? AND t.kind = 'sales' THEN t.amount END), 0) AS sales,
                    COALESCE(SUM(CASE WHEN t.day >= ? AND t.kind = 'returns' THEN t.amount END), 0) AS returns,
                    COALESCE(SUM(CASE WHEN t.day >= ? AND t.kind = 'payments' THEN 0 - t.amount END), 0) AS payments,
                    COALESCE(SUM(CASE WHEN t.day >= ? AND t.kind = 'settlements' THEN t.amount END), 0) AS settlements,
                    COALESCE(SUM(CASE WHEN t.day >= ? AND t.kind = 'other' THEN t.amount END), 0) AS other,
                    COALESCE(SUM(t.amount), 0) AS balance
             FROM (
                 SELECT a.entity_id AS party_id, a.name AS party_name, a.account_id, j.journal_date AS day, l.debit - l.credit AS amount,
                        CASE
                            WHEN IF(j.reverses_journal_id IS NULL, j.source_type, o.source_type) = ? AND s.sale_type = ? AND s.return_document IS NULL THEN 'settlements'
                            WHEN IF(j.reverses_journal_id IS NULL, j.source_type, o.source_type) = ? AND s.sale_type = ? THEN 'returns'
                            WHEN IF(j.reverses_journal_id IS NULL, j.source_type, o.source_type) IN (?, ?) THEN 'sales'
                            WHEN IF(j.reverses_journal_id IS NULL, j.source_type, o.source_type) IN (?, ?, ?) THEN 'payments'
                            ELSE 'other'
                        END AS kind
                 FROM {$accounts} a
                 JOIN {$lines} l ON l.account_id = a.account_id
                 JOIN {$journals} j ON j.journal_id = l.journal_id AND j.status IN (?, ?) AND j.journal_date <= ?
                 LEFT JOIN {$journals} o ON o.journal_id = j.reverses_journal_id
                 LEFT JOIN {$sales} s ON IF(j.reverses_journal_id IS NULL, j.source_type, o.source_type) = ? AND s.sale_id = IF(j.reverses_journal_id IS NULL, j.source_id, o.source_id)
                 WHERE a.entity_type = ?
             ) t
             GROUP BY t.account_id, t.party_id, t.party_name
             ORDER BY t.party_name ASC, t.party_id ASC",
            [
                $from, $from, $from, $from, $from, $from,
                SalePostingBuilder::SOURCE_SALE, SALE_TYPE_RETURN, SalePostingBuilder::SOURCE_SALE, SALE_TYPE_RETURN, SalePostingBuilder::SOURCE_SALE, SalesDebitNotes::SOURCE, SalePostingBuilder::SOURCE_PAYMENT, CustomerAdvances::SOURCE, CreditRefunds::CUSTOMER_SOURCE,
                JournalStatus::Posted->value, JournalStatus::Reversed->value, $period->to,
                SalePostingBuilder::SOURCE_SALE,
                EntityAccountService::CUSTOMER,
            ],
        )->getResult();

        $table = [];

        foreach ($rows as $row) {
            $line = new BalanceRow(
                (int) $row->party_id,
                (string) $row->party_name,
                (int) $row->account_id,
                bcadd((string) $row->before_balance, '0', SaleAmounts::SCALE),
                bcadd((string) $row->sales, '0', SaleAmounts::SCALE),
                bcadd((string) $row->returns, '0', SaleAmounts::SCALE),
                bcadd((string) $row->payments, '0', SaleAmounts::SCALE),
                bcadd((string) $row->settlements, '0', SaleAmounts::SCALE),
                bcadd((string) $row->other, '0', SaleAmounts::SCALE),
                bcadd((string) $row->balance, '0', SaleAmounts::SCALE),
            );

            if (! $hideEmpty || ! $line->isEmpty()) {
                $table[] = $line;
            }
        }

        return new BalanceTable($from === self::PERIOD_START ? '' : $from, $period->to, $table);
    }
}
