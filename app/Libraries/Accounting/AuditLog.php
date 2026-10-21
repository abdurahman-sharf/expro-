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
}
