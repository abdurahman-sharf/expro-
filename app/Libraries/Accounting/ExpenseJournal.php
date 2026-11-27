<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\PostingEngine;
use App\Libraries\Accounting\Engine\PostingMode;
use App\Libraries\Accounting\Engine\PostingModes;
use App\Libraries\Accounting\Engine\ShadowComparison;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The journal of a saved expense, in the mode the expense is posted in: built by the code of the
 * expense, by the posting engine, or by the code with the journal of the engine set beside it and
 * every difference written down. The live posting, the retroactive posting and its previews all ask
 * here, so they all follow the same mode.
 */
final class ExpenseJournal
{
    private BaseConnection $db;
    private ExpensePostingBuilder $builder;
    private PostingEngine $engine;
    private PostingModes $modes;

    public function __construct(?BaseConnection $db = null, ?ExpensePostingBuilder $builder = null, ?PostingEngine $engine = null)
    {
        $this->db      = $db ?? Database::connect();
        $this->builder = $builder ?? new ExpensePostingBuilder(new RoutingResolver($this->db));
        $this->engine  = $engine ?? new PostingEngine($this->db);
        $this->modes   = new PostingModes($this->db);
    }

    /**
     * @throws PostingException
     */
    public function draft(ExpenseDocument $expense, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        $mode = $this->modes->of(ExpensePostingBuilder::SOURCE);

        if ($mode === PostingMode::Engine) {
            return $this->engine->build(ExpenseFacts::of($expense, $revision, $employeeId));
        }

        $legacy = $this->builder->expense($expense, $revision, $employeeId);

        if ($mode === PostingMode::Shadow) {
            $this->shadow($expense, $revision, $employeeId, $legacy);
        }

        return $legacy;
    }

    /**
     * Whether a journal paid from a treasury of another branch is split between the two branches.
     */
    public function crossesBranches(string $date): bool
    {
        if ($this->modes->of(ExpensePostingBuilder::SOURCE) !== PostingMode::Engine) {
            return true;
        }

        return $this->engine->rules()->current(ExpensePostingBuilder::SOURCE, $date)->crossBranch;
    }

    private function shadow(ExpenseDocument $expense, int $revision, ?int $employeeId, ?JournalDraft $legacy): void
    {
        $engine  = null;
        $failure = null;
        $setId   = null;

        try {
            $setId  = $this->engine->rules()->current(ExpensePostingBuilder::SOURCE, $expense->date)->ruleSetId;
            $engine = $this->engine->build(ExpenseFacts::of($expense, $revision, $employeeId));
        } catch (PostingException $exception) {
            $failure = $exception;
        }

        (new ShadowComparison($this->db))->compare(ExpensePostingBuilder::SOURCE, $legacy, $engine, $setId, ExpensePostingBuilder::SOURCE, $expense->expenseId, $revision, $failure);
    }
}
