<?php

namespace App\Libraries\Accounting\Bank;

use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The date up to which the reconciliation of a bank account is closed, which is the latest date of
 * the closings that have not been reopened, or none.
 */
final class ReconciliationLock
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function through(int $treasuryId): ?string
    {
        $row = $this->db->table('bank_reconciliations')
            ->selectMax('closed_through', 'through')
            ->where(['company_id' => CompanyContext::id(), 'treasury_id' => $treasuryId, 'status' => 'closed'])
            ->get()
            ->getRow();

        return $row === null || $row->through === null ? null : (string) $row->through;
    }
}
