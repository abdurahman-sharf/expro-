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
        $this->recordUnbuilt($draft->sourceType, $draft->sourceId, $draft->revision, $draft->entryType, $failure, $draft->employeeId, $draft->branchId);
    }

    /**
     * For a journal that could not even be drafted, because an account it needs could not be
     * decided. The failure shows in the failure log like any other.
     */
    public function recordUnbuilt(string $sourceType, ?int $sourceId, int $revision, JournalEntryType $entryType, PostingException $failure, ?int $employeeId = null, ?int $branchId = null): void
    {
        try {
            $this->db->table('accounting_posting_failures')->insert([
                'source_type' => $sourceType,
                'source_id'   => $sourceId,
                'entry_type'  => $entryType->value,
                'revision'    => $revision,
                'error_code'  => $failure->error->value,
                'message'     => $failure->getMessage(),
                'context'     => $failure->context === [] ? null : json_encode($failure->context, JSON_UNESCAPED_UNICODE),
                'employee_id' => $employeeId,
                'branch_id'   => $branchId,
            ]);
        } catch (Throwable $exception) {
            log_message('error', 'Accounting posting failure could not be recorded: ' . $exception->getMessage());
        }
    }
}
