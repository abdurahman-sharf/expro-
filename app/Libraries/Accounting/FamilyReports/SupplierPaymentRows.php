<?php

namespace App\Libraries\Accounting\FamilyReports;

use App\Libraries\Accounting\CreditRefunds;
use App\Libraries\Accounting\JournalEntryType;
use App\Libraries\Accounting\JournalStatus;
use App\Libraries\Accounting\ReceivingPostingBuilder;
use App\Libraries\Accounting\ReportPeriod;
use App\Libraries\Accounting\ReportScope;
use App\Libraries\Accounting\SupplierAdvances;
use App\Libraries\Numbering\DocumentNumbering;
use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Every payment to a supplier that stands in the books, from the four places money leaves for a
 * supplier: the payment made when a purchase is saved, the payments made against a purchase after
 * that, a payment that belongs to no purchase, and what a supplier gave back in cash. What came
 * back is negative. A payment is of the branch of its purchase, or of its own when it has none.
 */
final class SupplierPaymentRows
{
    public const SAVED   = 'saved';
    public const PAYMENT = 'payment';
    public const ADVANCE = 'advance';
    public const REFUND  = 'refund';
    public const SOURCES = [ReceivingPostingBuilder::SOURCE_PAYMENT, ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, SupplierAdvances::SOURCE, CreditRefunds::SUPPLIER_SOURCE];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{supplier_id?: int, employee_id?: int, payment_type?: string} $filters
     *
     * @return list<object{kind: string, source_id: int, receiving_id: ?int, supplier_id: int, supplier_name: string, doc_date: string, amount: string, payment_type: string, treasury_name: string, employee_id: ?int, employee_name: string, reference_code: string, branch_code: string, number: string}>
     */
    public function read(ReportScope $scope, ReportPeriod $period, ?int $viewerId = null, array $filters = []): array
    {
        if ($scope->isEmpty()) {
            return [];
        }

        $journals = $this->db->prefixTable('accounting_journals');
        $standing = static fn (string $source, string $key): string => "JOIN {$journals} j ON j.company_id = ? AND j.source_type = '{$source}' AND j.source_id = {$key} AND j.status = ? AND j.entry_type = ?";
        $company  = CompanyContext::id();
        $posted   = [$company, JournalStatus::Posted->value, JournalEntryType::General->value];

        $sql = "SELECT d.*, COALESCE(NULLIF(s.company_name, ''), TRIM(CONCAT(COALESCE(sp.first_name, ''), ' ', COALESCE(sp.last_name, '')))) AS supplier_name,
                       TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name, t.name AS treasury_name, b.code AS branch_code
                FROM (
                    SELECT 'saved' AS kind, r.receiving_id AS source_id, r.receiving_id AS receiving_id, r.supplier_id AS supplier_id, j.journal_date AS doc_date,
                           CASE WHEN r.receiving_type = 'return' THEN 0 - j.total_debit ELSE j.total_debit END AS amount,
                           r.payment_type AS payment_type, r.treasury_id AS treasury_id, r.employee_id AS employee_id, r.reference AS reference_code, r.branch_id AS branch_id
                    FROM " . $this->db->prefixTable('receivings') . " r
                    {$standing(ReceivingPostingBuilder::SOURCE_PAYMENT, 'r.receiving_id')}
                    WHERE r.supplier_id IS NOT NULL AND r.receiving_type IN ('receive', 'return')
                    UNION ALL
                    SELECT 'payment', p.payment_id, p.receiving_id, r.supplier_id, COALESCE(p.payment_date, DATE(p.payment_time)), p.payment_amount,
                           p.payment_type, p.treasury_id, p.employee_id, p.reference_code, r.branch_id
                    FROM " . $this->db->prefixTable('receivings_payments') . ' p
                    JOIN ' . $this->db->prefixTable('receivings') . " r ON r.receiving_id = p.receiving_id
                    {$standing(ReceivingPostingBuilder::SOURCE_SUPPLIER_PAYMENT, 'p.payment_id')}
                    UNION ALL
                    SELECT 'advance', a.advance_id, NULL, a.supplier_id, a.advance_date, a.amount, a.payment_type, a.treasury_id, a.created_by, a.reference_code, a.branch_id
                    FROM " . $this->db->prefixTable('supplier_advances') . " a
                    {$standing(SupplierAdvances::SOURCE, 'a.advance_id')}
                    WHERE a.kind = ?
                    UNION ALL
                    SELECT 'refund', f.refund_id, NULL, f.supplier_id, f.refund_date, 0 - f.amount, f.payment_type, f.treasury_id, f.created_by, f.reference_code, f.branch_id
                    FROM " . $this->db->prefixTable('supplier_credit_refunds') . " f
                    {$standing(CreditRefunds::SUPPLIER_SOURCE, 'f.refund_id')}
                ) d
                LEFT JOIN " . $this->db->prefixTable('suppliers') . ' s ON s.person_id = d.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' sp ON sp.person_id = d.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' ep ON ep.person_id = d.employee_id
                LEFT JOIN ' . $this->db->prefixTable('treasuries') . ' t ON t.treasury_id = d.treasury_id
                LEFT JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = d.branch_id
                WHERE ' . ScopeSql::branches('d.branch_id', $scope) . ' AND d.doc_date >= ? AND d.doc_date <= ?';

        $bindings = [...$posted, ...$posted, ...$posted, SupplierAdvances::PAYMENT, ...$posted, $period->from ?? '1000-01-01', $period->to];

        [$mine, $mineBindings] = (new RecordScope($this->db))->condition('d.employee_id', $viewerId ?? RecordScope::sessionEmployee());

        if ($mine !== '') {
            $sql .= " AND {$mine}";
            $bindings = [...$bindings, ...$mineBindings];
        }

        foreach (['supplier_id' => 'd.supplier_id', 'employee_id' => 'd.employee_id'] as $name => $column) {
            if (($filters[$name] ?? 0) > 0) {
                $sql .= " AND {$column} = ?";
                $bindings[] = (int) $filters[$name];
            }
        }

        if (($filters['payment_type'] ?? '') !== '') {
            $sql .= ' AND d.payment_type = ?';
            $bindings[] = $filters['payment_type'];
        }

        $rows    = $this->db->query($sql . ' ORDER BY d.doc_date ASC, d.kind ASC, d.source_id ASC', $bindings)->getResult();
        $numbers = new DocumentNumbering($this->db);

        $payments = $numbers->numbers(DocumentType::SupplierPayment, 'receivings_payments', array_map(static fn (object $row): int => (int) $row->source_id, array_filter($rows, static fn (object $row): bool => $row->kind === self::PAYMENT)));
        $advances = $numbers->numbers(DocumentType::SupplierPayment, 'supplier_advances', array_map(static fn (object $row): int => (int) $row->source_id, array_filter($rows, static fn (object $row): bool => $row->kind === self::ADVANCE)));
        $saved    = $numbers->numbers(DocumentType::Purchase, 'receivings', array_map(static fn (object $row): int => (int) $row->source_id, array_filter($rows, static fn (object $row): bool => $row->kind === self::SAVED)));

        foreach ($rows as $row) {
            $row->number = match ($row->kind) {
                self::PAYMENT => $payments[(int) $row->source_id] ?? DocumentType::SupplierPayment->fallback((int) $row->source_id),
                self::ADVANCE => $advances[(int) $row->source_id] ?? DocumentType::SupplierPayment->fallback((int) $row->source_id),
                self::SAVED   => $saved[(int) $row->source_id] ?? DocumentType::Purchase->fallback((int) $row->source_id),
                default       => (string) $row->source_id,
            };
            $row->amount         = bcadd((string) $row->amount, '0', 4);
            $row->employee_name  = trim((string) $row->employee_name);
            $row->reference_code = (string) ($row->reference_code ?? '');
            $row->payment_type   = (string) ($row->payment_type ?? '');
            $row->treasury_name  = (string) ($row->treasury_name ?? '');
            $row->branch_code    = (string) ($row->branch_code ?? '');
        }

        return $rows;
    }
}
