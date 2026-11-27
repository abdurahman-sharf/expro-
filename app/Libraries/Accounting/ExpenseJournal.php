<?php

namespace App\Libraries\Accounting;

use App\Libraries\Accounting\Engine\OperationJournal;
use App\Libraries\Accounting\Engine\PostingEngine;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * The journal of a saved expense, in the mode the expense is posted in. The live posting, the
 * retroactive posting and its previews all ask here, so they all follow the same mode.
 */
final class ExpenseJournal
{
    private ExpensePostingBuilder $builder;
    private OperationJournal $journal;

    public function __construct(?BaseConnection $db = null, ?ExpensePostingBuilder $builder = null, ?PostingEngine $engine = null)
    {
        $db ??= Database::connect();

        $this->builder = $builder ?? new ExpensePostingBuilder(new RoutingResolver($db));
        $this->journal = new OperationJournal(ExpensePostingBuilder::SOURCE, $db, $engine);
    }

    /**
     * @throws PostingException
     */
    public function draft(ExpenseDocument $expense, int $revision, ?int $employeeId = null): ?JournalDraft
    {
        return $this->journal->draft(
            fn (): ?JournalDraft => $this->builder->expense($expense, $revision, $employeeId),
            static fn () => ExpenseFacts::of($expense, $revision, $employeeId),
            ExpensePostingBuilder::SOURCE,
            $expense->expenseId,
            $revision,
        );
    }

    /**
     * Whether a journal paid from a treasury of another branch is split between the two branches.
     */
    public function crossesBranches(string $date): bool
    {
        return $this->journal->crossesBranches($date);
    }
}
