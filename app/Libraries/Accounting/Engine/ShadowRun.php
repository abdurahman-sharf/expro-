<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\JournalDraft;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Throwable;

/**
 * Checks the journal the code of a document posts against the one the engine builds, for an operation
 * in shadow mode, without ever changing what is saved. Whatever the engine throws is written down as
 * a failure of the engine. A failed query of the engine would leave the transaction of the save marked
 * to roll back, so the mark is lifted again when the save had none: the engine only reads. If the
 * difference cannot be written either, that goes to the log and the save goes on.
 */
final class ShadowRun
{
    private BaseConnection $db;
    private PostingEngine $engine;
    private ShadowComparison $comparison;

    public function __construct(?BaseConnection $db = null, ?PostingEngine $engine = null, ?ShadowComparison $comparison = null)
    {
        $this->db         = $db ?? Database::connect();
        $this->engine     = $engine ?? new PostingEngine($this->db);
        $this->comparison = $comparison ?? new ShadowComparison($this->db);
    }

    /**
     * @param callable(): PostingFacts $facts What the document gives the engine, asked for here so that its own failures are caught too.
     */
    public function check(string $operation, ?JournalDraft $legacy, callable $facts, string $sourceType, ?int $sourceId, int $revision): void
    {
        $clean   = $this->db->transStatus();
        $built   = null;
        $failure = null;
        $setId   = null;

        try {
            $given = $facts();
            $setId = $this->engine->rules()->current($operation, $given->date)->ruleSetId;
            $built = $this->engine->build($given);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $this->restore($clean);

        try {
            $this->comparison->compare($operation, $legacy, $built, $setId, $sourceType, $sourceId, $revision, $failure);
        } catch (Throwable $exception) {
            log_message('error', "The shadow check of {$operation} {$sourceType} {$sourceId} could not write its difference: " . $exception::class . ': ' . $exception->getMessage());
        }

        $this->restore($clean);
    }

    private function restore(bool $clean): void
    {
        if ($clean && ! $this->db->transStatus()) {
            $this->db->resetTransStatus();
        }
    }
}
