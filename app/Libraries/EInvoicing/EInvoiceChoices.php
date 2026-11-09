<?php

namespace App\Libraries\EInvoicing;

use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\BranchGuard;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * What the form of a debit note document offers to choose from, so that nobody types a number: the debit
 * notes that stand and have no document yet, and the invoices and credit notes that were issued to their
 * customers. Both lists are cut to the branches the employee works in and, for someone who sees their own
 * records only, to the records they made.
 */
final class EInvoiceChoices
{
    private BaseConnection $db;
    private BranchGuard $branches;
    private RecordScope $scope;

    public function __construct(?BaseConnection $db = null, ?BranchGuard $branches = null)
    {
        $this->db       = $db ?? Database::connect();
        $this->branches = $branches ?? new BranchGuard($this->db);
        $this->scope    = new RecordScope($this->db);
    }

    /**
     * @return list<array{debit_note_id: int, number: string, customer_id: int, customer: string, total: string, date: string}> The newest first.
     */
    public function notesWaiting(?int $employeeId, int $limit = 100): array
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);

        if ($allowed === []) {
            return [];
        }

        $mine    = $this->scope->limit($employeeId);
        $builder = $this->db->table('sales_debit_notes n')
            ->select("n.debit_note_id, n.customer_id, n.total, n.note_date, c.company_name, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS person_name", false)
            ->join('people p', 'p.person_id = n.customer_id', 'left')
            ->join('customers c', 'c.person_id = n.customer_id', 'left')
            ->join('einvoice_documents d', "d.company_id = n.company_id AND d.source_type = '" . EInvoiceService::SOURCE_NOTE . "' AND d.source_id = n.debit_note_id", 'left')
            ->where(['n.company_id' => CompanyContext::id(), 'n.status' => 'posted'])
            ->where('d.document_id IS NULL', null, false)
            ->orderBy('n.debit_note_id', 'DESC')
            ->limit(max(1, $limit));

        if ($allowed !== null) {
            $builder->whereIn('n.branch_id', $allowed);
        }

        if ($mine !== null) {
            $builder->where('n.created_by', $mine);
        }

        $rows    = $builder->get()->getResult();
        $numbers = (new DocumentNumbering($this->db))->numbers(DocumentType::SalesDebitNote, 'sales_debit_notes', array_map(static fn (object $row): int => (int) $row->debit_note_id, $rows));
        $notes   = [];

        foreach ($rows as $row) {
            $notes[] = [
                'debit_note_id' => (int) $row->debit_note_id,
                'number'        => $numbers[(int) $row->debit_note_id] ?? DocumentType::SalesDebitNote->fallback((int) $row->debit_note_id),
                'customer_id'   => (int) $row->customer_id,
                'customer'      => trim((string) $row->company_name) !== '' ? (string) $row->company_name : (string) $row->person_name,
                'total'         => (string) $row->total,
                'date'          => (string) $row->note_date,
            ];
        }

        return $notes;
    }

    /**
     * @param list<int> $customerIds
     *
     * @return list<array{document_id: int, number: string, kind: EInvoiceKind, customer_id: int}> The newest first.
     */
    public function originalsOf(array $customerIds, ?int $employeeId, int $limit = 500): array
    {
        $allowed = $this->branches->allowedBranchIds($employeeId);

        if ($customerIds === [] || $allowed === []) {
            return [];
        }

        $mine    = $this->scope->limit($employeeId);
        $builder = $this->db->table('einvoice_documents d')
            ->select('d.document_id, d.document_number, d.doc_kind, s.customer_id')
            ->join('sales s', "d.source_type = '" . EInvoiceService::SOURCE_SALE . "' AND s.sale_id = d.source_id")
            ->where('d.company_id', CompanyContext::id())
            ->whereIn('s.customer_id', array_values(array_unique($customerIds)))
            ->orderBy('d.document_id', 'DESC')
            ->limit(max(1, $limit));

        if ($allowed !== null) {
            $builder->whereIn('d.branch_id', $allowed);
        }

        if ($mine !== null) {
            $builder->where('s.employee_id', $mine);
        }

        $documents = [];

        foreach ($builder->get()->getResult() as $row) {
            $documents[] = [
                'document_id' => (int) $row->document_id,
                'number'      => (string) $row->document_number,
                'kind'        => EInvoiceKind::from((string) $row->doc_kind),
                'customer_id' => (int) $row->customer_id,
            ];
        }

        return $documents;
    }
}
