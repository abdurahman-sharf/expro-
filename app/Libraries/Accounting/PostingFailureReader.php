<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Reads the posting failure log, which PostingFailureLog writes after a failed posting has been
 * rolled back. Nothing here changes a row.
 */
final class PostingFailureReader
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * @param array{date_from?: string, date_to?: string, source_type?: string, branch_id?: int|string} $filters
     * @param list<int>|null                                                                            $branchIds The branches the reader may see, null for every branch.
     *
     * @return array{total: int, rows: list<object>}
     */
    public function search(array $filters, string $order = 'desc', int $limit = 25, int $offset = 0, ?array $branchIds = null): array
    {
        $builder = $this->db->table('accounting_posting_failures f')
            ->select("f.failure_id, f.source_type, f.source_id, f.revision, f.error_code, f.message, f.employee_id, f.branch_id, br.code AS branch_code, f.created_at, TRIM(CONCAT(p.first_name, ' ', p.last_name)) AS employee_name", false)
            ->join('people p', 'p.person_id = f.employee_id', 'left')
            ->join('branches br', 'br.branch_id = f.branch_id', 'left');

        if ($branchIds !== null) {
            $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('f.branch_id', $branchIds);
        }

        $branchId = (int) ($filters['branch_id'] ?? 0);

        if ($branchId > 0) {
            $builder->where('f.branch_id', $branchId);
        }

        $source = trim((string) ($filters['source_type'] ?? ''));

        if ($source !== '') {
            $builder->where('f.source_type', $source);
        }

        foreach (['date_from' => ['f.created_at >=', ' 00:00:00'], 'date_to' => ['f.created_at <=', ' 23:59:59']] as $key => [$column, $time]) {
            $date = $filters[$key] ?? '';

            if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
                $builder->where($column, $date . $time);
            }
        }

        $total = $builder->countAllResults(false);
        $dir   = strtolower($order) === 'asc' ? 'ASC' : 'DESC';
        $rows  = $builder->orderBy('f.created_at', $dir)->orderBy('f.failure_id', $dir)->limit(max(1, min($limit, 500)), $offset)->get()->getResult();

        return ['total' => $total, 'rows' => $rows];
    }

    /**
     * The kinds of document that have failed, for the source filter.
     *
     * @param list<int>|null $branchIds
     *
     * @return list<string>
     */
    public function sources(?array $branchIds = null): array
    {
        $builder = $this->db->table('accounting_posting_failures f')->distinct()->select('f.source_type')->orderBy('f.source_type', 'ASC');

        if ($branchIds !== null) {
            $branchIds === [] ? $builder->where('1 = 0', null, false) : $builder->whereIn('f.branch_id', $branchIds);
        }

        return array_column($builder->get()->getResultArray(), 'source_type');
    }
}
