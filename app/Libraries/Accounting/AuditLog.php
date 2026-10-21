<?php

namespace App\Libraries\Accounting;

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
    ): void {
        $this->db->table('accounting_audit_log')->insert([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'action'      => $action,
            'employee_id' => $employeeId,
            'origin'      => $origin->value,
            'details'     => $details === [] ? null : json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array{date_from?: string, date_to?: string, action?: string, entity_type?: string, employee_id?: int|string, origin?: string} $filters
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, string $order = 'desc', int $limit = 25, int $offset = 0): array
    {
        $builder = $this->db->table('accounting_audit_log a')
            ->select("a.audit_id, a.entity_type, a.entity_id, a.action, a.employee_id, a.origin, a.details, a.created_at, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS employee_name", false)
            ->join('people p', 'p.person_id = a.employee_id', 'left');

        foreach (['action' => 'a.action', 'entity_type' => 'a.entity_type', 'origin' => 'a.origin'] as $key => $column) {
            $value = trim((string) ($filters[$key] ?? ''));

            if ($value !== '') {
                $builder->where($column, $value);
            }
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
     * @return list<string>
     */
    public function actions(): array
    {
        return array_column($this->db->table('accounting_audit_log')->distinct()->select('action')->orderBy('action', 'ASC')->get()->getResultArray(), 'action');
    }
}
