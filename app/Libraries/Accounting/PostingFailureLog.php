<?php

namespace App\Libraries\Accounting;

use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Must be called after the failed transaction has rolled back. A row written
 * inside that transaction would be rolled back with it.
 */
final class PostingFailureLog
{
    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function record(JournalDraft $draft, PostingException $failure): void
    {
        try {
            $this->db->table('accounting_posting_failures')->insert([
                'source_type' => $draft->sourceType,
                'source_id'   => $draft->sourceId,
                'revision'    => $draft->revision,
                'error_code'  => $failure->error->value,
                'message'     => $failure->getMessage(),
                'context'     => $failure->context === [] ? null : json_encode($failure->context, JSON_UNESCAPED_UNICODE),
                'employee_id' => $draft->employeeId,
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Accounting posting failure could not be recorded: ' . $exception->getMessage());
        }
    }
}
