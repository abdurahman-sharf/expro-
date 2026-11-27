<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\AuditLog;
use App\Libraries\Accounting\JournalOrigin;
use App\Libraries\Organization\CompanyContext;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The mode each operation is posted in. An operation with no mode of its own is posted by the code of
 * its document. A change of mode is written in the audit log with the employee who made it.
 */
final class PostingModes
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function of(string $operation): PostingMode
    {
        $row = $this->db->table('accounting_posting_modes')->select('mode')->where(['company_id' => CompanyContext::id(), 'operation' => $operation])->get()->getRow();

        return $row === null ? PostingMode::Legacy : (PostingMode::tryFrom((string) $row->mode) ?? PostingMode::Legacy);
    }

    /**
     * @return array<string, PostingMode>
     */
    public function all(): array
    {
        $modes = [];

        foreach ($this->db->table('accounting_posting_modes')->where('company_id', CompanyContext::id())->orderBy('operation', 'ASC')->get()->getResult() as $row) {
            $modes[(string) $row->operation] = PostingMode::tryFrom((string) $row->mode) ?? PostingMode::Legacy;
        }

        return $modes;
    }

    public function set(string $operation, PostingMode $mode, int $employeeId): void
    {
        $before = $this->of($operation);

        if ($before === $mode) {
            return;
        }

        $this->db->table('accounting_posting_modes')->replace([
            'company_id' => CompanyContext::id(),
            'operation'  => $operation,
            'mode'       => $mode->value,
            'changed_by' => $employeeId,
            'changed_at' => date('Y-m-d H:i:s'),
        ]);

        (new AuditLog($this->db))->write('posting_mode', null, 'changed', $employeeId, JournalOrigin::User, ['operation' => $operation, 'before' => $before->value, 'after' => $mode->value]);
    }
}
