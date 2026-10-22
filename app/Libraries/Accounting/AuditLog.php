<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class AuditLog
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array<string, mixed> $details
     */
    public function write(
        string $entityType,
        ?int $entityId,
        string $action,
        ?int $employeeId,
        JournalOrigin $origin,
        array $details = [],
        ?int $branchId = null,
    ): void {
        $this->db->table('accounting_audit_log')->insert([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'employee_id' => $employeeId,
            'branch_id'   => $branchId,
            'origin'      => $origin->value,
            'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array{date_from?: string, date_to?: string, action?: string, entity_type?: string, entity_id?: int|string, employee_id?: int|string, origin?: string, branch_id?: int|string} $filters
     * @param list<int>|null                                                                                                                                                                $branchIds The branches the reader may see, null for every branch. Entries that belong to no branch (the whole company) are for readers of every branch.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, string $order = 'desc', int $limit = 25, int $offset = 0, ?array $branchIds = null): array
    {
        $builder = $this->db->table('accounting_audit_log a')
            ->select("a.audit_id, a.entity_type, a.entity_id, a.action, a.employee_id, a.branch_id, br.code AS branch_code, a.origin, a.details, a.created_at, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS employee_name", false)
            ->join('people p', 'p.person_id = a.employee_id', 'left')
            ->join('branches br', 'br.branch_id = a.branch_id', 'left');

        $this->limitToBranches($builder, $branchIds);

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $builder->where('a.branch_id', $branchId);
        }

        foreach (['action' => 'a.action', 'entity_type' => 'a.entity_type', 'origin' => 'a.origin'] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));

            if ($value !== '') {
                $builder->where($column, $value);
            }
        }

        $entityId = (int) ($filters['entity_id'] ?? 0);

        if ($entityId > 0) {
            $builder->where('a.entity_id', $entityId);
        }

        $employeeId = (int) ($filters['employee_id'] ?? 0);

        if ($employeeId > 0) {
            $builder->where('a.employee_id', $employeeId);
        }

        foreach (['date_from' => ['a.created_at >=', ' 00:00:00'], 'date_to' => ['a.created_at <=', ' 23:59:59']] as $key => [$column, $time]) {
            $date = $filters[$key] ?? '';

            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $builder->where($column, $date . $time);
            }
        }

        $total = $builder->countAllResults(false);
        $dir   = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $rows  = $builder->orderBy('a.created_at', $dir)->orderBy('a.audit_id', $dir)->limit(max(1, min($limit, 500)), $offset)->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * @param list<int>|null $branchIds
     *
     * @return list<string>
     */
    public function actions(?array $branchIds = null): array
    {
        $builder = $this->db->table('accounting_audit_log a')->distinct()->select('a.action')->orderBy('a.action', 'ASC');

        $this->limitToBranches($builder, $branchIds);

        return array_column($builder->get()->getResultArray(), 'action');
    }

    /**
     * People who appear in the log, for the employee filter.
     *
     * @param list<int>|null $branchIds
     *
     * @return list<object>
     */
    public function employees(?array $branchIds = null): array
    {
        $builder = $this->db->table('accounting_audit_log a')
            ->select("a.employee_id, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS name", false)
            ->join('people p', 'p.person_id = a.employee_id')
            ->distinct()
            ->orderBy('name', 'ASC');

        $this->limitToBranches($builder, $branchIds);

        return $builder->get()->getResult();
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function limitToBranches(BaseBuilder $builder, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }

        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);

            return;
        }

        $builder->whereIn('a.branch_id', $branchIds);
    }
}
