<?php

namespace App\Libraries\Accounting;

use App\Libraries\Numbering\DocumentType;
use App\Libraries\Organization\CompanyContext;
use App\Libraries\Roles\RecordScope;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Every payment made to a supplier as a document of its own: the payments made against a purchase
 * after it was saved, and the payments made to a supplier that belong to no purchase. Each has a
 * number (the one its sequence gave it, or for a payment saved before there were numbers its id),
 * the day it is posted on (the date entered, or for a payment saved before dates could be entered
 * the day it was saved), and the amount, which is negative for money the supplier gave back. The
 * payment made at once when a purchase is saved is a line of that purchase and is not listed. What
 * is read is cut down to the branches given, and a payment is of the branch of its purchase, or of
 * its own branch when it belongs to none.
 */
final class SupplierPaymentList
{
    public const PURCHASE = 'purchase';
    public const ADVANCE  = 'advance';
    public const REFUND   = 'refund';
    private const SORTS   = [
        'date'          => 'd.doc_date',
        'number'        => 'COALESCE(n.sequence_number, d.source_id)',
        'supplier_name' => 'supplier_name',
        'amount'        => 'd.amount',
    ];

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param list<int>|null       $branchIds Null reads every branch, none reads nothing.
     * @param array<string, mixed> $filters   search, date_from, date_to, supplier_id, payment_type, status, treasury_id, employee_id and kind; anything that is not what a filter takes is left out.
     * @param int|null             $viewerId  The reader, who sees only the payments they made when they see their own records only. The employee of the session when not given.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(?array $branchIds, array $filters = [], string $sort = 'date', string $order = 'desc', int $limit = 25, int $offset = 0, ?int $viewerId = null): array
    {
        [$where, $bindings] = $this->conditions($branchIds, $filters, $viewerId);

        $from  = $this->from();
        $total = (int) $this->db->query("SELECT COUNT(*) AS found {$from['sql']} {$where}", [...$from['bindings'], ...$bindings])->getRow()->found;

        $column = self::SORTS[$sort] ?? self::SORTS['date'];
        $way    = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $paging = ' LIMIT ' . max(1, $limit) . ' OFFSET ' . max(0, $offset);

        $rows = $this->db->query(
            "SELECT d.*, n.number AS number, pn.number AS purchase_number,
                    {$this->supplierName()} AS supplier_name, TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS employee_name,
                    t.name AS treasury_name, b.code AS branch_code
             {$from['sql']} {$where}
             ORDER BY {$column} {$way}, d.doc_date DESC, d.kind ASC, d.source_id DESC{$paging}",
            [...$from['bindings'], ...$bindings],
        )->getResult();

        foreach ($rows as $row) {
            $row->numbered = $row->number !== null ? 1 : 0;
            $row->number ??= (string) $row->source_id;
        }

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The payment methods the payments in the books were made with, for the filter.
     *
     * @return list<string>
     */
    public function paymentTypes(): array
    {
        $rows = $this->db->query(
            'SELECT payment_type FROM ' . $this->db->prefixTable('receivings_payments') . ' UNION SELECT payment_type FROM ' . $this->db->prefixTable('supplier_advances')
            . ' WHERE payment_type IS NOT NULL AND kind = ? ORDER BY payment_type',
            [SupplierAdvances::PAYMENT],
        )->getResultArray();

        return array_values(array_filter(array_map(static fn (array $row): string => (string) $row['payment_type'], $rows), static fn (string $type): bool => $type !== ''));
    }

    /**
     * The employees who made the payments that are read, for the filter.
     *
     * @param list<int>|null $branchIds
     *
     * @return list<object{employee_id: int, name: string}>
     */
    public function payers(?array $branchIds, ?int $viewerId = null): array
    {
        [$where, $bindings] = $this->conditions($branchIds, [], $viewerId);

        $from = $this->from();
        $rows = $this->db->query(
            "SELECT DISTINCT d.employee_id, TRIM(CONCAT(COALESCE(ep.first_name, ''), ' ', COALESCE(ep.last_name, ''))) AS name
             {$from['sql']} " . ($where === '' ? 'WHERE' : $where . ' AND') . ' d.employee_id IS NOT NULL ORDER BY name',
            [...$from['bindings'], ...$bindings],
        )->getResult();

        return array_map(static fn (object $row): object => (object) ['employee_id' => (int) $row->employee_id, 'name' => (string) $row->name], $rows);
    }

    /**
     * @return array{sql: string, bindings: list<int|string>}
     */
    private function from(): array
    {
        $payments   = $this->db->prefixTable('receivings_payments');
        $receivings = $this->db->prefixTable('receivings');
        $advances   = $this->db->prefixTable('supplier_advances');
        $numbers    = $this->db->prefixTable('document_numbers');
        $accounts   = $this->db->prefixTable('accounting_accounts');
        $company    = CompanyContext::id();

        $sql = "FROM (
                    SELECT 'purchase' AS kind, p.payment_id AS source_id, p.receiving_id AS receiving_id, r.supplier_id AS supplier_id,
                           COALESCE(p.payment_date, DATE(p.payment_time)) AS doc_date, p.payment_date IS NOT NULL AS date_entered, p.payment_time AS saved_at,
                           p.payment_amount AS amount, p.payment_type AS payment_type, p.treasury_id AS treasury_id, p.employee_id AS employee_id,
                           p.payment_status AS status, p.reference_code AS reference_code, r.branch_id AS branch_id
                    FROM {$payments} p
                    JOIN {$receivings} r ON r.receiving_id = p.receiving_id
                    UNION ALL
                    SELECT 'advance', a.advance_id, NULL, a.supplier_id, a.advance_date, 1, a.created_at, a.amount, a.payment_type, a.treasury_id, a.created_by, a.status, a.reference_code, a.branch_id
                    FROM {$advances} a
                    WHERE a.kind = ? AND a.company_id = ?
                ) d
                LEFT JOIN {$numbers} n ON n.company_id = ? AND n.doc_type = ? AND n.source_table = CASE d.kind WHEN 'purchase' THEN 'receivings_payments' ELSE 'supplier_advances' END AND n.source_id = d.source_id
                LEFT JOIN {$numbers} pn ON pn.company_id = ? AND pn.doc_type = ? AND pn.source_table = 'receivings' AND pn.source_id = d.receiving_id
                LEFT JOIN {$accounts} sa ON sa.company_id = ? AND sa.entity_type = ? AND sa.entity_id = d.supplier_id
                LEFT JOIN " . $this->db->prefixTable('suppliers') . ' s ON s.person_id = d.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' sp ON sp.person_id = d.supplier_id
                LEFT JOIN ' . $this->db->prefixTable('people') . ' ep ON ep.person_id = d.employee_id
                LEFT JOIN ' . $this->db->prefixTable('treasuries') . ' t ON t.treasury_id = d.treasury_id
                LEFT JOIN ' . $this->db->prefixTable('branches') . ' b ON b.branch_id = d.branch_id';

        return [
            'sql'      => $sql,
            'bindings' => [SupplierAdvances::PAYMENT, $company, $company, DocumentType::SupplierPayment->value, $company, DocumentType::Purchase->value, $company, EntityAccountService::SUPPLIER],
        ];
    }

