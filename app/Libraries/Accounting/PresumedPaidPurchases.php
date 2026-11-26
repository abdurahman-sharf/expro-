<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The purchases the books hold as paid in full, and the returns they hold as refunded in full, at the
 * moment they were saved, with no payment of their own recorded against them. Until money needed an
 * amount typed to move, a purchase or a return saved with the amount left empty was booked this way,
 * and so was one whose typed amount covered it: the two were stored alike, so this lists both for
 * somebody to go through. It reads and changes nothing.
 */
final class PresumedPaidPurchases
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @return list<array{receiving_id: int, kind: string, number: string, date: string, supplier: string, amount: string, treasury: string, payment_type: string}>
     */
    public function list(): array
    {
        $rows = $this->db->table('accounting_journals j')
            ->select('r.receiving_id, r.receiving_type, n.number, COALESCE(r.invoice_date, DATE(r.receiving_time)) AS purchase_date, s.company_name, j.total_debit, t.name AS treasury, r.payment_type', false)
            ->join('receivings r', 'r.receiving_id = j.source_id')
            ->join('document_numbers n', "n.doc_type IN ('" . DocumentType::Purchase->value . "', '" . DocumentType::DebitNote->value . "') AND n.source_table = 'receivings' AND n.source_id = r.receiving_id", 'left')
            ->join('suppliers s', 's.person_id = r.supplier_id', 'left')
            ->join('treasuries t', 't.treasury_id = r.treasury_id', 'left')
            ->where('j.company_id', CompanyContext::id())
            ->where('j.source_type', ReceivingPostingBuilder::SOURCE_PAYMENT)
            ->where('j.status', JournalStatus::Posted->value)
            ->groupStart()->where('r.receiving_type', null)->orWhereIn('r.receiving_type', [ReceivingType::Receive->value, ReceivingType::Return->value])->groupEnd()
            ->orderBy('purchase_date', 'ASC')
            ->orderBy('r.receiving_id', 'ASC')
            ->get()
            ->getResult();

        return array_map(static fn (object $row): array => [
            'receiving_id' => (int) $row->receiving_id,
            'kind'         => $row->receiving_type === ReceivingType::Return->value ? 'return' : 'purchase',
            'number'       => (string) ($row->number ?? ''),
            'date'         => (string) $row->purchase_date,
            'supplier'     => (string) ($row->company_name ?? ''),
            'amount'       => bcadd((string) $row->total_debit, '0', 2),
            'treasury'     => (string) ($row->treasury ?? ''),
            'payment_type' => (string) ($row->payment_type ?? ''),
        ], $rows);
    }
}
