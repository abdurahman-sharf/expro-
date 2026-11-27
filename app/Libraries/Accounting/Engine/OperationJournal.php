<?php

namespace App\Libraries\Accounting\Engine;

use App\Libraries\Accounting\JournalDraft;
use App\Libraries\Accounting\PostingException;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The journal of a document of one operation, in the mode the operation is posted in: the journal the
 * code of the document builds, the journal of the engine, or the journal of the code with the journal
 * of the engine checked beside it. Every place that posts a document of the operation asks here, so
 * they all follow the same mode.
 */
final class OperationJournal
{
    private BaseConnection $db;
    private PostingEngine $engine;
    private PostingModes $modes;
    private ShadowRun $shadow;

    public function __construct(private readonly string $operation, ?BaseConnection $db = null, ?PostingEngine $engine = null)
    {
        $this->db     = $db ?? Database::connect();
        $this->engine = $engine ?? new PostingEngine($this->db);
        $this->modes  = new PostingModes($this->db);
        $this->shadow = new ShadowRun($this->db, $this->engine);
    }

    /**
     * @param callable(): ?JournalDraft $legacy What the code of the document builds.
     * @param callable(): PostingFacts  $facts  What the document gives the engine.
     *
     * @throws PostingException
     */
    public function draft(callable $legacy, callable $facts, string $sourceType, ?int $sourceId, int $revision): ?JournalDraft
    {
        $mode = $this->modes->of($this->operation);

        if ($mode === PostingMode::Engine) {
            return $this->engine->build($facts());
        }

        $draft = $legacy();

        if ($mode === PostingMode::Shadow) {
            $this->shadow->check($this->operation, $draft, $facts, $sourceType, $sourceId, $revision);
        }

        return $draft;
    }

    /**
     * Whether a journal paid from a treasury of another branch is split between the two branches: as the
     * code does it until the operation is posted by the engine, then as its rules say.
     */
    public function crossesBranches(string $date): bool
    {
        if ($this->modes->of($this->operation) !== PostingMode::Engine) {
            return true;
        }

        return $this->engine->rules()->current($this->operation, $date)->crossBranch;
    }
}