    private function supplierName(): string
    {
        return "COALESCE(sa.name, NULLIF(s.company_name, ''), TRIM(CONCAT(COALESCE(sp.first_name, ''), ' ', COALESCE(sp.last_name, ''))))";
    }

    /**
     * @param list<int>|null       $branchIds
     * @param array<string, mixed> $filters
     *
     * @return array{string, list<int|string>}
     */
    private function conditions(?array $branchIds, array $filters, ?int $viewerId): array
    {
        $where    = [];
        $bindings = [];
        $mine     = (new RecordScope($this->db))->limit($viewerId ?? RecordScope::sessionEmployee());

        if ($mine !== null) {
            $where[]    = 'd.employee_id = ?';
            $bindings[] = $mine;
        }

        if ($branchIds !== null) {
            $ids     = array_values(array_unique(array_map('intval', $branchIds)));
            $where[] = $ids === [] ? '1 = 0' : 'd.branch_id IN (' . implode(', ', $ids) . ')';
        }

        $search = $this->text($filters['search'] ?? null);

        if ($search !== '') {
            $like     = '%' . $this->db->escapeLikeString($search) . '%';
            $escape   = "ESCAPE '!'";
            $where[]  = "(n.number LIKE ? {$escape} OR (n.number IS NULL AND CAST(d.source_id AS CHAR) LIKE ? {$escape}) OR {$this->supplierName()} LIKE ? {$escape} OR d.reference_code LIKE ? {$escape} OR pn.number LIKE ? {$escape} OR CONCAT('RECV ', d.receiving_id) LIKE ? {$escape})";
            $bindings = [...$bindings, $like, $like, $like, $like, $like, $like];
        }

        $from = $this->day($filters['date_from'] ?? null);
        $to   = $this->day($filters['date_to'] ?? null);

        if ($from !== null) {
            $where[]    = 'd.doc_date >= ?';
            $bindings[] = $from;
        }

        if ($to !== null) {
            $where[]    = 'd.doc_date <= ?';
            $bindings[] = $to;
        }

        foreach (['supplier_id' => 'd.supplier_id', 'treasury_id' => 'd.treasury_id', 'employee_id' => 'd.employee_id'] as $name => $column) {
            $id = $this->id($filters[$name] ?? null);

            if ($id > 0) {
                $where[]    = "{$column} = ?";
                $bindings[] = $id;
            }
        }

        $type = $this->text($filters['payment_type'] ?? null);

        if ($type !== '') {
            $where[]    = 'd.payment_type = ?';
            $bindings[] = $type;
        }

        $status = $this->text($filters['status'] ?? null);

        if ($status !== '') {
            $where[]    = 'd.status = ?';
            $bindings[] = $status;
        }

        $kind = $this->text($filters['kind'] ?? null);

        if ($kind === self::PURCHASE || $kind === self::ADVANCE) {
            $where[]    = 'd.kind = ?';
            $bindings[] = $kind;
        } elseif ($kind === self::REFUND) {
            $where[] = 'd.amount < 0';
        }

        return [$where === [] ? '' : 'WHERE ' . implode(' AND ', $where), $bindings];
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    private function id(mixed $value): int
    {
        return is_string($value) || is_int($value) ? (int) $value : 0;
    }

    private function day(mixed $value): ?string
    {
        $text = $this->text($value);

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $parts) !== 1 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            return null;
        }

        return $text;
    }
}
